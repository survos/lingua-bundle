<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Optional webhook endpoint for the translation-server to POST results back.
 * Validates a shared key via X-Api-Key.
 */
final class LinguaWebhookController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire(param: 'lingua.webhook_key')] private readonly ?string $webhookKey = null,
    ) {}

    #[Route(path: '/_lingua/webhook', name: 'lingua_webhook', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        if ($this->webhookKey) {
            $key = $request->headers->get('X-Api-Key');
            if (!$key || !\hash_equals($this->webhookKey, $key)) {
                return $this->json(['status' => 'forbidden'], 403);
            }
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        // You can dispatch a Symfony Event here for app-specific handling.
        $this->logger->info('Lingua webhook received', ['payload' => $payload]);

        return $this->json(['status' => 'ok']);
    }
}
