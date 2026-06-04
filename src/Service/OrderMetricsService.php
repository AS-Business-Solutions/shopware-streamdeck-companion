<?php

declare(strict_types=1);

namespace Asbs\ShopwareStreamDeck\Service;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\DateHistogramAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\CountAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\SumAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\DateHistogramResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\CountResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\SumResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Server-side metric computation backing the Stream-Deck companion endpoints.
 *
 * Every revenue figure is reported as BOTH net and gross — the order's amountNet
 * and amountTotal are summed in parallel so the consumer can switch modes locally
 * without re-querying. Configured non-revenue line items (see
 * [[RevenueExclusionConfig]]/[[RevenueExclusionResolver]]) are subtracted from both
 * values; when nothing is configured the resolver is a no-op and the fast,
 * aggregation-only path stays intact.
 *
 * All methods share buildBaseCriteria() so the OrderFilterConfig (configured order-
 * and payment-status filter) is applied identically to every metric — and so the
 * exclusion subtraction is scoped to exactly the same order set as the totals.
 */
final class OrderMetricsService
{
    /**
     * @param EntityRepository<\Shopware\Core\Checkout\Order\OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly OrderFilterConfig $filterConfig,
        private readonly RevenueExclusionResolver $exclusions,
    ) {
    }

    /**
     * @return array{id:string,orderNumber:string,customerName:string,amountNet:float,amountGross:float,currency:string,orderedAt:string}|null
     */
    public function latestOrder(Context $context): ?array
    {
        $criteria = $this->buildBaseCriteria($context);
        $criteria->setLimit(1);
        $criteria->addSorting(new FieldSorting('orderDateTime', FieldSorting::DESCENDING));
        $criteria->addAssociations(['orderCustomer', 'currency']);
        if ($this->exclusions->isActive()) {
            $criteria->addAssociation('lineItems');
        }

        $order = $this->orderRepository->search($criteria, $context)->first();
        if (!$order instanceof OrderEntity) {
            return null;
        }

        return $this->orderToArray($order);
    }

    /**
     * Highest-value order of the day. Ranked by net-of-exclusion gross; when no
     * exclusions are configured the database does the sorting (fast path).
     *
     * @return array{id:string,orderNumber:string,customerName:string,amountNet:float,amountGross:float,currency:string,orderedAt:string}|null
     */
    public function topOrderToday(Context $context, string $timeZone = 'UTC'): ?array
    {
        [$start, $end] = $this->todayRange($context, $timeZone);

        if (!$this->exclusions->isActive()) {
            $criteria = $this->buildBaseCriteria($context);
            $criteria->setLimit(1);
            $criteria->addFilter($this->rangeFilter($start, $end));
            $criteria->addSorting(new FieldSorting('amountTotal', FieldSorting::DESCENDING));
            $criteria->addAssociations(['orderCustomer', 'currency']);

            $order = $this->orderRepository->search($criteria, $context)->first();

            return $order instanceof OrderEntity ? $this->orderToArray($order) : null;
        }

        // Exclusions change the effective gross, so the DB sort no longer reflects the
        // ranking — re-rank today's orders in PHP by their net-of-exclusion gross.
        $criteria = $this->buildBaseCriteria($context);
        $criteria->addFilter($this->rangeFilter($start, $end));
        $criteria->addAssociations(['orderCustomer', 'currency', 'lineItems']);

        $top = null;
        $topGross = null;
        foreach ($this->orderRepository->search($criteria, $context)->getEntities() as $order) {
            $adjustedGross = (float) $order->getAmountTotal() - $this->exclusions->forOrder($order)['gross'];
            if ($topGross === null || $adjustedGross > $topGross) {
                $topGross = $adjustedGross;
                $top = $order;
            }
        }

        return $top instanceof OrderEntity ? $this->orderToArray($top) : null;
    }

