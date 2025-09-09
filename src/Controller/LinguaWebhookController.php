<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Optional webhook endpoint for clients to receive results back from the server. */
final class LinguaWebhookController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire(param: 'lingua.webhook_key')] private readonly ?string $webhookKey = null,
    ) {}

    #[Route(path: '/_lingua/webhook', name: 'lingua_webhook', methods: ['POST'])]
    public function receive(Request $request): JsonResponse
    {
        if ($this->webhookKey) {
            $key = $request->headers->get('X-Api-Key');
            if (!$key || !\hash_equals($this->webhookKey, $key)) {
                return $this->json(['status' => 'forbidden'], 403);
            }
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $this->logger->info('Lingua webhook received', ['payload' => $payload]);

        return $this->json(['status' => 'ok']);
    }
}
