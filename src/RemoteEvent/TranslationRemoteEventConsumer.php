<?php

declare(strict_types=1);

namespace Survos\LinguaBundle\RemoteEvent;

use Survos\LinguaBundle\Dto\TranslationUpdate;
use Survos\LinguaBundle\Service\TranslationUpdateApplier;
use Survos\LinguaBundle\Webhook\LinguaWebhookRequestParser;
use Symfony\Component\RemoteEvent\Attribute\AsRemoteEventConsumer;
use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;

/**
 * Async arm of the translation update path.
 *
 * Contains no logic beyond unpacking the page: the webhook body is
 * `{"event":…, "count":N, "translations":[…]}` and every element becomes a
 * {@see TranslationUpdate} for {@see TranslationUpdateApplier}.
 *
 * By the time this runs the request is long over — FrameworkBundle's WebhookController
 * authenticated the delivery, answered 202 and put a `ConsumeRemoteEventMessage` on the queue.
 * That ordering is the answer to the question this whole feature exists for: the app learns a
 * string was translated without polling, and without a translation server holding an HTTP
 * connection open while the app writes 500 rows.
 */
#[AsRemoteEventConsumer(LinguaWebhookRequestParser::WEBHOOK_NAME)]
final class TranslationRemoteEventConsumer implements ConsumerInterface
{
    public function __construct(
        private readonly TranslationUpdateApplier $applier,
    ) {
    }

    public function consume(RemoteEvent $event): void
    {
        $rows = $event->getPayload()['translations'] ?? [];
        if (!\is_array($rows)) {
            return;
        }

        $this->applier->applyBatch(array_map(
            static fn(array $row): TranslationUpdate => TranslationUpdate::fromWebhook($row),
            array_values(array_filter($rows, 'is_array')),
        ));
    }
}
