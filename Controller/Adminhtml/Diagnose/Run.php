<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Controller\Adminhtml\Diagnose;

use ETechFlow\PageSpeedOptimizer\Model\Data\DiagnosticResult;
use ETechFlow\PageSpeedOptimizer\Model\Data\Recommendation;
use ETechFlow\PageSpeedOptimizer\Model\Psi\DiagnosticLogger;
use ETechFlow\PageSpeedOptimizer\Model\Psi\PsiClient;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * AJAX endpoint hit by the Diagnose page when admin clicks "Run".
 * Returns the same JSON envelope shape as IO v1.2.0 used.
 */
class Run extends Action
{
    public const ADMIN_RESOURCE = 'ETechFlow_PageSpeedOptimizer::diagnose';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly PsiClient $psiClient,
        private readonly DiagnosticLogger $diagnosticLogger
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $url = trim((string) $this->getRequest()->getParam('url', ''));
        $strategy = (string) $this->getRequest()->getParam('strategy', 'mobile');

        /** @var JsonResult $json */
        $json = $this->jsonFactory->create();

        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            $json->setHttpResponseCode(400);
            return $json->setData(['ok' => false, 'error' => 'A valid URL is required.']);
        }

        $result = $this->psiClient->diagnose($url, $strategy);
        $this->diagnosticLogger->log($result);

        if ($result->failed()) {
            $json->setHttpResponseCode(502);
            return $json->setData(['ok' => false, 'error' => $result->errorMessage]);
        }

        return $json->setData($this->serialize($result));
    }

    private function serialize(DiagnosticResult $r): array
    {
        return [
            'ok'            => true,
            'url'           => $r->url,
            'strategy'      => $r->strategy,
            'score'         => $r->performanceScore,
            'scoreCategory' => $r->scoreCategory(),
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
                    'description'   => $rec->description,
                    'impactSeconds' => $rec->impactSeconds,
                    'impactBucket'  => $rec->impactBucket(),
                    'etechflowFix'  => $rec->etechflowFix,
                ],
                $r->recommendations
            ),
        ];
    }
}
