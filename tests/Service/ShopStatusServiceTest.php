<?php

declare(strict_types=1);

namespace Asbs\ShopwareStreamDeck\Tests\Service;

use Asbs\ShopwareStreamDeck\Service\ShopStatusService;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\CountResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskDefinition;

final class ShopStatusServiceTest extends TestCase
{
    /**
     * @param array<\Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AbstractAggregationResult> $aggregations
     */
    private function repoReturning(array $aggregations, ?Criteria &$captured = null): EntityRepository
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo
            ->method('search')
            ->willReturnCallback(static function (Criteria $c, Context $ctx) use ($aggregations, &$captured): EntitySearchResult {
                $captured = $c;

                return new EntitySearchResult(
                    'scheduled_task',
                    0,
                    new EntityCollection(),
                    new AggregationResultCollection($aggregations),
                    $c,
                    $ctx,
                );
            });

        return $repo;
    }

    public function testReportsProductionModeAndOverdueTaskCount(): void
    {
        $captured = null;
        $repo = $this->repoReturning([new CountResult('count', 5)], $captured);
        $service = new ShopStatusService('prod', false, $repo);

        $result = $service->collect(Context::createDefaultContext());

        self::assertSame('prod', $result['environment']);
        self::assertTrue($result['production']);
        self::assertFalse($result['debug']);
        self::assertSame(5, $result['overdueScheduledTasks']);

        // Only scheduled/queued tasks past their due time (minus grace) count as overdue.
        $statusFilters = array_values(array_filter($captured->getFilters(), static fn ($f) => $f instanceof EqualsAnyFilter));
        self::assertCount(1, $statusFilters);
        self::assertSame('status', $statusFilters[0]->getField());
        self::assertSame(
            [ScheduledTaskDefinition::STATUS_SCHEDULED, ScheduledTaskDefinition::STATUS_QUEUED],
            $statusFilters[0]->getValue(),
        );

        $rangeFilters = array_values(array_filter($captured->getFilters(), static fn ($f) => $f instanceof RangeFilter));
        self::assertCount(1, $rangeFilters);
        self::assertSame('nextExecutionTime', $rangeFilters[0]->getField());
        self::assertArrayHasKey(RangeFilter::LT, $rangeFilters[0]->getParameters());
    }

    public function testReportsNonProductionWhenEnvironmentIsDev(): void
    {
        $service = new ShopStatusService('dev', true, $this->repoReturning([new CountResult('count', 0)]));

        $result = $service->collect(Context::createDefaultContext());

        self::assertFalse($result['production']);
        self::assertTrue($result['debug']);
        self::assertSame(0, $result['overdueScheduledTasks']);
    }

    public function testReportsServerTimeAsTimestampAndIso(): void
    {
        $service = new ShopStatusService('prod', false, $this->repoReturning([new CountResult('count', 0)]));

        $result = $service->collect(Context::createDefaultContext());

        self::assertIsInt($result['serverTimestamp']);
        self::assertGreaterThan(0, $result['serverTimestamp']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $result['serverTime']));
    }
}
