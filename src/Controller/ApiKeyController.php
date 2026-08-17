<?php

declare(strict_types=1);

namespace Asbs\ShopwareStreamDeck\Controller;

use Asbs\ShopwareStreamDeck\Service\ApiKeyManager;
use Asbs\ShopwareStreamDeck\Service\OrderMetricsService;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin-facing CRUD for Stream-Deck API keys, used by the plugin-config
 * component so shop operators never need the CLI.
 *
 * Unlike the metric endpoints (authed via the X-Asbs-Streamdeck-Key shared
 * secret), these manage that very secret and must therefore be restricted to a
 * logged-in admin user — not just any valid API token (an integration token
 * must not be able to mint or revoke keys).
 */
#[Route(defaults: ['_routeScope' => ['api']])]
final class ApiKeyController extends AbstractController
{
    public function __construct(
        private readonly ApiKeyManager $apiKeyManager,
        private readonly OrderMetricsService $metrics,
    ) {
    }

    #[Route(
        path: '/api/_action/asbs-streamdeck/keys',
        name: 'api.asbs_streamdeck.keys.list',
        methods: ['GET'],
    )]
    public function list(Context $context): Response
    {
        if (($r = $this->requireAdminUser($context)) !== null) {
            return $r;
        }

        return new JsonResponse($this->apiKeyManager->list());
    }

    #[Route(
        path: '/api/_action/asbs-streamdeck/keys',
        name: 'api.asbs_streamdeck.keys.create',
        methods: ['POST'],
    )]
    public function create(Request $request, Context $context): Response
    {
        if (($r = $this->requireAdminUser($context)) !== null) {
            return $r;
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        $label = \is_array($payload) && isset($payload['label']) ? (string) $payload['label'] : '';

        // Returned exactly once; only the hash is persisted.
        return new JsonResponse(['secret' => $this->apiKeyManager->create(mb_substr($label, 0, 255))]);
    }

    #[Route(
        path: '/api/_action/asbs-streamdeck/keys/{id}',
        name: 'api.asbs_streamdeck.keys.delete',
        methods: ['DELETE'],
    )]
    public function delete(string $id, Context $context): Response
    {
        if (($r = $this->requireAdminUser($context)) !== null) {
            return $r;
        }

        $this->apiKeyManager->delete($id);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Self-test behind the "Test connection" button in the plugin configuration.
     *
     * Always answers 200 — a failed check is data, not a transport error, so the
     * admin's HTTP client never mistakes an invalid key for an expired session.
     * Works without a key on hand (secrets are shown exactly once): the key check
     * is then reported as skipped while the store and read-path checks still run.
     */
    #[Route(
        path: '/api/_action/asbs-streamdeck/keys/test',
        name: 'api.asbs_streamdeck.keys.test',
        methods: ['POST'],
    )]
    public function test(Request $request, Context $context): Response
    {
        if (($r = $this->requireAdminUser($context)) !== null) {
            return $r;
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        $payload = \is_array($payload) ? $payload : [];
        $key = isset($payload['key']) ? trim((string) $payload['key']) : '';
        $timeZone = isset($payload['tz']) ? (string) $payload['tz'] : 'UTC';
        if (!\in_array($timeZone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            $timeZone = 'UTC';
        }

        $checks = [];

        $keyCount = 0;
        try {
            $keyCount = $this->apiKeyManager->count();
            $checks[] = self::check('keyStore', $keyCount > 0 ? 'ok' : 'warning', ['count' => $keyCount]);
        } catch (\Throwable $e) {
            $checks[] = self::check('keyStore', 'error', ['message' => $e->getMessage()]);
        }

        if ($key === '') {
            $checks[] = self::check('apiKey', 'skipped');
        } else {
            $valid = false;
            try {
                $valid = $this->apiKeyManager->matches($key);
                $checks[] = self::check('apiKey', $valid ? 'ok' : 'error');
            } catch (\Throwable $e) {
                $checks[] = self::check('apiKey', 'error', ['message' => $e->getMessage()]);
            }
            $key = $valid ? $key : '';
        }

        try {
            // Exercises the very read path the metric endpoints use, so a broken
            // order-state filter or DAL problem surfaces here instead of on the
            // Stream Deck.
            $revenue = $this->metrics->revenueToday(new Context(new SystemSource()), $timeZone);
            $checks[] = self::check('metrics', 'ok', ['orders' => $revenue['count']]);
        } catch (\Throwable $e) {
            $checks[] = self::check('metrics', 'error', ['message' => $e->getMessage()]);
        }

        $failed = array_filter($checks, static fn (array $c): bool => $c['status'] === 'error');

        return new JsonResponse([
            'success' => $failed === [],
            'version' => DashboardController::PLUGIN_VERSION,
            'keyCount' => $keyCount,
            // Tells the admin component whether it may replay the public ping
            // endpoint with this key for a true end-to-end round-trip.
            'liveKey' => $key !== '',
            'checks' => $checks,
        ]);
    }

    /**
     * @param array<string, scalar> $detail
     *
     * @return array{id:string,status:string,detail:array<string, scalar>}
     */
    private static function check(string $id, string $status, array $detail = []): array
    {
        return ['id' => $id, 'status' => $status, 'detail' => $detail];
    }

    private function requireAdminUser(Context $context): ?JsonResponse
    {
        $source = $context->getSource();
        if (!$source instanceof AdminApiSource || $source->getUserId() === null) {
            return new JsonResponse(['error' => 'admin user required'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }
}