    /**
     * @return array{amountNet:float,amountGross:float,currency:string,count:int}
     */
    public function revenueToday(Context $context, string $timeZone = 'UTC'): array
    {
        [$start, $end] = $this->todayRange($context, $timeZone);
        $criteria = $this->buildBaseCriteria($context);
        $criteria->setLimit(1);
        $criteria->addFilter($this->rangeFilter($start, $end));
        $criteria->addAggregation(new SumAggregation('sumNet', 'amountNet'));
        $criteria->addAggregation(new SumAggregation('sumGross', 'amountTotal'));
        $criteria->addAggregation(new CountAggregation('count', 'id'));

        $result = $this->orderRepository->search($criteria, $context);
        $sumNet = $result->getAggregations()->get('sumNet');
        $sumGross = $result->getAggregations()->get('sumGross');
        $count = $result->getAggregations()->get('count');

        $excl = $this->sumEntries($this->exclusions->entries($context, $start, $end));

        return [
            'amountNet' => ($sumNet instanceof SumResult ? (float) $sumNet->getSum() : 0.0) - $excl['net'],
            'amountGross' => ($sumGross instanceof SumResult ? (float) $sumGross->getSum() : 0.0) - $excl['gross'],
            'currency' => 'EUR',
            'count' => $count instanceof CountResult ? $count->getCount() : 0,
        ];
    }

    /**
     * @return array{amountNet:float,amountGross:float}
     */
    public function aovToday(Context $context, string $timeZone = 'UTC'): array
    {
        $rev = $this->revenueToday($context, $timeZone);
        $count = $rev['count'];

        return [
            'amountNet' => $count > 0 ? $rev['amountNet'] / $count : 0.0,
            'amountGross' => $count > 0 ? $rev['amountGross'] / $count : 0.0,
        ];
    }

    /**
     * One bucket per calendar day for the last $days days (incl. today), oldest first.
     *
     * @return array<int,array{start:string,amountNet:float,amountGross:float}>
     */
    public function revenueByDay(Context $context, int $days, string $timeZone): array
    {
        $days = max(1, min($days, 60));
        $tz = new \DateTimeZone($timeZone);
        $today = $this->startOfDay(new \DateTimeImmutable('now', $tz));
        $start = $today->modify(\sprintf('-%d days', $days - 1));
        $end = $today->modify('+1 day');

        $criteria = $this->buildBaseCriteria($context);
        $criteria->setLimit(1);
        $criteria->addFilter($this->rangeFilter($start, $end));
        $criteria->addAggregation($this->hourlyHistogram('perHourNet', 'amountNet'));
        $criteria->addAggregation($this->hourlyHistogram('perHourGross', 'amountTotal'));

        $result = $this->orderRepository->search($criteria, $context);
        $netByDay = $this->sumsByLocalDay($result->getAggregations()->get('perHourNet'), $tz);
        $grossByDay = $this->sumsByLocalDay($result->getAggregations()->get('perHourGross'), $tz);

        // Subtract exclusions keyed by the same local day the order hours map to, so an
        // order and its excluded position always land in the same bucket (no negatives).
        foreach ($this->exclusions->entries($context, $start, $end) as $e) {
            $key = $this->localDay($e['orderDateTime'], $tz);
            $netByDay[$key] = ($netByDay[$key] ?? 0.0) - $e['net'];
            $grossByDay[$key] = ($grossByDay[$key] ?? 0.0) - $e['gross'];
        }

        $out = [];
        for ($i = 0; $i < $days; ++$i) {
            $key = $start->modify(\sprintf('+%d days', $i))->format('Y-m-d');
            $out[] = [
                'start' => $key,
                'amountNet' => $netByDay[$key] ?? 0.0,
                'amountGross' => $grossByDay[$key] ?? 0.0,
            ];
        }

        return $out;
    }

    /**
     * One bucket per hour for today (0..23), oldest first.
     *
     * @return array<int,array{hour:int,amountNet:float,amountGross:float}>
     */
    public function revenueByHour(Context $context, string $timeZone): array
    {
        $tz = new \DateTimeZone($timeZone);
        [$start, $end] = $this->todayRange($context, $timeZone);

        $criteria = $this->buildBaseCriteria($context);
        $criteria->setLimit(1);
        $criteria->addFilter($this->rangeFilter($start, $end));
        $criteria->addAggregation($this->hourlyHistogram('perHourNet', 'amountNet'));
        $criteria->addAggregation($this->hourlyHistogram('perHourGross', 'amountTotal'));

        $result = $this->orderRepository->search($criteria, $context);
        $net = $this->sumsByLocalHour($result->getAggregations()->get('perHourNet'), $tz);
        $gross = $this->sumsByLocalHour($result->getAggregations()->get('perHourGross'), $tz);

        foreach ($this->exclusions->entries($context, $start, $end) as $e) {
            $hour = $this->localHour($e['orderDateTime'], $tz);
            $net[$hour] -= $e['net'];
            $gross[$hour] -= $e['gross'];
        }

        $out = [];
        for ($h = 0; $h < 24; ++$h) {
            $out[] = ['hour' => $h, 'amountNet' => $net[$h], 'amountGross' => $gross[$h]];
        }

        return $out;
    }

