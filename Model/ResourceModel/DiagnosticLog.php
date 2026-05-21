<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class DiagnosticLog extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('etechflow_pso_diagnostic_log', 'log_id');
    }
}
