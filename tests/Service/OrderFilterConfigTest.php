<?php

declare(strict_types=1);

namespace Asbs\ShopwareStreamDeck\Tests\Service;

use Asbs\ShopwareStreamDeck\Service\OrderFilterConfig;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class OrderFilterConfigTest extends TestCase
{
    public function testReturnsEmptyArraysWhenBothConfigsEmpty(): void
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturn(null);
        $repo = $this->createMock(EntityRepository::class);
        $repo->expects(self::never())->method('searchIds');

        $filter = new OrderFilterConfig($systemConfig, $repo);

        self::assertSame(['order' => [], 'payment' => []], $filter->resolve(Context::createDefaultContext()));
    }

    public function testResolvesOnlyOrderStatesWhenPaymentConfigEmpty(): void
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnMap([
            [OrderFilterConfig::CONFIG_ORDER_STATES, null, ['open', 'completed']],
            [OrderFilterConfig::CONFIG_PAYMENT_STATES, null, []],
        ]);
        $repo = $this->createMock(EntityRepository::class);
        $repo
            ->expects(self::once())
            ->method('searchIds')
            ->with(self::callback(static function (Criteria $c): bool {
                $filters = $c->getFilters();
                self::assertCount(2, $filters);
                self::assertInstanceOf(EqualsFilter::class, $filters[0]);
                self::assertSame('stateMachine.technicalName', $filters[0]->getField());
                self::assertSame('order.state', $filters[0]->getValue());
                self::assertInstanceOf(EqualsAnyFilter::class, $filters[1]);
                self::assertSame('technicalName', $filters[1]->getField());
                self::assertSame(['open', 'completed'], $filters[1]->getValue());

                return true;
            }))
            ->willReturn(new IdSearchResult(2, [
                ['primaryKey' => 'uuid-open', 'data' => []],
                ['primaryKey' => 'uuid-completed', 'data' => []],
            ], new Criteria(), Context::createDefaultContext()));

        $filter = new OrderFilterConfig($systemConfig, $repo);

        $result = $filter->resolve(Context::createDefaultContext());
        self::assertSame(['uuid-open', 'uuid-completed'], $result['order']);
        self::assertSame([], $result['payment']);
    }

    public function testCachesResolvedIdsAcrossCalls(): void
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturn(['paid']);
        $repo = $this->createMock(EntityRepository::class);
        $repo
            ->expects(self::exactly(2)) // once for order, once for payment
            ->method('searchIds')
            ->willReturn(new IdSearchResult(1, [['primaryKey' => 'uuid-x', 'data' => []]], new Criteria(), Context::createDefaultContext()));

        $filter = new OrderFilterConfig($systemConfig, $repo);

        $a = $filter->resolve(Context::createDefaultContext());
        $b = $filter->resolve(Context::createDefaultContext());

        self::assertSame($a, $b);
    }

    public function testIgnoresNonStringEntriesInConfig(): void
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnMap([
            [OrderFilterConfig::CONFIG_ORDER_STATES, null, ['open', '', null, 42, 'completed']],
            [OrderFilterConfig::CONFIG_PAYMENT_STATES, null, []],
        ]);
        $repo = $this->createMock(EntityRepository::class);
        $repo
            ->method('searchIds')
            ->with(self::callback(static function (Criteria $c): bool {
                $filters = $c->getFilters();
                self::assertInstanceOf(EqualsAnyFilter::class, $filters[1]);
                self::assertSame(['open', 'completed'], $filters[1]->getValue());

                return true;
            }))
            ->willReturn(new IdSearchResult(0, [], new Criteria(), Context::createDefaultContext()));

        (new OrderFilterConfig($systemConfig, $repo))->resolve(Context::createDefaultContext());
    }
}
