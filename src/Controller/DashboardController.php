<?php

declare(strict_types=1);

namespace Asbs\ShopwareStreamDeck\Controller;

use Asbs\ShopwareStreamDeck\Service\ApiKeyManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api'], 'auth_required' => false])]
final class DashboardController extends AbstractController
{
    public const PLUGIN_VERSION = '0.6.0';

    public function __construct(private readonly ApiKeyManager $apiKeyManager)
    {
    }

    #[Route(path: '/api/_action/asbs-streamdeck/ping', name: 'api.asbs_streamdeck.ping', methods: ['GET'])]
    public function ping(Request $request): Response
    {
        if (!$this->authenticate($request)) {
            return new JsonResponse(['error' => 'invalid api key'], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse([
            'version' => self::PLUGIN_VERSION,
        ]);
    }

    #[Route(path: '/api/_action/asbs-streamdeck/dashboard', name: 'api.asbs_streamdeck.dashboard', methods: ['GET'])]
    public function dashboard(Request $request): Response
    {
        if (!$this->authenticate($request)) {
            return new JsonResponse(['error' => 'invalid api key'], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse([
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'revenueToday' => ['value' => 0.0, 'currency' => 'EUR', 'comparedToLastWeek' => null],
            'ordersToday' => ['count' => 0, 'sinceLastViewed' => 0],
            'visitors' => ['currentlyOnline' => null, 'supported' => false],
        ]);
    }

    private function authenticate(Request $request): bool
    {
        $key = $request->headers->get('X-Asbs-Streamdeck-Key') ?? '';

        return $key !== '' && $this->apiKeyManager->isValid($key);
    }
}
