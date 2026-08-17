<?php

declare(strict_types=1);

namespace Asbs\ShopwareStreamDeck\Service;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;

/**
 * Computes the net and gross amount of the configured "non-revenue" line items
 * (see [[RevenueExclusionConfig]]) so the metric services can subtract them.
 *
 * Net is not a DAL-aggregatable field on order_line_item (it only stores the gross
 * total_price; net + tax live inside the serialized `price` CalculatedPrice). So the
 * matched — and only the matched — line items are loaded as entities and their net/gross
 * are derived from the order's taxStatus via the universal rule `gross = net + tax`.
 */
class RevenueExclusionResolver
{
    /**
     * @param EntityRepository<\Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection> $lineItemRepository
     */
    public function __construct(
        private readonly RevenueExclusionConfig $config,
        private readonly OrderFilterConfig $filterConfig,
        private readonly EntityRepository $lineItemRepository,
    ) {
    }

    public function isActive(): bool
    {
        return $this->config->isActive();
    }

    /**
     * Sum of the matched line items of an already-loaded order (no query).
     * Used for per-order metrics (latest order, top order display).
     *
     * @return array{net:float,gross:float}
     */
    public function forOrder(OrderEntity $order): array
    {
        if (!$this->config->isActive()) {
            return ['net' => 0.0, 'gross' => 0.0];
        }

        $net = 0.0;
        $gross = 0.0;
        foreach ($order->getLineItems() ?? [] as $line) {
            if (!$this->matches($line)) {
                continue;
            }
            $price = $line->getPrice();
            if ($price === null) {
                continue;
            }
            $amounts = $this->netGross($price, $order->getTaxStatus());
            $net += $amounts['net'];
            $gross += $amounts['gross'];
        }

        return ['net' => $net, 'gross' => $gross];
    }

    /**
     * All matched line items in [$startUtc, $endUtc) scoped to the same order- and
     * payment-state filter as the revenue totals, each with its computed net/gross and
     * its parent order's orderDateTime so the caller can bucket identically to the
     * DateHistogramAggregation (by the UTC wall clock).
     *
     * @return list<array{net:float,gross:float,orderDateTime:\DateTimeInterface}>
     */
    public function entries(Context $context, \DateTimeImmutable $startUtc, \DateTimeImmutable $endUtc): array
    {
        if (!$this->config->isActive()) {
            return [];
        }

        $criteria = new Criteria();
        $criteria->addAssociation('order');
        $criteria->addFilter(new RangeFilter('order.orderDateTime', [
            RangeFilter::GTE => $this->utc($startUtc),
            RangeFilter::LT => $this->utc($endUtc),
        ]));

        $filter = $this->filterConfig->resolve($context);
        if ($filter['order'] !== []) {
            $criteria->addFilter(new EqualsAnyFilter('order.stateId', $filter['order']));
        }
        if ($filter['payment'] !== []) {
            $criteria->addFilter(new EqualsAnyFilter('order.transactions.stateId', $filter['payment']));
        }
        $criteria->addFilter($this->matchFilter());

        $entries = [];
        $lines = $this->lineItemRepository->search($criteria, $context)->getEntities();
        foreach ($lines as $line) {
            $order = $line->getOrder();
            $price = $line->getPrice();
            if ($order === null || $price === null) {
                continue;
            }
            $amounts = $this->netGross($price, $order->getTaxStatus());
            $entries[] = [
                'net' => $amounts['net'],
                'gross' => $amounts['gross'],
                'orderDateTime' => $order->getOrderDateTime(),
            ];
        }

        return $entries;
    }

    private function matches(OrderLineItemEntity $line): bool
    {
        if ($line->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE
            && $line->getReferencedId() !== null
            && \in_array($line->getReferencedId(), $this->config->productIds(), true)
        ) {
            return true;
        }

        return \in_array($line->getLabel(), $this->config->labels(), true);
    }

    /**
     * @return array{net:float,gross:float}
     */
    private function netGross(CalculatedPrice $price, ?string $taxStatus): array
    {
        $total = $price->getTotalPrice();
        $tax = 0.0;
        foreach ($price->getCalculatedTaxes() as $calculatedTax) {
            $tax += $calculatedTax->getTax();
        }

        // For net-priced orders totalPrice is already net → add tax for gross.
        // For gross-priced (and tax-free, where tax = 0) orders totalPrice is gross → subtract tax for net.
        if ($taxStatus === CartPrice::TAX_STATE_NET) {
            return ['net' => $total, 'gross' => $total + $tax];
        }

        return ['net' => $total - $tax, 'gross' => $total];
    }

    private function matchFilter(): Filter
    {
        $clauses = [];

        $productIds = $this->config->productIds();
        if ($productIds !== []) {
            $clauses[] = new MultiFilter(MultiFilter::CONNECTION_AND, [
                new EqualsFilter('type', LineItem::PRODUCT_LINE_ITEM_TYPE),
                new EqualsAnyFilter('referencedId', $productIds),
            ]);
        }

        $labels = $this->config->labels();
        if ($labels !== []) {
            $clauses[] = new EqualsAnyFilter('label', $labels);
        }

        return new MultiFilter(MultiFilter::CONNECTION_OR, $clauses);
    }

    private function utc(\DateTimeImmutable $d): string
    {
        return $d->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }
}
