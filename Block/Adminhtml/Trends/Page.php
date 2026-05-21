<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Block\Adminhtml\Trends;

use ETechFlow\PageSpeedOptimizer\Model\ResourceModel\DiagnosticLog as DiagnosticLogResource;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/**
 * Renders the Trends admin page — score-over-time chart + recent-runs
 * table — from etechflow_pso_diagnostic_log.
 *
 * No JS lib dependency: we render the chart as inline SVG built in the
 * template. Magento admin doesn't reliably bundle Chart.js across all
 * versions; SVG works everywhere with zero footprint.
 *
 * Data shape returned by getChartData():
 *   [
 *     'series' => [
 *       'https://store.com/ (mobile)' => [
 *         ['x' => '2026-05-20 10:00:00', 'y' => 87],
 *         ['x' => '2026-05-21 10:00:00', 'y' => 92],
 *         ...
 *       ],
 *       'https://store.com/ (desktop)' => [ ... ]
 *     ],
 *     'min_score' => 0,
 *     'max_score' => 100,
 *     'min_date'  => '2026-05-01 ...',
 *     'max_date'  => '2026-05-21 ...',
 *   ]
 */
class Page extends Template
{
    private DiagnosticLogResource $logResource;

    /** @var array<string, mixed>|null lazy-loaded data */
    private ?array $chartData = null;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $recentRuns = null;

    public function __construct(
        Context $context,
        DiagnosticLogResource $logResource,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->logResource = $logResource;
    }

    /**
     * @return array<string, mixed>
     */
    public function getChartData(): array
    {
        if ($this->chartData === null) {
            $this->chartData = $this->buildChartData();
        }
        return $this->chartData;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentRuns(int $limit = 20): array
    {
        if ($this->recentRuns === null) {
            $conn = $this->logResource->getConnection();
            $select = $conn->select()
                ->from($this->logResource->getMainTable())
                ->order('run_at DESC')
                ->limit($limit);
            $this->recentRuns = $conn->fetchAll($select);
        }
        return $this->recentRuns;
    }

    /**
     * Renamed from hasData() — that signature collides with
     * Magento\Framework\DataObject::hasData($key = '').
     */
    public function hasAnyRuns(): bool
    {
        return !empty($this->getRecentRuns(1));
    }

    /**
     * Score bucket colour matches Google's bands: green ≥ 90, orange 50-89,
     * red < 50. Used in the recent-runs table.
     */
    public function scoreColour(?int $score): string
    {
        if ($score === null || $score < 0) {
            return '#777';
        }
        if ($score >= 90) {
            return '#1f7a1f';
        }
        if ($score >= 50) {
            return '#a06800';
        }
        return '#a02818';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildChartData(): array
    {
        $conn = $this->logResource->getConnection();
        $select = $conn->select()
            ->from($this->logResource->getMainTable(),
                ['url', 'strategy', 'performance_score', 'run_at'])
            ->where('status = ?', 'ok')
            ->where('performance_score IS NOT NULL')
            ->order('run_at ASC');

        $rows = $conn->fetchAll($select);
        if (empty($rows)) {
            return [
                'series' => [],
                'min_score' => 0,
                'max_score' => 100,
                'min_date' => null,
                'max_date' => null,
            ];
        }

        $series = [];
        $minDate = null;
        $maxDate = null;
        foreach ($rows as $row) {
            $key = $row['url'] . ' (' . $row['strategy'] . ')';
            if (!isset($series[$key])) {
                $series[$key] = [];
            }
            $series[$key][] = [
                'x' => $row['run_at'],
                'y' => (int) $row['performance_score'],
            ];
            if ($minDate === null || $row['run_at'] < $minDate) {
                $minDate = $row['run_at'];
            }
            if ($maxDate === null || $row['run_at'] > $maxDate) {
                $maxDate = $row['run_at'];
            }
        }

        return [
            'series'    => $series,
            'min_score' => 0,
            'max_score' => 100,
            'min_date'  => $minDate,
            'max_date'  => $maxDate,
        ];
    }
}
