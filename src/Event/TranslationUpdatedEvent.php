<?php

declare(strict_types=1);

namespace Survos\LinguaBundle\Event;

use Survos\LinguaBundle\Dto\TranslationUpdate;

/**
 * Fired after local StrTranslation rows have been written from a lingua webhook.
 *
 * This is the seam for app-specific work, and it is dispatched ONCE PER BATCH rather than once
 * per string — a delivery carries up to 500 translations, and the things listeners actually do
 * here (reindex a Meilisearch index, bust a cache, mark a folio stale) are all cheaper done
 * once for the batch than 500 times.
 *
 *     #[AsEventListener]
 *     public function onTranslated(TranslationUpdatedEvent $event): void
 *     {
 *         foreach ($event->locales() as $locale) {
 *             // ... reindex $locale
 *         }
 *     }
 *
 * lingua-bundle deliberately stops at babel's `StrTranslation`. It is the one shape lingua and
 * every consumer already agree on; an app's own translated entities are the app's business.
 */
final class TranslationUpdatedEvent
{
    public function __construct(
        /** @var TranslationUpdate[] the updates that actually changed a row */
        public readonly array $applied,
    ) {
    }

    /**
     * Distinct target locales touched by this batch.
     *
     * Almost every listener wants this rather than the rows: "which locales just changed" is
     * the question a reindex or a cache bust is actually asking.
     *
     * @return list<string>
     */
    public function locales(): array
    {
        $locales = [];
        foreach ($this->applied as $update) {
            $locales[$update->targetLocale] = true;
        }

        return array_keys($locales);
    }

    /** @return list<string> babel Str codes touched by this batch */
    public function refs(): array
    {
        return array_values(array_map(
            static fn(TranslationUpdate $u): string => $u->ref,
            $this->applied,
        ));
    }
}
