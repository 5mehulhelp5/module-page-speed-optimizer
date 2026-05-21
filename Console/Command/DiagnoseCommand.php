<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Console\Command;

use ETechFlow\PageSpeedOptimizer\Model\Config;
use ETechFlow\PageSpeedOptimizer\Model\Data\DiagnosticResult;
use ETechFlow\PageSpeedOptimizer\Model\Data\Recommendation;
use ETechFlow\PageSpeedOptimizer\Model\Psi\DiagnosticLogger;
use ETechFlow\PageSpeedOptimizer\Model\Psi\PsiClient;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento etechflow:pso:diagnose --url=... --strategy=mobile|desktop --json --pass-score=80`
 *
 * Three exit codes:
 *   0 — score ≥ pass-score (default 80)
 *   1 — score < pass-score (CI failure)
 *   2 — API call itself failed
 */
class DiagnoseCommand extends Command
{
    private const OPT_URL        = 'url';
    private const OPT_STRATEGY   = 'strategy';
    private const OPT_JSON       = 'json';
    private const OPT_PASS_SCORE = 'pass-score';

    public function __construct(
        private readonly AppState $appState,
        private readonly Config $config,
        private readonly PsiClient $psiClient,
        private readonly DiagnosticLogger $diagnosticLogger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('etechflow:pso:diagnose')
            ->setDescription('Run Google PageSpeed Insights against a URL and print/return the score + recommendations.')
            ->addOption(self::OPT_URL, null, InputOption::VALUE_REQUIRED, 'URL to analyse.')
            ->addOption(self::OPT_STRATEGY, null, InputOption::VALUE_REQUIRED, 'mobile | desktop (defaults to admin config).')
            ->addOption(self::OPT_JSON, null, InputOption::VALUE_NONE, 'Output machine-readable JSON.')
            ->addOption(self::OPT_PASS_SCORE, null, InputOption::VALUE_REQUIRED,
                'Exit 1 if score < this value (default 80). Useful for CI gates.', '80');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_GLOBAL);
        } catch (\Exception $e) {
            // already set — ignore
        }

        $url = (string) $input->getOption(self::OPT_URL);
        if ($url === '') {
            $output->writeln('<error>--url is required.</error>');
            return 2;
        }
        $strategy = (string) $input->getOption(self::OPT_STRATEGY) ?: $this->config->getPsiDefaultStrategy();
        $passScore = (int) $input->getOption(self::OPT_PASS_SCORE);
        $jsonOnly = (bool) $input->getOption(self::OPT_JSON);

        $result = $this->psiClient->diagnose($url, $strategy);
        $this->diagnosticLogger->log($result);

        if ($result->failed()) {
            if ($jsonOnly) {
                $output->writeln((string) json_encode([
                    'ok' => false, 'error' => $result->errorMessage, 'url' => $url,
                ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $output->writeln('<error>PSI call failed: ' . $result->errorMessage . '</error>');
            }
            return 2;
        }

        if ($jsonOnly) {
            $output->writeln((string) json_encode($this->serialize($result), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $this->printHuman($output, $result);
        }

        return $result->performanceScore >= $passScore ? 0 : 1;
    }

    private function serialize(DiagnosticResult $r): array
    {
        return [
            'ok'       => true,
            'url'      => $r->url,
            'strategy' => $r->strategy,
            'score'    => $r->performanceScore,
            'category' => $r->scoreCategory(),
            'lab' => [
                'fcp_s'  => $r->labFcpSeconds,
                'lcp_s'  => $r->labLcpSeconds,
                'tbt_ms' => $r->labTbtMillis,
                'cls'    => $r->labClsScore,
            ],
            'field' => $r->hasFieldData() ? [
                'lcp_ms'   => $r->fieldLcpMillis,
                'inp_ms'   => $r->fieldInpMillis,
                'cls'      => $r->fieldClsScore,
                'category' => $r->fieldOverallCategory,
            ] : null,
            'recommendations' => array_map(
                fn(Recommendation $rec) => [
                    'auditId'       => $rec->auditId,
                    'title'         => $rec->title,
                    'impactSeconds' => $rec->impactSeconds,
                    'impactBucket'  => $rec->impactBucket(),
                    'etechflowFix'  => $rec->etechflowFix,
                ],
                $r->recommendations
            ),
        ];
    }

    private function printHuman(OutputInterface $output, DiagnosticResult $r): void
    {
        $output->writeln('');
        $output->writeln(sprintf('=== PageSpeed Insights — %s (%s) ===', $r->url, $r->strategy));
        $output->writeln('');
        $colour = $r->scoreCategory() === 'good' ? 'info'
                : ($r->scoreCategory() === 'needs-improvement' ? 'comment' : 'error');
        $output->writeln(sprintf('  Performance: <%s>%d / 100</%s>', $colour, $r->performanceScore, $colour));
        $output->writeln('');
        $output->writeln(sprintf('  Lab data: FCP=%s  LCP=%s  TBT=%s  CLS=%s',
            $r->labFcpSeconds !== null ? round($r->labFcpSeconds, 2) . 's' : '—',
            $r->labLcpSeconds !== null ? round($r->labLcpSeconds, 2) . 's' : '—',
            $r->labTbtMillis !== null ? round($r->labTbtMillis) . 'ms' : '—',
            $r->labClsScore !== null ? round($r->labClsScore, 3) : '—'
        ));
        if ($r->hasFieldData()) {
            $output->writeln(sprintf('  Field data: LCP=%s  INP=%s  CLS=%s  Overall=%s',
                $r->fieldLcpMillis !== null ? round($r->fieldLcpMillis) . 'ms' : '—',
                $r->fieldInpMillis !== null ? round($r->fieldInpMillis) . 'ms' : '—',
                $r->fieldClsScore !== null ? round($r->fieldClsScore, 3) : '—',
                $r->fieldOverallCategory ?? '—'
            ));
        }
        $output->writeln('');
        if ($r->recommendations) {
            $output->writeln('  <info>Recommendations:</info>');
            foreach ($r->recommendations as $rec) {
                $bucket = strtoupper($rec->impactBucket());
                $saving = $rec->impactSeconds > 0 ? sprintf('  (~%s saved)', round($rec->impactSeconds, 2) . 's') : '';
                $output->writeln(sprintf('    [%s] %s%s', $bucket, $rec->title, $saving));
                if ($rec->etechflowFix !== null) {
                    $output->writeln(sprintf('         → ETechFlow fix: %s', $rec->etechflowFix));
                }
            }
        } else {
            $output->writeln('  <info>No actionable recommendations — you\'re performing well!</info>');
        }
        $output->writeln('');
    }
}
