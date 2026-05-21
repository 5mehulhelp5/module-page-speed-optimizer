<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Centralised reader for the module's admin config + license-aware gate.
 *
 * isEnabled() returns false when EITHER the admin "Module Enabled" toggle
 * is No OR the license isn't valid. Calling code just checks isEnabled().
 */
class Config
{
    public const XML_PATH_ENABLED          = 'etechflow_pso/general/enabled';

    public const XML_PATH_PSI_API_KEY      = 'etechflow_pso/psi/api_key';
    public const XML_PATH_PSI_STRATEGY     = 'etechflow_pso/psi/default_strategy';
    public const XML_PATH_PSI_TIMEOUT      = 'etechflow_pso/psi/timeout_seconds';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LicenseValidator $licenseValidator
    ) {
    }

    public function isEnabled(): bool
    {
        if (!$this->licenseValidator->isValid()) {
            return false;
        }
        return $this->isAdminEnabled();
    }

    public function isAdminEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Encrypted in DB via Magento\Config\Model\Config\Backend\Encrypted.
     * Plaintext returned here.
     */
    public function getGooglePsiApiKey(): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_PSI_API_KEY, ScopeInterface::SCOPE_STORE);
        return trim((string) $value);
    }

    /** 'mobile' | 'desktop'. Mobile is Google's mobile-first default. */
    public function getPsiDefaultStrategy(): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_PSI_STRATEGY, ScopeInterface::SCOPE_STORE);
        return in_array($value, ['mobile', 'desktop'], true) ? $value : 'mobile';
    }

    public function getPsiTimeoutSeconds(): int
    {
        $value = (int) $this->scopeConfig->getValue(self::XML_PATH_PSI_TIMEOUT, ScopeInterface::SCOPE_STORE);
        return $value > 0 ? $value : 90;
    }
}
