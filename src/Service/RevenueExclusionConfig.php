<?php

declare(strict_types=1);

namespace Asbs\ShopwareStreamDeck\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Reads the configured "non-revenue" line-item exclusions from the system config:
 * a list of product ids (from the admin product picker) and a list of free-text
 * labels (one per line). Both empty is the sentinel for "feature off" — the metric
 * services then keep their fast, exclusion-free path.
 *
 * Order- and payment-state scoping lives in [[OrderFilterConfig]]; this class only
 * answers "which line items don't count as revenue".
 */
class RevenueExclusionConfig
{
    public const CONFIG_PRODUCT_IDS = 'ASBusStreamDeckDashboard.config.revenueExclusionProductIds';
    public const CONFIG_LABELS = 'ASBusStreamDeckDashboard.config.revenueExclusionLabels';

    public function __construct(
        private readonly SystemConfigService $systemConfig,
    ) {
    }

    /**
     * @return string[] product UUIDs configured via the product picker
     */
    public function productIds(): array
    {
        $raw = $this->systemConfig->get(self::CONFIG_PRODUCT_IDS);
        if (!\is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, static fn ($v): bool => \is_string($v) && $v !== ''));
    }

    /**
     * @return string[] trimmed, non-empty labels (textarea, one per line)
     */
    public function labels(): array
    {
        $raw = $this->systemConfig->get(self::CONFIG_LABELS);
        if (!\is_string($raw) || $raw === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $l): string => trim($l), $lines),
            static fn (string $l): bool => $l !== '',
        ));
    }

    public function isActive(): bool
    {
        return $this->productIds() !== [] || $this->labels() !== [];
    }
}
