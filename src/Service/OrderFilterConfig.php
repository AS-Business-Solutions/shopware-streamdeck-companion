<?php

declare(strict_types=1);

namespace Asbs\ShopwareStreamDeck\Service;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Reads the configured order-state and payment-state filter from the system
 * config and resolves the technical names to the matching state-machine UUIDs.
 * Empty configuration is the sentinel for "no filter" — i.e. all orders count.
 *
 * Resolution is cached on the instance, so callers in the same request only
 * hit the database once even when six metric endpoints share this service.
 */
class OrderFilterConfig
{
    public const CONFIG_ORDER_STATES = 'ASBusStreamDeckDashboard.config.orderStates';
    public const CONFIG_PAYMENT_STATES = 'ASBusStreamDeckDashboard.config.paymentStates';

    /** @var array{order:string[],payment:string[]}|null */
    private ?array $cache = null;

    /**
     * @param EntityRepository<\Shopware\Core\Framework\DataAbstractionLayer\EntityCollection<\Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity>> $stateMachineStateRepository
     */
    public function __construct(
        private readonly SystemConfigService $systemConfig,
        private readonly EntityRepository $stateMachineStateRepository,
    ) {
    }

    /**
     * @return array{order:string[],payment:string[]} arrays of state-machine
     *         state UUIDs. Empty array = no filter for that dimension.
     */
    public function resolve(Context $context): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $orderTechnical = $this->readTechnicalNames(self::CONFIG_ORDER_STATES);
        $paymentTechnical = $this->readTechnicalNames(self::CONFIG_PAYMENT_STATES);

        $this->cache = [
            'order' => $orderTechnical === []
                ? []
                : $this->resolveStateIds(OrderStates::STATE_MACHINE, $orderTechnical, $context),
            'payment' => $paymentTechnical === []
                ? []
                : $this->resolveStateIds(OrderTransactionStates::STATE_MACHINE, $paymentTechnical, $context),
        ];

        return $this->cache;
    }

    /**
     * @return string[]
     */
    private function readTechnicalNames(string $key): array
    {
        $raw = $this->systemConfig->get($key);
        if (!\is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, static fn ($v) => \is_string($v) && $v !== ''));
    }

    /**
     * @param string[] $technicalNames
     *
     * @return string[]
     */
    private function resolveStateIds(string $stateMachine, array $technicalNames, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('stateMachine.technicalName', $stateMachine));
        $criteria->addFilter(new EqualsAnyFilter('technicalName', $technicalNames));

        return array_values($this->stateMachineStateRepository->searchIds($criteria, $context)->getIds());
    }
}
