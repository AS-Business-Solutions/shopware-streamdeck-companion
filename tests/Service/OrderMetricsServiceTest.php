<?php

declare(strict_types=1);

namespace Asbs\ShopwareStreamDeck\Tests\Service;

use Asbs\ShopwareStreamDeck\Service\OrderFilterConfig;
use Asbs\ShopwareStreamDeck\Service\OrderMetricsService;
use Asbs\ShopwareStreamDeck\Service\RevenueExclusionResolver;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\Bucket;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\DateHistogramResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\CountResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\SumResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

final class OrderMetricsServiceTest extends TestCase
{
    private function filter(array $order = [], array $payment = []): OrderFilterConfig
    {
        $filter = $this->createMock(OrderFilterConfig::class);
        $filter->method('resolve')->willReturn(['order' => $order, 'payment' => $payment]);

        return $filter;
    }

    /**
     * @param list<array{net:float,gross:float,orderDateTime:\DateTimeInterface}> $entries
     */
    private function resolver(bool $active = false, array $entries = [], ?\Closure $forOrder = null): RevenueExclusionResolver
    {
        $resolver = $this->createMock(RevenueExclusionResolver::class);
        $resolver->method('isActive')->willReturn($active);
        $resolver->method('entries')->willReturn($entries);
        $resolver->method('forOrder')->willReturnCallback(
            $forOrder ?? static fn (): array => ['net' => 0.0, 'gross' => 0.0],
        );

        return $resolver;
    }

    private function repoCallback(\Closure $cb): EntityRepository
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('search')->willReturnCallback($cb);

