<?php

declare(strict_types=1);

namespace Asbs\ShopwareStreamDeck\Tests\Service;

use Asbs\ShopwareStreamDeck\Service\OrderFilterConfig;
use Asbs\ShopwareStreamDeck\Service\RevenueExclusionConfig;
use Asbs\ShopwareStreamDeck\Service\RevenueExclusionResolver;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;

final class RevenueExclusionResolverTest extends TestCase
{
    private int $idCounter = 0;

    /**
     * @param array<string,mixed> $configValues isActive/productIds/labels via mock
     */
    private function resolver(
        RevenueExclusionConfig $config,
        OrderFilterConfig $filter,
        EntityRepository $lineItemRepo,
    ): RevenueExclusionResolver {
        return new RevenueExclusionResolver($config, $filter, $lineItemRepo);
    }

    private function config(bool $active, array $productIds = [], array $labels = []): RevenueExclusionConfig
    {
        $config = $this->createMock(RevenueExclusionConfig::class);
        $config->method('isActive')->willReturn($active);
        $config->method('productIds')->willReturn($productIds);
        $config->method('labels')->willReturn($labels);

        return $config;
    }

    private function filter(array $order = [], array $payment = []): OrderFilterConfig
    {
        $filter = $this->createMock(OrderFilterConfig::class);
        $filter->method('resolve')->willReturn(['order' => $order, 'payment' => $payment]);

        return $filter;
    }

    private function line(string $label, ?string $type, ?string $referencedId, float $total, float $tax): OrderLineItemEntity
    {
        $taxes = new CalculatedTaxCollection($tax > 0.0 ? [new CalculatedTax($tax, 7.0, $total)] : []);
        $li = new OrderLineItemEntity();
        $li->setUniqueIdentifier('li-'.(++$this->idCounter));
        $li->setLabel($label);
        $li->setType($type);
        $li->setReferencedId($referencedId);
        $li->setPrice(new CalculatedPrice($total, $total, $taxes, new TaxRuleCollection()));

        return $li;
    }

    /**
     * @param OrderLineItemEntity[] $lines
     */
    private function order(string $taxStatus, array $lines, ?\DateTimeImmutable $dt = null): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId('order-'.(++$this->idCounter));
        $order->setTaxStatus($taxStatus);
        $order->setOrderDateTime($dt ?? new \DateTimeImmutable('2026-06-02T10:00:00+00:00'));
        $order->setLineItems(new OrderLineItemCollection($lines));

