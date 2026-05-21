<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\ResourceModel\OptimizationLog;

use ETechFlow\PageSpeedOptimizer\Model\OptimizationLog;
use ETechFlow\PageSpeedOptimizer\Model\ResourceModel\OptimizationLog as OptimizationLogResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'log_id';

    protected function _construct(): void
    {
        $this->_init(OptimizationLog::class, OptimizationLogResource::class);
    }
}
