<?php

declare(strict_types=1);

namespace Asbs\ShopwareStreamDeck\Tests\Service;

use Asbs\ShopwareStreamDeck\Service\RevenueExclusionConfig;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class RevenueExclusionConfigTest extends TestCase
{
    /**
     * @param array<string,mixed> $values
     */
    private function config(array $values): RevenueExclusionConfig
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key) => $values[$key] ?? null,
        );

        return new RevenueExclusionConfig($systemConfig);
    }

    public function testInactiveWhenNothingConfigured(): void
    {
        $config = $this->config([]);

        self::assertFalse($config->isActive());
        self::assertSame([], $config->productIds());
        self::assertSame([], $config->labels());
    }

    public function testProductIdsReturnsConfiguredUuidsAndDropsNonStrings(): void
    {
        $config = $this->config([
            RevenueExclusionConfig::CONFIG_PRODUCT_IDS => ['uuid-a', '', 'uuid-b', null, 42],
        ]);

        self::assertSame(['uuid-a', 'uuid-b'], $config->productIds());
        self::assertTrue($config->isActive());
    }

    public function testLabelsSplitTrimAndDropEmptyLines(): void
    {
        $config = $this->config([
            RevenueExclusionConfig::CONFIG_LABELS => "  Vorläufige Reservierung  \r\n\nPfand-Position\n   \n",
        ]);

        self::assertSame(['Vorläufige Reservierung', 'Pfand-Position'], $config->labels());
        self::assertTrue($config->isActive());
    }

    public function testActiveWhenOnlyLabelsConfigured(): void
    {
        $config = $this->config([
            RevenueExclusionConfig::CONFIG_LABELS => 'Gebühr',
        ]);

        self::assertTrue($config->isActive());
        self::assertSame([], $config->productIds());
        self::assertSame(['Gebühr'], $config->labels());
    }
}