        return $order;
    }

    private function repoReturning(OrderLineItemEntity ...$lines): EntityRepository
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('search')->willReturnCallback(
            static fn (Criteria $c, Context $ctx): EntitySearchResult => new EntitySearchResult(
                'order_line_item',
                \count($lines),
                new OrderLineItemCollection($lines),
                new AggregationResultCollection([]),
                $c,
                $ctx,
            ),
        );

        return $repo;
    }

    public function testForOrderGrossSubtractsTaxForNet(): void
    {
        $resolver = $this->resolver(
            $this->config(true, [], ['Zuschlag']),
            $this->filter(),
            $this->createMock(EntityRepository::class),
        );
        $order = $this->order(CartPrice::TAX_STATE_GROSS, [
            $this->line('Zuschlag', 'product', null, 100.0, 7.0),
            $this->line('Echtes Produkt', 'product', null, 50.0, 3.5),
        ]);

        self::assertSame(['net' => 93.0, 'gross' => 100.0], $resolver->forOrder($order));
    }

    public function testForOrderNetAddsTaxForGross(): void
    {
        $resolver = $this->resolver(
            $this->config(true, [], ['Zuschlag']),
            $this->filter(),
            $this->createMock(EntityRepository::class),
        );
        $order = $this->order(CartPrice::TAX_STATE_NET, [
            $this->line('Zuschlag', 'custom', null, 100.0, 7.0),
        ]);

        self::assertSame(['net' => 100.0, 'gross' => 107.0], $resolver->forOrder($order));
    }

    public function testForOrderTaxFree(): void
    {
        $resolver = $this->resolver(
            $this->config(true, [], ['Zuschlag']),
            $this->filter(),
            $this->createMock(EntityRepository::class),
        );
        $order = $this->order(CartPrice::TAX_STATE_FREE, [
            $this->line('Zuschlag', 'custom', null, 50.0, 0.0),
        ]);

        self::assertSame(['net' => 50.0, 'gross' => 50.0], $resolver->forOrder($order));
    }

    public function testForOrderMatchesByProductIdAndIgnoresOthers(): void
    {
        $resolver = $this->resolver(
            $this->config(true, ['prod-uuid'], []),
            $this->filter(),
            $this->createMock(EntityRepository::class),
        );
        $order = $this->order(CartPrice::TAX_STATE_GROSS, [
            $this->line('Reservierung', 'product', 'prod-uuid', 20.0, 0.0),
            $this->line('Reservierung', 'product', 'other-uuid', 20.0, 0.0),
            $this->line('Reservierung', 'custom', 'prod-uuid', 20.0, 0.0),
        ]);

        // Only the product line whose referencedId is the configured product matches.
        self::assertSame(['net' => 20.0, 'gross' => 20.0], $resolver->forOrder($order));
    }

    public function testIsActiveDelegatesToConfig(): void
    {
        self::assertTrue(
            $this->resolver($this->config(true, [], ['x']), $this->filter(), $this->createMock(EntityRepository::class))->isActive(),
        );
        self::assertFalse(
            $this->resolver($this->config(false), $this->filter(), $this->createMock(EntityRepository::class))->isActive(),
        );
    }

    public function testForOrderInactiveReturnsZero(): void
    {
        $resolver = $this->resolver(
            $this->config(false),
            $this->filter(),
            $this->createMock(EntityRepository::class),
        );
        $order = $this->order(CartPrice::TAX_STATE_GROSS, [
            $this->line('Zuschlag', 'product', null, 100.0, 7.0),
        ]);

        self::assertSame(['net' => 0.0, 'gross' => 0.0], $resolver->forOrder($order));
    }

    public function testEntriesInactiveReturnsEmptyWithoutQuery(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->expects(self::never())->method('search');

        $resolver = $this->resolver($this->config(false), $this->filter(), $repo);

        self::assertSame([], $resolver->entries(
            Context::createDefaultContext(),
            new \DateTimeImmutable('2026-06-02T00:00:00+00:00'),
            new \DateTimeImmutable('2026-06-03T00:00:00+00:00'),
        ));
    }

    public function testEntriesScopesByRangeAndStateAndComputesValues(): void
    {
        $orderDt = new \DateTimeImmutable('2026-06-01T22:30:00+00:00'); // 00:30 Berlin
        $line = $this->line('Zuschlag', 'custom', null, 100.0, 7.0);
        $line->setOrder($this->order(CartPrice::TAX_STATE_GROSS, [], $orderDt));

        $captured = null;
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('search')->willReturnCallback(
            static function (Criteria $c, Context $ctx) use (&$captured, $line): EntitySearchResult {
                $captured = $c;

                return new EntitySearchResult('order_line_item', 1, new OrderLineItemCollection([$line]), new AggregationResultCollection([]), $c, $ctx);
            },
        );

        $resolver = $this->resolver(
            $this->config(true, [], ['Zuschlag']),
            $this->filter(['oid'], ['pid']),
            $repo,
        );

        $startBerlin = new \DateTimeImmutable('2026-06-02T00:00:00', new \DateTimeZone('Europe/Berlin'));
        $endBerlin = $startBerlin->modify('+1 day');
        $entries = $resolver->entries(Context::createDefaultContext(), $startBerlin, $endBerlin);

        // Computed values + parent order datetime carried for bucketing.
        self::assertCount(1, $entries);
        self::assertSame(93.0, $entries[0]['net']);
        self::assertSame(100.0, $entries[0]['gross']);
        self::assertEquals($orderDt, $entries[0]['orderDateTime']);

        // order association loaded.
        self::assertArrayHasKey('order', $captured->getAssociations());

        // Range filter on the parent order's orderDateTime, expressed in UTC.
        $range = null;
        $stateFields = [];
        foreach ($captured->getFilters() as $f) {
            if ($f instanceof RangeFilter && $f->getField() === 'order.orderDateTime') {
                $range = $f;
            }
            if ($f instanceof EqualsAnyFilter) {
                $stateFields[$f->getField()] = $f->getValue();
            }
        }
        self::assertInstanceOf(RangeFilter::class, $range);
        self::assertSame(
            $startBerlin->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
            $range->getParameter(RangeFilter::GTE),
        );
        self::assertSame(['oid'], $stateFields['order.stateId'] ?? null);
        self::assertSame(['pid'], $stateFields['order.transactions.stateId'] ?? null);
    }
}
