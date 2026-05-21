<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Block\Adminhtml\CronTasks;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ResourceConnection;

/**
 * Cron Tasks List admin block — reads cron_schedule table and surfaces
 * the last 50 jobs grouped by status. Matches Amasty Pro's "View and
 * manage all cron jobs" feature.
 *
 * Magento has a cron_schedule table that records every cron job execution
 * (cron:run populates it). Standard columns:
 *   - schedule_id, job_code, status (pending/running/success/missed/error),
 *   - messages, created_at, scheduled_at, executed_at, finished_at
 *
 * No 3rd-party UI Component grid — server-rendered table for instant load
 * (same approach as the Trends page).
 */
class Page extends Template
{
    private ResourceConnection $resourceConnection;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $recentJobs = null;

    /** @var array<string, int>|null */
    private ?array $statusCounts = null;

    public function __construct(
        Context $context,
        ResourceConnection $resourceConnection,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentJobs(int $limit = 50): array
    {
        if ($this->recentJobs === null) {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('cron_schedule');
            $select = $connection->select()
                ->from($table)
                ->order('schedule_id DESC')
                ->limit($limit);
            $this->recentJobs = $connection->fetchAll($select);
        }
        return $this->recentJobs;
    }

    /**
     * Aggregate counts per status across the last 24h.
     * @return array<string, int>
     */
    public function getStatusCounts(): array
    {
        if ($this->statusCounts === null) {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('cron_schedule');
            $cutoff = date('Y-m-d H:i:s', strtotime('-24 hours'));
            $select = $connection->select()
                ->from($table, ['status', new \Zend_Db_Expr('COUNT(*) AS cnt')])
                ->where('created_at >= ?', $cutoff)
                ->group('status');
            $rows = $connection->fetchAll($select);
            $counts = ['pending' => 0, 'running' => 0, 'success' => 0, 'missed' => 0, 'error' => 0];
            foreach ($rows as $row) {
                $key = strtolower((string) $row['status']);
                if (isset($counts[$key])) {
                    $counts[$key] = (int) $row['cnt'];
                }
            }
            $this->statusCounts = $counts;
        }
        return $this->statusCounts;
    }

    /**
     * Calculate execution duration in seconds where both timestamps exist.
     */
    public function getDurationSeconds(?string $executedAt, ?string $finishedAt): ?int
    {
        if (!$executedAt || !$finishedAt || $executedAt === 'null' || $finishedAt === 'null') {
            return null;
        }
        $start = strtotime($executedAt);
        $end   = strtotime($finishedAt);
        if ($start === false || $end === false) {
            return null;
        }
        return max(0, $end - $start);
    }

    public function statusColour(string $status): string
    {
        $map = [
            'success' => '#1f7a1f',
            'running' => '#1f4fa0',
            'pending' => '#777',
            'missed'  => '#a06800',
            'error'   => '#a02818',
        ];
        return $map[strtolower($status)] ?? '#444';
    }
}
