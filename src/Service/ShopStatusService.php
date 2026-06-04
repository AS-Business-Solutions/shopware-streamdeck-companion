<?php

declare(strict_types=1);

namespace Asbs\ShopwareStreamDeck\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\CountAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\CountResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskDefinition;

/**
 * Collects a Frosh-Tools-style health snapshot of the shop for the Stream-Deck
 * status tile: production mode, the server clock, and the number of overdue
 * scheduled tasks.
 */
final class ShopStatusService
{
    /**
     * Tasks routinely sit a few seconds past their due time before the cron
     * worker picks them up; without this grace the tile would flash red on a
     * perfectly healthy shop.
     */
    private const OVERDUE_GRACE_SECONDS = 30;

    /**
     * @param EntityRepository<\Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection> $scheduledTaskRepository
     */
    public function __construct(
        private readonly string $environment,
        private readonly bool $debug,
        private readonly EntityRepository $scheduledTaskRepository,
    ) {
    }

    /**
     * @return array{environment:string,production:bool,debug:bool,serverTime:string,serverTimestamp:int,overdueScheduledTasks:int}
     */
    public function collect(Context $context): array
    {
        $now = new \DateTimeImmutable();

        return [
            'environment' => $this->environment,
            'production' => $this->environment === 'prod',
            'debug' => $this->debug,
            'serverTime' => $now->format(\DateTimeInterface::ATOM),
            'serverTimestamp' => $now->getTimestamp(),
            'overdueScheduledTasks' => $this->countOverdueTasks($context, $now),
        ];
    }

    private function countOverdueTasks(Context $context, \DateTimeImmutable $now): int
    {
        $threshold = $now->modify(\sprintf('-%d seconds', self::OVERDUE_GRACE_SECONDS));

        $criteria = new Criteria();
        $criteria->setLimit(1);
        $criteria->addFilter(new EqualsAnyFilter('status', [
            ScheduledTaskDefinition::STATUS_SCHEDULED,
            ScheduledTaskDefinition::STATUS_QUEUED,
        ]));
        $criteria->addFilter(new RangeFilter('nextExecutionTime', [
            RangeFilter::LT => $threshold->format(\DateTimeInterface::ATOM),
        ]));
        $criteria->addAggregation(new CountAggregation('count', 'id'));

        $count = $this->scheduledTaskRepository->search($criteria, $context)->getAggregations()->get('count');

        return $count instanceof CountResult ? $count->getCount() : 0;
    }
}
