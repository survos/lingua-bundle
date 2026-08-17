<?php

declare(strict_types=1);

namespace Survos\LinguaBundle\Dto;

/**
 * One finished translation, as announced by lingua's `translation.completed` webhook.
 *
 * Normalises a single element of the webhook's `translations` array into the shape of a local
 * babel `StrTranslation` row. Deliberately small: lingua knows about strings and locales and
 * nothing about an app's own entities, so anything further hangs off
 * {@see \Survos\LinguaBundle\Event\TranslationUpdatedEvent}.
 */
final class TranslationUpdate
{
    public function __construct(
        /**
         * Our OWN key for the string — babel's `Str.code`, which we handed lingua as `refs`
         * when we pushed. Not lingua's content hash: reversing that is what `lingua:pull` has
         * to do, and the whole point of sending the ref up front was to skip it here.
         */
        public readonly string $ref,
        public readonly string $targetLocale,
        public readonly ?string $engine = null,
        public readonly ?string $text = null,
        /** The engine returned the source unchanged. A real answer, not a failure. */
        public readonly bool $identical = false,
    ) {
    }

    /**
     * One element of the webhook's `translations` array.
     *
     * Unknown keys are ignored on purpose: this is a pub/sub contract, and a subscriber must
     * not break the first time lingua sends more than it used to.
     */
    public static function fromWebhook(array $row): self
    {
        return new self(
            ref: (string) ($row['ref'] ?? ''),
            targetLocale: (string) ($row['targetLocale'] ?? ''),
            engine: isset($row['engine']) ? (string) $row['engine'] : null,
            text: isset($row['text']) ? (string) $row['text'] : null,
            identical: (bool) ($row['identical'] ?? false),
        );
    }

    /** Enough to write a row: without a key, a locale and text there is nothing to apply. */
    public function isUsable(): bool
    {
        return $this->ref !== '' && $this->targetLocale !== '' && ($this->text ?? '') !== '';
    }
}