        return $repo;
    }

    private function aggregationResult(AggregationResultCollection $aggs, OrderCollection $orders = new OrderCollection()): \Closure
    {
        return static fn (Criteria $c, Context $ctx): EntitySearchResult => new EntitySearchResult(
            'order',
            $orders->count(),
            $orders,
            $aggs,
            $c,
            $ctx,
        );
    }

    // ── revenueToday ───────────────────────────────────────────────

    public function testRevenueTodayReturnsNetGrossAndCount(): void
    {
        $repo = $this->repoCallback($this->aggregationResult(new AggregationResultCollection([
            new SumResult('sumNet', 1000.0),
            new SumResult('sumGross', 1070.0),
            new CountResult('count', 7),
        ])));

        $result = (new OrderMetricsService($repo, $this->filter(), $this->resolver()))
            ->revenueToday(Context::createDefaultContext());

        self::assertSame(['amountNet' => 1000.0, 'amountGross' => 1070.0, 'currency' => 'EUR', 'count' => 7], $result);
    }

    public function testRevenueTodayAppliesConfiguredStateFilters(): void
    {
        $captured = null;
        $repo = $this->repoCallback(function (Criteria $c, Context $ctx) use (&$captured): EntitySearchResult {
            $captured = $c;

            return new EntitySearchResult('order', 0, new OrderCollection(), new AggregationResultCollection([]), $c, $ctx);
        });

        (new OrderMetricsService($repo, $this->filter(['uuid-completed'], ['uuid-paid']), $this->resolver()))
            ->revenueToday(Context::createDefaultContext());

        $stateFilters = array_values(array_filter($captured->getFilters(), static fn ($f) => $f instanceof EqualsAnyFilter));
        self::assertCount(2, $stateFilters);
        self::assertSame('stateId', $stateFilters[0]->getField());
        self::assertSame(['uuid-completed'], $stateFilters[0]->getValue());
        self::assertSame('transactions.stateId', $stateFilters[1]->getField());
        self::assertSame(['uuid-paid'], $stateFilters[1]->getValue());
    }

    public function testRevenueTodaySubtractsExclusionsFromBothValues(): void
    {
        $repo = $this->repoCallback($this->aggregationResult(new AggregationResultCollection([
            new SumResult('sumNet', 1000.0),
            new SumResult('sumGross', 1070.0),
            new CountResult('count', 7),
        ])));
        $resolver = $this->resolver(true, [
            ['net' => 90.0, 'gross' => 100.0, 'orderDateTime' => new \DateTimeImmutable()],
            ['net' => 9.0, 'gross' => 10.0, 'orderDateTime' => new \DateTimeImmutable()],
        ]);

        $result = (new OrderMetricsService($repo, $this->filter(), $resolver))
            ->revenueToday(Context::createDefaultContext());

        // count stays (orders are not removed), amounts drop by the exclusion sums.
        self::assertSame(['amountNet' => 901.0, 'amountGross' => 960.0, 'currency' => 'EUR', 'count' => 7], $result);
    }

    public function testRevenueTodayRangeBoundaryUsesUtcWallClockForBerlinMidnight(): void
    {
        $captured = null;
        $repo = $this->repoCallback(function (Criteria $c, Context $ctx) use (&$captured): EntitySearchResult {
            $captured = $c;

            return new EntitySearchResult('order', 0, new OrderCollection(), new AggregationResultCollection([]), $c, $ctx);
        });

        (new OrderMetricsService($repo, $this->filter(), $this->resolver()))
            ->revenueToday(Context::createDefaultContext(), 'Europe/Berlin');

        $range = null;
        foreach ($captured->getFilters() as $f) {
            if ($f instanceof RangeFilter && $f->getField() === 'orderDateTime') {
                $range = $f;
            }
        }
        self::assertInstanceOf(RangeFilter::class, $range);

        $emitted = new \DateTimeImmutable((string) $range->getParameter(RangeFilter::GTE));
        $emittedWallClockAsUtc = new \DateTimeImmutable($emitted->format('Y-m-d\TH:i:s'), new \DateTimeZone('UTC'));
        $expected = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))
            ->setTime(0, 0, 0)
            ->setTimezone(new \DateTimeZone('UTC'));

        self::assertSame($expected->format('Y-m-d H:i:s'), $emittedWallClockAsUtc->format('Y-m-d H:i:s'));
    }

    // ── aovToday ───────────────────────────────────────────────────

    public function testAovTodayDividesBothValuesByCount(): void
    {
        $repo = $this->repoCallback($this->aggregationResult(new AggregationResultCollection([
            new SumResult('sumNet', 1000.0),
            new SumResult('sumGross', 1190.0),
            new CountResult('count', 4),
        ])));

        $aov = (new OrderMetricsService($repo, $this->filter(), $this->resolver()))
            ->aovToday(Context::createDefaultContext());

        self::assertSame(['amountNet' => 250.0, 'amountGross' => 297.5], $aov);
    }

    public function testAovTodayReturnsZeroWhenNoOrders(): void
    {
        $repo = $this->repoCallback($this->aggregationResult(new AggregationResultCollection([
            new SumResult('sumNet', 0.0),
            new SumResult('sumGross', 0.0),
            new CountResult('count', 0),
        ])));

        $aov = (new OrderMetricsService($repo, $this->filter(), $this->resolver()))
            ->aovToday(Context::createDefaultContext());

        self::assertSame(['amountNet' => 0.0, 'amountGross' => 0.0], $aov);
    }

    // ── latestOrder ────────────────────────────────────────────────

    public function testLatestOrderReturnsNullWhenNoResults(): void
    {
        $repo = $this->repoCallback($this->aggregationResult(new AggregationResultCollection([]), new OrderCollection()));

        self::assertNull(
            (new OrderMetricsService($repo, $this->filter(), $this->resolver()))->latestOrder(Context::createDefaultContext()),
        );
    }

    public function testLatestOrderMapsBothAmountsWithoutExclusion(): void
    {
        $order = $this->order('order-1', '10099', 116.5, 124.5, 'Sabine', 'Müller');
        $repo = $this->repoCallback($this->aggregationResult(new AggregationResultCollection([], ), new OrderCollection([$order])));

        $dto = (new OrderMetricsService($repo, $this->filter(), $this->resolver()))
            ->latestOrder(Context::createDefaultContext());

        self::assertSame('order-1', $dto['id']);
        self::assertSame('10099', $dto['orderNumber']);
        self::assertSame('Sabine Müller', $dto['customerName']);
        self::assertSame(116.5, $dto['amountNet']);
        self::assertSame(124.5, $dto['amountGross']);
        self::assertSame('EUR', $dto['currency']);
    }

    public function testLatestOrderSubtractsExclusionAndLoadsLineItems(): void
    {
        $order = $this->order('order-1', '10099', 116.5, 124.5, 'Sabine', 'Müller');
        $captured = null;
        $repo = $this->repoCallback(function (Criteria $c, Context $ctx) use (&$captured, $order): EntitySearchResult {
            $captured = $c;

            return new EntitySearchResult('order', 1, new OrderCollection([$order]), new AggregationResultCollection([]), $c, $ctx);
        });
        $resolver = $this->resolver(true, [], static fn (): array => ['net' => 16.5, 'gross' => 24.5]);

        $dto = (new OrderMetricsService($repo, $this->filter(), $resolver))
            ->latestOrder(Context::createDefaultContext());

        self::assertSame(100.0, $dto['amountNet']);
        self::assertSame(100.0, $dto['amountGross']);
        self::assertArrayHasKey('lineItems', $captured->getAssociations());
    }

    // ── topOrderToday ──────────────────────────────────────────────

    public function testTopOrderTodayInactiveSortsByGrossLimitOne(): void
    {
        $order = $this->order('top', '20001', 95.0, 100.0, 'Max', 'Mustermann');
        $captured = null;
        $repo = $this->repoCallback(function (Criteria $c, Context $ctx) use (&$captured, $order): EntitySearchResult {
            $captured = $c;

            return new EntitySearchResult('order', 1, new OrderCollection([$order]), new AggregationResultCollection([]), $c, $ctx);
        });

        $dto = (new OrderMetricsService($repo, $this->filter(), $this->resolver()))
            ->topOrderToday(Context::createDefaultContext());

        self::assertSame('top', $dto['id']);
        self::assertSame(100.0, $dto['amountGross']);
        self::assertSame(1, $captured->getLimit());
        $sortings = $captured->getSorting();
        self::assertSame('amountTotal', $sortings[0]->getField());
        self::assertSame(FieldSorting::DESCENDING, $sortings[0]->getDirection());
    }

    public function testTopOrderTodayActiveRanksByAdjustedGross(): void
    {
        // Raw gross: A (200) > B (120). Adjusted gross: A 200-150=50 < B 120-10=110 → B wins.
        $a = $this->order('A', '1', 190.0, 200.0, 'Anna', 'A');
        $b = $this->order('B', '2', 110.0, 120.0, 'Bert', 'B');
        $repo = $this->repoCallback($this->aggregationResult(new AggregationResultCollection([]), new OrderCollection([$a, $b])));

        $forOrder = static fn (OrderEntity $o): array => match ($o->getId()) {
            'A' => ['net' => 120.0, 'gross' => 150.0],
            'B' => ['net' => 5.0, 'gross' => 10.0],
            default => ['net' => 0.0, 'gross' => 0.0],
        };

        $dto = (new OrderMetricsService($repo, $this->filter(), $this->resolver(true, [], $forOrder)))
            ->topOrderToday(Context::createDefaultContext());

        self::assertSame('B', $dto['id']);
        self::assertSame(110.0, $dto['amountGross']);
        self::assertSame(105.0, $dto['amountNet']);
    }

    // ── revenueByDay / revenueByHour bucketing + exclusion alignment ─

    public function testRevenueByDayMapsUtcHourBucketsToLocalDayAndSubtractsExclusion(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $utc = new \DateTimeZone('UTC');
        $berlinMidnight = (new \DateTimeImmutable('now', $tz))->setTime(0, 0, 0);
        $todayKey = $berlinMidnight->format('Y-m-d');
        // An order at Berlin 00:30 today; the histogram emits its raw UTC hour.
        $orderInstant = $berlinMidnight->modify('+30 minutes');
        $utcHourKey = $orderInstant->setTimezone($utc)->format('Y-m-d H').':00:00.000';

        $repo = $this->repoCallback($this->aggregationResult(new AggregationResultCollection([
            new DateHistogramResult('perHourNet', [new Bucket($utcHourKey, 1, new SumResult('s', 93.0))]),
            new DateHistogramResult('perHourGross', [new Bucket($utcHourKey, 1, new SumResult('s', 100.0))]),
        ])));
        $resolver = $this->resolver(true, [
            ['net' => 28.0, 'gross' => 30.0, 'orderDateTime' => $orderInstant],
        ]);

        $buckets = (new OrderMetricsService($repo, $this->filter(), $resolver))
            ->revenueByDay(Context::createDefaultContext(), 3, 'Europe/Berlin');

        $today = array_values(array_filter($buckets, static fn ($b) => $b['start'] === $todayKey))[0];
        self::assertSame(65.0, $today['amountNet']);
        self::assertSame(70.0, $today['amountGross']);
        foreach ($buckets as $b) {
            self::assertGreaterThanOrEqual(0.0, $b['amountGross']);
            self::assertGreaterThanOrEqual(0.0, $b['amountNet']);
        }
    }

    public function testRevenueByHourMapsUtcHourBucketsToLocalHourAndSubtractsNearMidnightExclusion(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $utc = new \DateTimeZone('UTC');
        // A near-midnight order at Berlin 00:30 today → must land in local hour 0.
        $instant = (new \DateTimeImmutable('now', $tz))->setTime(0, 30, 0);
        $utcHourKey = $instant->setTimezone($utc)->format('Y-m-d H').':00:00.000';

        $repo = $this->repoCallback($this->aggregationResult(new AggregationResultCollection([
            new DateHistogramResult('perHourNet', [new Bucket($utcHourKey, 1, new SumResult('s', 93.0))]),
            new DateHistogramResult('perHourGross', [new Bucket($utcHourKey, 1, new SumResult('s', 100.0))]),
        ])));
        $resolver = $this->resolver(true, [
            ['net' => 37.0, 'gross' => 40.0, 'orderDateTime' => $instant],
        ]);

        $buckets = (new OrderMetricsService($repo, $this->filter(), $resolver))
            ->revenueByHour(Context::createDefaultContext(), 'Europe/Berlin');

        self::assertSame(0, $buckets[0]['hour']);
        self::assertSame(56.0, $buckets[0]['amountNet']);
        self::assertSame(60.0, $buckets[0]['amountGross']);
        foreach ($buckets as $b) {
            self::assertGreaterThanOrEqual(0.0, $b['amountGross']);
        }
    }

    // ── revenueByMonth bucketing + exclusion alignment ─────────────

    public function testRevenueByMonthMapsUtcHourBucketsToLocalMonthAndSubtractsExclusion(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $tz);
        // An order at Berlin 00:30 on the 1st of the current month → current-month bucket.
        $firstOfMonth = $now->setDate((int) $now->format('Y'), (int) $now->format('n'), 1)->setTime(0, 30, 0);
        $monthKey = $firstOfMonth->format('Y-m');
        $utcHourKey = $firstOfMonth->setTimezone($utc)->format('Y-m-d H').':00:00.000';

        $repo = $this->repoCallback($this->aggregationResult(new AggregationResultCollection([
            new DateHistogramResult('perHourNet', [new Bucket($utcHourKey, 1, new SumResult('s', 930.0))]),
            new DateHistogramResult('perHourGross', [new Bucket($utcHourKey, 1, new SumResult('s', 1000.0))]),
        ])));
        $resolver = $this->resolver(true, [
            ['net' => 28.0, 'gross' => 30.0, 'orderDateTime' => $firstOfMonth],
        ]);

        $buckets = (new OrderMetricsService($repo, $this->filter(), $resolver))
            ->revenueByMonth(Context::createDefaultContext(), 'Europe/Berlin');

        // One bucket per month from January to the current month — no future zeros.
        self::assertCount((int) $now->format('n'), $buckets);
        $current = array_values(array_filter($buckets, static fn ($b) => $b['start'] === $monthKey))[0];
        self::assertSame(902.0, $current['amountNet']);   // 930 - 28
        self::assertSame(970.0, $current['amountGross']); // 1000 - 30
        foreach ($buckets as $b) {
            self::assertGreaterThanOrEqual(0.0, $b['amountNet']);
            self::assertGreaterThanOrEqual(0.0, $b['amountGross']);
        }
    }

    // ── helper ─────────────────────────────────────────────────────

    private function order(string $id, string $number, float $net, float $total, string $first, string $last): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId($id);
        $order->setUniqueIdentifier($id);
        $order->setOrderNumber($number);
        $order->setAmountNet($net);
        $order->setAmountTotal($total);
        $order->setOrderDateTime(new \DateTimeImmutable('2026-06-02T10:00:00+00:00'));
        $customer = new \Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity();
        $customer->setUniqueIdentifier('oc-'.$id);
        $customer->setFirstName($first);
        $customer->setLastName($last);
        $order->setOrderCustomer($customer);
        $currency = new \Shopware\Core\System\Currency\CurrencyEntity();
        $currency->setUniqueIdentifier('cur-'.$id);
        $currency->setIsoCode('EUR');
        $order->setCurrency($currency);

        return $order;
    }
}
