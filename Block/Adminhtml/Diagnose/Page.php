<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Block\Adminhtml\Diagnose;

use ETechFlow\PageSpeedOptimizer\Model\Config;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\FormKey;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Renders the Diagnose admin page.
 *
 * Note: $diagnoseFormKey (NOT $formKey) — parent Magento\Backend\Block\Template
 * already has a non-readonly $formKey. PHP 8.1+ rejects re-declaring it as
 * readonly. (Lesson learned from IO v1.2.0.)
 */
class Page extends Template
{
    private Config $config;
    private StoreManagerInterface $storeManager;
    private FormKey $diagnoseFormKey;

    public function __construct(
        Context $context,
        Config $config,
        StoreManagerInterface $storeManager,
        FormKey $diagnoseFormKey,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->diagnoseFormKey = $diagnoseFormKey;
    }

    public function getDefaultStrategy(): string
    {
        return $this->config->getPsiDefaultStrategy();
    }

    public function getDefaultUrl(): string
    {
        try {
            return rtrim($this->storeManager->getStore()->getBaseUrl(), '/');
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function getRunActionUrl(): string
    {
        return $this->getUrl('etechflow_pso/diagnose/run');
    }

    public function getFormKey(): string
    {
        return $this->diagnoseFormKey->getFormKey();
    }

    public function hasApiKey(): bool
    {
        return $this->config->getGooglePsiApiKey() !== '';
    }

    public function getConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'etechflow_pso']);
    }
}
