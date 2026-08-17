<?php

declare(strict_types=1);

namespace Survos\LinguaBundle\Webhook;

use Survos\Kit\Webhook\AbstractJsonWebhookParser;

/**
 * Authenticates the lingua server's `translation.completed` deliveries at `/webhook/lingua`.
 *
 * Verification, the fail-closed empty-secret rule and the "authenticated but not for me is a
 * 200" rule all live in {@see AbstractJsonWebhookParser}; only the name and the event list are
 * specific to lingua.
 *
 * This replaced a `LinguaWebhookController` that had never been reachable: its route was not
 * registered, and its own docblock recorded that the `lingua.webhook_key` parameter it read
 * had never been defined anywhere either. It was a placeholder for this.
 *
 * Configure in the consuming app:
 *
 *     framework:
 *         webhook:
 *             routing:
 *                 lingua:
 *                     service: Survos\LinguaBundle\Webhook\LinguaWebhookRequestParser
 *                     secret: '%env(LINGUA_WEBHOOK_SECRET)%'
 */
final class LinguaWebhookRequestParser extends AbstractJsonWebhookParser
{
    /** Also the `#[AsRemoteEventConsumer]` key — see {@see \Survos\LinguaBundle\RemoteEvent\TranslationRemoteEventConsumer}. */
    public const string WEBHOOK_NAME = 'lingua';

    public const string EVENT_TRANSLATION_COMPLETED = 'translation.completed';

    protected function webhookName(): string
    {
        return self::WEBHOOK_NAME;
    }

    protected function acceptedEvents(): array
    {
        return [self::EVENT_TRANSLATION_COMPLETED];
    }
}
