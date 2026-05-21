<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model;

use ETechFlow\PageSpeedOptimizer\Model\ResourceModel\OptimizationLog as OptimizationLogResource;
use Magento\Framework\Model\AbstractModel;

/**
 * Lightweight entity for the conversion-audit table. Not exposed via
 * service contract — internal implementation detail consumed only by
 * WebpGenerator + the admin grid.
 */
class OptimizationLog extends AbstractModel
{
    public const STATUS_OK      = 'ok';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected function _construct(): void
    {
        $this->_init(OptimizationLogResource::class);
    }

    public function getLogId(): ?int { $v = $this->getData('log_id'); return $v === null ? null : (int) $v; }
    public function setLogId(?int $id): self { return $this->setData('log_id', $id); }

    public function getSourcePath(): string { return (string) $this->getData('source_path'); }
    public function setSourcePath(string $p): self { return $this->setData('source_path', $p); }

    public function getOutputPath(): string { return (string) $this->getData('output_path'); }
    public function setOutputPath(string $p): self { return $this->setData('output_path', $p); }

    public function getFormatFrom(): string { return (string) $this->getData('format_from'); }
    public function setFormatFrom(string $f): self { return $this->setData('format_from', $f); }

    public function getFormatTo(): string { return (string) ($this->getData('format_to') ?: 'webp'); }
    public function setFormatTo(string $f): self { return $this->setData('format_to', $f); }

    public function getBytesBefore(): int { return (int) $this->getData('bytes_before'); }
    public function setBytesBefore(int $b): self { return $this->setData('bytes_before', $b); }

    public function getBytesAfter(): int { return (int) $this->getData('bytes_after'); }
    public function setBytesAfter(int $b): self { return $this->setData('bytes_after', $b); }

    public function getSavingsPct(): int { return (int) $this->getData('savings_pct'); }
    public function setSavingsPct(int $p): self { return $this->setData('savings_pct', $p); }

    public function getEngine(): string { return (string) $this->getData('engine'); }
    public function setEngine(string $e): self { return $this->setData('engine', $e); }

    public function getSourceMtime(): ?int { $v = $this->getData('source_mtime'); return $v === null ? null : (int) $v; }
    public function setSourceMtime(?int $t): self { return $this->setData('source_mtime', $t); }

    public function getStatus(): string { return (string) ($this->getData('status') ?: self::STATUS_OK); }
    public function setStatus(string $s): self { return $this->setData('status', $s); }

    public function getErrorMessage(): ?string { $v = $this->getData('error_message'); return $v === null ? null : (string) $v; }
    public function setErrorMessage(?string $m): self { return $this->setData('error_message', $m); }

    public function getOptimizedAt(): ?string { $v = $this->getData('optimized_at'); return $v === null ? null : (string) $v; }
    public function setOptimizedAt(?string $t): self { return $this->setData('optimized_at', $t); }
}
