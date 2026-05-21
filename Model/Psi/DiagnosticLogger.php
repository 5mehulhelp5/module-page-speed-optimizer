<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Psi;

use ETechFlow\PageSpeedOptimizer\Model\Data\DiagnosticResult;
use ETechFlow\PageSpeedOptimizer\Model\DiagnosticLog;
use ETechFlow\PageSpeedOptimizer\Model\ResourceModel\DiagnosticLog as DiagnosticLogResource;
use Psr\Log\LoggerInterface;

/**
 * Persists DiagnosticResult to etechflow_pso_diagnostic_log. Best-effort
 * — failure to write never breaks the caller (the result is already in
 * hand; logging is for future trend graphs).
 */
class DiagnosticLogger
{
    public function __construct(
        private readonly DiagnosticLogResource $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    public function log(DiagnosticResult $result): void
    {
        try {
            $this->resource->getConnection()->insert(
                $this->resource->getMainTable(),
                [
                    'url'               => $result->url,
                    'strategy'          => $result->strategy,
                    'performance_score' => $result->performanceScore >= 0 ? $result->performanceScore : null,
                    'lab_fcp_seconds'   => $result->labFcpSeconds,
                    'lab_lcp_seconds'   => $result->labLcpSeconds,
                    'lab_tbt_ms'        => $result->labTbtMillis,
                    'lab_cls'           => $result->labClsScore,
                    'field_lcp_ms'      => $result->fieldLcpMillis,
                    'field_inp_ms'      => $result->fieldInpMillis,
                    'field_cls'         => $result->fieldClsScore,
                    'field_category'    => $result->fieldOverallCategory,
                    'status'            => $result->failed() ? DiagnosticLog::STATUS_FAILED : DiagnosticLog::STATUS_OK,
                    'error_message'     => $result->errorMessage,
                    'raw_json'          => $result->rawJson,
                    'run_at'            => date('Y-m-d H:i:s'),
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ETechFlow_PSO failed to log a PSI diagnostic',
                ['url' => $result->url, 'exception' => $e->getMessage()]
            );
        }
    }
}
