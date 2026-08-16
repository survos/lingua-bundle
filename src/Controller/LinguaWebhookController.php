<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Optional webhook endpoint for clients to receive results back from the server.
 *
 * $webhookKey arrives from SurvosLinguaBundle::loadExtension(), like every other setting in
 * this bundle. It used to be #[Autowire(param: 'lingua.webhook_key')] against a parameter
 * that was never defined anywhere -- latent rather than fatal only because this controller's
 * route is not currently registered, so nothing ever instantiated it.
 */
final class LinguaWebhookController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?string $webhookKey = null,
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
