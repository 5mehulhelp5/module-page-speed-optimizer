<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Block\Adminhtml\OptimizationLog;

use ETechFlow\PageSpeedOptimizer\Model\OptimizationLog;
use ETechFlow\PageSpeedOptimizer\Model\ResourceModel\OptimizationLog as LogResource;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/**
 * Top-of-page savings banner. One SELECT, cheap. Returns:
 *   - count of OK rows
 *   - total bytes before
 *   - total bytes after
 *   - average savings %
 *
 * Used by the template at view/adminhtml/templates/log/savings-summary.phtml.
 */
class SavingsSummary extends Template
{
    private ?array $summary = null;

    public function __construct(
        Context $context,
        private readonly LogResource $logResource,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array{count:int, bytes_before:int, bytes_after:int, avg_savings_pct:int}
     */
    public function getSummary(): array
    {
        if ($this->summary === null) {
            $conn = $this->logResource->getConnection();
            $row = $conn->fetchRow(
                $conn->select()
                    ->from($this->logResource->getMainTable(), [
                        'count' => 'COUNT(*)',
                        'bytes_before' => 'COALESCE(SUM(bytes_before), 0)',
                        'bytes_after'  => 'COALESCE(SUM(bytes_after), 0)',
                        'avg_savings'  => 'COALESCE(AVG(savings_pct), 0)',
                    ])
                    ->where('status = ?', OptimizationLog::STATUS_OK)
            );
            $this->summary = [
                'count'           => (int) ($row['count'] ?? 0),
                'bytes_before'    => (int) ($row['bytes_before'] ?? 0),
                'bytes_after'     => (int) ($row['bytes_after'] ?? 0),
                'avg_savings_pct' => (int) round((float) ($row['avg_savings'] ?? 0)),
            ];
        }
        return $this->summary;
    }

    /**
     * Format bytes as KB / MB / GB. Static-ish helper used by the template.
     */
    public function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 2) . ' MB';
        }
        return number_format($bytes / (1024 * 1024 * 1024), 2) . ' GB';
    }
}