    /**
     * One bucket per calendar month of the current year (January → current month,
     * configured-tz), oldest first. Built on the same PER-HOUR machinery as
     * revenueByDay (no CONVERT_TZ); the UTC hours are mapped to the local month in PHP.
     * A naive PER_MONTH histogram would re-introduce the timezone bug (revenue sloshing
     * across month boundaries when MySQL's tz tables are absent).
     *
     * @return array<int,array{start:string,amountNet:float,amountGross:float}>
     */
    public function revenueByMonth(Context $context, string $timeZone): array
    {
        $tz = new \DateTimeZone($timeZone);
        $now = new \DateTimeImmutable('now', $tz);
        $year = (int) $now->format('Y');
        $months = (int) $now->format('n'); // 1..12, current month
        $start = $now->setDate($year, 1, 1)->setTime(0, 0, 0);
        $end = $this->startOfDay($now)->modify('+1 day');

        $criteria = $this->buildBaseCriteria($context);
        $criteria->setLimit(1);
        $criteria->addFilter($this->rangeFilter($start, $end));
        $criteria->addAggregation($this->hourlyHistogram('perHourNet', 'amountNet'));
        $criteria->addAggregation($this->hourlyHistogram('perHourGross', 'amountTotal'));

        $result = $this->orderRepository->search($criteria, $context);
        $netByMonth = $this->sumsByLocalMonth($result->getAggregations()->get('perHourNet'), $tz);
        $grossByMonth = $this->sumsByLocalMonth($result->getAggregations()->get('perHourGross'), $tz);

        // Subtract exclusions keyed by the same local month the order hours map to.
        foreach ($this->exclusions->entries($context, $start, $end) as $e) {
            $key = $this->localMonth($e['orderDateTime'], $tz);
            $netByMonth[$key] = ($netByMonth[$key] ?? 0.0) - $e['net'];
            $grossByMonth[$key] = ($grossByMonth[$key] ?? 0.0) - $e['gross'];
        }

        $out = [];
        for ($m = 1; $m <= $months; ++$m) {
            $key = \sprintf('%04d-%02d', $year, $m);
            $out[] = [
                'start' => $key,
                'amountNet' => $netByMonth[$key] ?? 0.0,
                'amountGross' => $grossByMonth[$key] ?? 0.0,
            ];
        }

        return $out;
    }

    private function buildBaseCriteria(Context $context): Criteria
    {
        $criteria = new Criteria();
        $filter = $this->filterConfig->resolve($context);
        if ($filter['order'] !== []) {
            $criteria->addFilter(new EqualsAnyFilter('stateId', $filter['order']));
        }
        if ($filter['payment'] !== []) {
            $criteria->addFilter(new EqualsAnyFilter('transactions.stateId', $filter['payment']));
        }

        return $criteria;
    }

    /**
     * @param list<array{net:float,gross:float,orderDateTime:\DateTimeInterface}> $entries
     *
     * @return array{net:float,gross:float}
     */
    private function sumEntries(array $entries): array
    {
        $net = 0.0;
        $gross = 0.0;
        foreach ($entries as $e) {
            $net += $e['net'];
            $gross += $e['gross'];
        }

        return ['net' => $net, 'gross' => $gross];
    }

    /**
     * Bucket by the raw UTC hour: passing no timezone means no CONVERT_TZ, which would
     * need MySQL's timezone tables loaded (they often aren't). The UTC hours are mapped
     * to the configured local day/hour in PHP, DST-correctly via the IANA zone.
     */
    private function hourlyHistogram(string $name, string $field): DateHistogramAggregation
    {
        return new DateHistogramAggregation(
            $name,
            'orderDateTime',
            DateHistogramAggregation::PER_HOUR,
            null,
            new SumAggregation($name.'Sum', $field),
        );
    }

    /**
     * @return array<string,float> sum keyed by local calendar day (Y-m-d)
     */
    private function sumsByLocalDay(mixed $histogram, \DateTimeZone $tz): array
    {
        $map = [];
        foreach ($this->histogramHours($histogram) as [$instant, $sum]) {
            $key = $instant->setTimezone($tz)->format('Y-m-d');
            $map[$key] = ($map[$key] ?? 0.0) + $sum;
        }

        return $map;
    }

