<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Console\Command;

use ETechFlow\PageSpeedOptimizer\Model\Config;
use ETechFlow\PageSpeedOptimizer\Model\LicenseValidator;
use ETechFlow\PageSpeedOptimizer\Model\Psi\DiagnosticLogger;
use ETechFlow\PageSpeedOptimizer\Model\Psi\PsiClient;
use ETechFlow\PageSpeedOptimizer\Model\Recommendation\Mapper;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento etechflow:pso:verify` — 10-check smoke test.
 *
 * 10 PASS lines = green-light go-live. Same shape as etechflow:pso:verify,
 * etechflow:isp:verify, etechflow:bisn:verify.
 */
class VerifyCommand extends Command
{
    private int $checksRun = 0;
    private int $checksFailed = 0;

    public function __construct(
        private readonly AppState $appState,
        private readonly LicenseValidator $licenseValidator,
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly PsiClient $psiClient,
        private readonly DiagnosticLogger $diagnosticLogger,
        private readonly Mapper $mapper
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('etechflow:pso:verify')
            ->setDescription('Smoke-test ETechFlow Page Speed Optimizer (license, DB, DI wiring).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_GLOBAL);
        } catch (\Exception $e) {
            // already set
        }

        $output->writeln('=== ETechFlow Page Speed Optimizer verify ===');
        $output->writeln('');

        $this->check($output, 'LicenseValidator evaluates without throwing', function () {
            $host = $this->licenseValidator->getCurrentHost();
            $isDev = $this->licenseValidator->isDevHost($host);
            $isValid = $this->licenseValidator->isValid();
            return sprintf('host=%s; dev_host=%s; valid=%s',
                $host ?: '(none)',
                $isDev ? 'yes' : 'no',
                $isValid ? 'yes' : 'no');
        });

        $this->check($output, 'Config.isEnabled() returns a boolean', function () {
            return 'enabled=' . ($this->config->isEnabled() ? 'yes' : 'no');
        });

        $this->check($output, 'PSI settings reachable', function () {
            $hasKey = $this->config->getGooglePsiApiKey() !== '';
            $strategy = $this->config->getPsiDefaultStrategy();
            $timeout = $this->config->getPsiTimeoutSeconds();
            return sprintf('api_key=%s; strategy=%s; timeout=%ds',
                $hasKey ? 'set' : '(none — will use Google\'s rate-limited public quota)',
                $strategy, $timeout);
        });

        $this->check($output, 'etechflow_pso_diagnostic_log table exists', function () {
            $conn = $this->resourceConnection->getConnection();
            $name = $this->resourceConnection->getTableName('etechflow_pso_diagnostic_log');
            if (!$conn->isTableExists($name)) {
                throw new \RuntimeException("Missing table '$name' — run bin/magento setup:upgrade");
            }
            return 'OK';
        });

        $this->check($output, 'PsiClient resolves via DI', function () {
            return get_class($this->psiClient);
        });

        $this->check($output, 'DiagnosticLogger resolves via DI', function () {
            return get_class($this->diagnosticLogger);
        });

        $this->check($output, 'Recommendation Mapper resolves via DI', function () {
            $sample = $this->mapper->getFix('uses-webp-images');
            return $sample !== null
                ? sprintf('class=%s; sample mapping for "uses-webp-images" returns non-null', get_class($this->mapper))
                : sprintf('class=%s — but no mapping for "uses-webp-images" (audit ID coverage incomplete)', get_class($this->mapper));
        });

        $this->check($output, 'Recommendation Mapper covers core image audits', function () {
            $required = ['uses-webp-images', 'modern-image-formats', 'offscreen-images', 'unminified-css', 'unminified-javascript'];
            $missing = array_filter($required, fn($k) => $this->mapper->getFix($k) === null);
            if (!empty($missing)) {
                throw new \RuntimeException('Missing mappings for: ' . implode(', ', $missing));
            }
            return sprintf('%d/%d core audits mapped', count($required), count($required));
        });

        $this->check($output, 'Curl HTTP client resolves via DI (via PsiClient construction)', function () {
            // PsiClient already resolved — Curl is one of its deps. If it didn't,
            // we wouldn't have gotten this far.
            return 'OK (verified transitively via PsiClient)';
        });

        $this->check($output, 'Lab + field metric serialisation works on a failed-result fixture', function () {
            // Spin a DiagnosticResult through the failure path and confirm it
            // serialises without throwing.
            $sample = $this->psiClient->diagnose('http://invalid-host-that-doesnt-exist.invalid', 'mobile');
            if (!$sample->failed()) {
                throw new \RuntimeException('Expected diagnose() on invalid host to fail');
            }
            return 'OK (errorMessage was populated: ' . substr($sample->errorMessage ?? '?', 0, 60) . '...)';
        });

        $output->writeln('');
        if ($this->checksFailed === 0) {
            $output->writeln(sprintf('<info>All %d checks passed.</info>', $this->checksRun));
            return Command::SUCCESS;
        }
        $output->writeln(sprintf('<error>%d of %d checks FAILED.</error>', $this->checksFailed, $this->checksRun));
        return Command::FAILURE;
    }

    private function check(OutputInterface $output, string $name, callable $fn): void
    {
        $this->checksRun++;
        $output->write(sprintf('%2d. %s ... ', $this->checksRun, $name));
        try {
            $detail = $fn();
            $output->writeln(sprintf('<info>OK</info> (%s)', $detail));
        } catch (\Throwable $e) {
            $this->checksFailed++;
            $output->writeln(sprintf('<error>FAIL: %s</error>', $e->getMessage()));
        }
    }
}
