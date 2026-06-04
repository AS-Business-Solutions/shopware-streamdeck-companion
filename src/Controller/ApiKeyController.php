<?php

declare(strict_types=1);

namespace Asbs\ShopwareStreamDeck\Controller;

use Asbs\ShopwareStreamDeck\Service\ApiKeyManager;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
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
    public function __construct(private readonly ApiKeyManager $apiKeyManager)
    {
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

    private function requireAdminUser(Context $context): ?JsonResponse
    {
        $source = $context->getSource();
        if (!$source instanceof AdminApiSource || $source->getUserId() === null) {
            return new JsonResponse(['error' => 'admin user required'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }
}
