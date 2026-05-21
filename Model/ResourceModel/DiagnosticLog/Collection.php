<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\ResourceModel\DiagnosticLog;

use ETechFlow\PageSpeedOptimizer\Model\DiagnosticLog;
use ETechFlow\PageSpeedOptimizer\Model\ResourceModel\DiagnosticLog as DiagnosticLogResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'log_id';

    protected function _construct(): void
    {
        $this->_init(DiagnosticLog::class, DiagnosticLogResource::class);
    }
}