    /**
     * @return array<int,float> 24 local hour slots (0..23), filled with 0
     */
    private function sumsByLocalHour(mixed $histogram, \DateTimeZone $tz): array
    {
        $hours = array_fill(0, 24, 0.0);
        foreach ($this->histogramHours($histogram) as [$instant, $sum]) {
            $hours[(int) $instant->setTimezone($tz)->format('G')] += $sum;
        }

        return $hours;
    }

    /**
     * @return list<array{0:\DateTimeImmutable,1:float}> [UTC hour instant, summed amount]
     */
    private function histogramHours(mixed $histogram): array
    {
        $out = [];
        if ($histogram instanceof DateHistogramResult) {
            foreach ($histogram->getBuckets() as $bucket) {
                $key = (string) $bucket->getKey();
                if ($key === '') {
                    continue;
                }
                $sum = $bucket->getResult();
                $out[] = [
                    new \DateTimeImmutable($key, new \DateTimeZone('UTC')),
                    $sum instanceof SumResult ? (float) $sum->getSum() : 0.0,
                ];
            }
        }

        return $out;
    }

    /**
     * @return array<string,float> sum keyed by local calendar month (Y-m)
     */
    private function sumsByLocalMonth(mixed $histogram, \DateTimeZone $tz): array
    {
        $map = [];
        foreach ($this->histogramHours($histogram) as [$instant, $sum]) {
            $key = $instant->setTimezone($tz)->format('Y-m');
            $map[$key] = ($map[$key] ?? 0.0) + $sum;
        }

        return $map;
    }

    private function localDay(\DateTimeInterface $d, \DateTimeZone $tz): string
    {
        return \DateTimeImmutable::createFromInterface($d)->setTimezone($tz)->format('Y-m-d');
    }

    private function localMonth(\DateTimeInterface $d, \DateTimeZone $tz): string
    {
        return \DateTimeImmutable::createFromInterface($d)->setTimezone($tz)->format('Y-m');
    }

    private function localHour(\DateTimeInterface $d, \DateTimeZone $tz): int
    {
        return (int) \DateTimeImmutable::createFromInterface($d)->setTimezone($tz)->format('G');
    }

    /**
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable}
     */
    private function todayRange(Context $context, ?string $timeZone = null): array
    {
        $tz = new \DateTimeZone($timeZone ?? 'UTC');
        $start = $this->startOfDay(new \DateTimeImmutable('now', $tz));

        return [$start, $start->modify('+1 day')];
    }

    private function startOfDay(\DateTimeImmutable $d): \DateTimeImmutable
    {
        return $d->setTime(0, 0, 0);
    }

    private function rangeFilter(\DateTimeImmutable $start, \DateTimeImmutable $end): RangeFilter
    {
        return new RangeFilter('orderDateTime', [
            RangeFilter::GTE => $this->rangeBoundary($start),
            RangeFilter::LT => $this->rangeBoundary($end),
        ]);
    }

    /**
     * Render a range boundary for the DAL.
     *
     * Shopware's RangeFilter compares the *wall-clock* portion of a datetime value
     * against the UTC-stored orderDateTime and ignores the timezone offset. A
     * Europe/Berlin "today 00:00" boundary formatted with its native "+02:00" offset
     * is therefore read as 00:00 UTC (= 02:00 Berlin), silently dropping every order
     * placed in the first hours of the local day. Converting to UTC first makes the
     * wall clock the correct instant regardless of how the DAL treats the offset.
     */
    private function rangeBoundary(\DateTimeImmutable $d): string
    {
        return $d->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }

    /**
     * @return array{id:string,orderNumber:string,customerName:string,amountNet:float,amountGross:float,currency:string,orderedAt:string}
     */
    private function orderToArray(OrderEntity $order): array
    {
        $excl = $this->exclusions->forOrder($order);
        $customer = $order->getOrderCustomer();
        $first = trim((string) ($customer?->getFirstName() ?? ''));
        $last = trim((string) ($customer?->getLastName() ?? ''));
        $name = trim($first.' '.$last);

        return [
            'id' => $order->getId(),
            'orderNumber' => (string) ($order->getOrderNumber() ?? ''),
            'customerName' => $name !== '' ? $name : '—',
            'amountNet' => (float) $order->getAmountNet() - $excl['net'],
            'amountGross' => (float) $order->getAmountTotal() - $excl['gross'],
            'currency' => $order->getCurrency()?->getIsoCode() ?? 'EUR',
            'orderedAt' => $order->getOrderDateTime()->format(\DateTimeInterface::ATOM),
        ];
    }
}
