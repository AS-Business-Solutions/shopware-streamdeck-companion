<?php

declare(strict_types=1);

namespace Asbs\ShopwareStreamDeck\Controller;

use Asbs\ShopwareStreamDeck\Service\ApiKeyManager;
use Asbs\ShopwareStreamDeck\Service\OrderMetricsService;
use Asbs\ShopwareStreamDeck\Service\ShopStatusService;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api'], 'auth_required' => false])]
final class MetricsController extends AbstractController
{
    public function __construct(
        private readonly ApiKeyManager $apiKeyManager,
        private readonly OrderMetricsService $metrics,
        private readonly ShopStatusService $shopStatus,
    ) {
    }

    #[Route(
        path: '/api/_action/asbs-streamdeck/metrics/latest-order',
        name: 'api.asbs_streamdeck.metrics.latest_order',
        methods: ['GET'],
    )]
    public function latestOrder(Request $request): Response
    {
        if (($r = $this->authenticate($request)) !== null) {
            return $r;
        }

        return new JsonResponse($this->metrics->latestOrder(Context::createDefaultContext()));
    }

    #[Route(
        path: '/api/_action/asbs-streamdeck/metrics/top-order-today',
        name: 'api.asbs_streamdeck.metrics.top_order_today',
        methods: ['GET'],
    )]
    public function topOrderToday(Request $request): Response
    {
        if (($r = $this->authenticate($request)) !== null) {
            return $r;
        }

        return new JsonResponse($this->metrics->topOrderToday(
            Context::createDefaultContext(),
            $this->timeZone($request),
        ));
    }

    #[Route(
        path: '/api/_action/asbs-streamdeck/metrics/revenue-today',
        name: 'api.asbs_streamdeck.metrics.revenue_today',
        methods: ['GET'],
    )]
    public function revenueToday(Request $request): Response
    {
        if (($r = $this->authenticate($request)) !== null) {
            return $r;
        }

        return new JsonResponse($this->metrics->revenueToday(
            Context::createDefaultContext(),
            $this->timeZone($request),
        ));
    }

    #[Route(
        path: '/api/_action/asbs-streamdeck/metrics/aov-today',
        name: 'api.asbs_streamdeck.metrics.aov_today',
        methods: ['GET'],
    )]
    public function aovToday(Request $request): Response
    {
        if (($r = $this->authenticate($request)) !== null) {
            return $r;
        }

        return new JsonResponse($this->metrics->aovToday(
            Context::createDefaultContext(),
            $this->timeZone($request),
        ));
    }

    #[Route(
        path: '/api/_action/asbs-streamdeck/metrics/revenue-by-day',
        name: 'api.asbs_streamdeck.metrics.revenue_by_day',
        methods: ['GET'],
    )]
    public function revenueByDay(Request $request): Response
    {
        if (($r = $this->authenticate($request)) !== null) {
            return $r;
        }
        $days = max(1, min(60, (int) $request->query->get('days', '7')));
        $buckets = $this->metrics->revenueByDay(
            Context::createDefaultContext(),
            $days,
            $this->timeZone($request),
        );

        return new JsonResponse(['buckets' => $buckets]);
    }

    #[Route(
        path: '/api/_action/asbs-streamdeck/metrics/revenue-by-month',
        name: 'api.asbs_streamdeck.metrics.revenue_by_month',
        methods: ['GET'],
    )]
    public function revenueByMonth(Request $request): Response
    {
        if (($r = $this->authenticate($request)) !== null) {
            return $r;
        }
        $buckets = $this->metrics->revenueByMonth(
            Context::createDefaultContext(),
            $this->timeZone($request),
        );

        return new JsonResponse(['buckets' => $buckets]);
    }

    #[Route(
        path: '/api/_action/asbs-streamdeck/metrics/revenue-by-hour',
        name: 'api.asbs_streamdeck.metrics.revenue_by_hour',
        methods: ['GET'],
    )]
    public function revenueByHour(Request $request): Response
    {
        if (($r = $this->authenticate($request)) !== null) {
            return $r;
        }
        $buckets = $this->metrics->revenueByHour(
            Context::createDefaultContext(),
            $this->timeZone($request),
        );

        return new JsonResponse(['buckets' => $buckets]);
    }

    #[Route(
        path: '/api/_action/asbs-streamdeck/metrics/shop-status',
        name: 'api.asbs_streamdeck.metrics.shop_status',
        methods: ['GET'],
    )]
    public function shopStatusEndpoint(Request $request): Response
    {
        if (($r = $this->authenticate($request)) !== null) {
            return $r;
        }

        return new JsonResponse($this->shopStatus->collect(Context::createDefaultContext()));
    }

    private function authenticate(Request $request): ?JsonResponse
    {
        $key = $request->headers->get('X-Asbs-Streamdeck-Key') ?? '';
        if ($key === '' || !$this->apiKeyManager->isValid($key)) {
            return new JsonResponse(['error' => 'invalid api key'], Response::HTTP_UNAUTHORIZED);
        }

        return null;
    }

    private function timeZone(Request $request): string
    {
        $tz = (string) $request->query->get('tz', 'UTC');
        if (!\in_array($tz, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            return 'UTC';
        }

        return $tz;
    }
}
