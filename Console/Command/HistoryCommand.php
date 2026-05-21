<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Console\Command;

use ETechFlow\PageSpeedOptimizer\Model\ResourceModel\DiagnosticLog as DiagnosticLogResource;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento etechflow:pso:history --url=... --limit=20 --json`
 *
 * Tabular dump of recent diagnostic runs. Useful for:
 *   - CI pipelines that want to see "did the score regress?" trending
 *   - Agency dashboards aggregating multiple stores
 *   - Quick "what's the current state?" check without opening admin
 */
class HistoryCommand extends Command
{
    private const OPT_URL   = 'url';
    private const OPT_LIMIT = 'limit';
    private const OPT_JSON  = 'json';

    public function __construct(
        private readonly AppState $appState,
        private readonly DiagnosticLogResource $logResource
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('etechflow:pso:history')
            ->setDescription('Show recent PageSpeed Insights diagnostic runs from the log table.')
            ->addOption(self::OPT_URL, null, InputOption::VALUE_REQUIRED,
                'Filter to runs for this URL only.')
            ->addOption(self::OPT_LIMIT, null, InputOption::VALUE_REQUIRED,
                'Number of rows to show (default 20).', '20')
            ->addOption(self::OPT_JSON, null, InputOption::VALUE_NONE,
                'Output JSON instead of a human-readable table.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_GLOBAL);
        } catch (\Exception $e) {
            // already set
        }

        $url = (string) $input->getOption(self::OPT_URL);
        $limit = max(1, (int) $input->getOption(self::OPT_LIMIT));
        $jsonOnly = (bool) $input->getOption(self::OPT_JSON);

        $conn = $this->logResource->getConnection();
        $select = $conn->select()
            ->from($this->logResource->getMainTable(),
                ['log_id', 'run_at', 'url', 'strategy', 'performance_score',
                 'lab_lcp_seconds', 'lab_tbt_ms', 'lab_cls',
                 'field_lcp_ms', 'field_inp_ms', 'status', 'error_message'])
            ->order('run_at DESC')
            ->limit($limit);
        if ($url !== '') {
            $select->where('url = ?', $url);
        }
        $rows = $conn->fetchAll($select);

        if ($jsonOnly) {
            $output->writeln((string) json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        if (empty($rows)) {
            $output->writeln('<comment>No diagnostic runs found.</comment>');
            $output->writeln('Run: <info>bin/magento etechflow:pso:diagnose --url=https://your-store.com/</info>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Run at', 'URL', 'Strat', 'Score', 'LCP', 'TBT', 'CLS', 'Status']);
        foreach ($rows as $row) {
            $score = $row['performance_score'] !== null ? (int) $row['performance_score'] : '—';
            $lcp = $row['lab_lcp_seconds'] !== null ? round((float) $row['lab_lcp_seconds'], 2) . 's' : '—';
            $tbt = $row['lab_tbt_ms'] !== null ? (int) $row['lab_tbt_ms'] . 'ms' : '—';
            $cls = $row['lab_cls'] !== null ? round((float) $row['lab_cls'], 3) : '—';
            $url = strlen($row['url']) > 36 ? substr($row['url'], 0, 34) . '…' : $row['url'];

            $table->addRow([
                $row['log_id'],
                $row['run_at'],
                $url,
                $row['strategy'],
                $score,
                $lcp,
                $tbt,
                $cls,
                $row['status'],
            ]);
        }
        $table->render();
        return Command::SUCCESS;
    }
}
