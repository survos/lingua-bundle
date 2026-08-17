<?php

declare(strict_types=1);

namespace Survos\LinguaBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Entity\StrTranslation as BabelStrTranslation;
use Survos\LinguaBundle\Dto\TranslationUpdate;
use Survos\LinguaBundle\Event\TranslationUpdatedEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Writes finished translations from lingua onto local babel `StrTranslation` rows.
 *
 * ## Relationship to `lingua:pull`
 *
 * Both end at the same two columns (`text`, `engine`) selected the same way
 * (`strCode` + `targetLocale`), and they are NOT merged into one implementation, which is worth
 * being explicit about because the equivalent split on the media side is what let 26,244 rows
 * end up with no dimensions.
 *
 * The difference here is real: `lingua:pull` issues a bulk DQL UPDATE over rows it selected
 * itself and never loads an entity, because it runs over tens of thousands of rows and must not
 * hydrate them. This path handles a webhook page of at most 500 and needs the entities anyway,
 * to know which rows actually changed and to give listeners something to react to. What keeps
 * them from drifting is that neither invents a value: lingua sends the text, and both do
 * nothing but store it.
 *
 * ## "Not ours" is normal
 *
 * lingua serves several apps and a subscription is per (target, callback url), so a delivery
 * should only ever contain our own refs. A ref with no local row is still logged and skipped
 * rather than treated as an error — the alternative is a 4xx that makes lingua park a whole
 * page of good translations in its failure transport because of one stale row.
 *
 * Idempotent: redelivery is normal (re-pushing a string deliberately clears `notifiedAt` on the
 * server so it is announced again). Applying the same text twice reports no change.
 */
final class TranslationUpdateApplier
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param TranslationUpdate[] $updates
     *
     * @return array{applied:int, changed:int, skipped:int}
     */
    public function applyBatch(array $updates): array
    {
        $class = $this->translationClass();
        $repository = $this->em->getRepository($class);

        $applied = 0;
        $skipped = 0;
        $changedUpdates = [];

        foreach ($updates as $update) {
            if (!$update->isUsable()) {
                $skipped++;
                continue;
            }

            $applied++;

            // Selected WITHOUT engine, matching lingua:pull. `engine` records which provider
            // produced the text, so it is an OUTPUT of translation, not part of the row's
            // identity — filtering on it would miss the row whenever the server switched
            // engines, which is exactly when the text is new.
            $row = $repository->findOneBy([
                'strCode' => $update->ref,
                'targetLocale' => $update->targetLocale,
            ]);

            if ($row === null) {
                $this->logger->info('translation update: no local row for {ref}/{locale}, ignoring', [
                    'ref' => $update->ref,
                    'locale' => $update->targetLocale,
                ]);
                $skipped++;
                $applied--;
                continue;
            }

            $changed = false;

            if ($row->text !== $update->text) {
                $row->text = $update->text;
                $changed = true;
            }

            // Only when lingua told us — never overwrite a recorded provider with null.
            if ($update->engine !== null && $row->engine !== $update->engine) {
                $row->engine = $update->engine;
                $changed = true;
            }

            if ($changed) {
                $row->status = $row::STATUS_TRANSLATED;
                $row->updatedAt = new \DateTimeImmutable('now');
                $changedUpdates[] = $update;
            }
        }

        if ($changedUpdates !== []) {
            $this->em->flush();

            $this->logger->info('translation update: {changed} row(s) written across {locales} locale(s)', [
                'changed' => \count($changedUpdates),
                'locales' => \count(array_unique(array_map(
                    static fn(TranslationUpdate $u): string => $u->targetLocale,
                    $changedUpdates,
                ))),
            ]);

            // One event for the whole page — see TranslationUpdatedEvent on why not per row.
            $this->dispatcher->dispatch(new TranslationUpdatedEvent($changedUpdates));
        }

        return ['applied' => $applied, 'changed' => \count($changedUpdates), 'skipped' => $skipped];
    }

    /**
     * The app's StrTranslation if it has subclassed babel's, else babel's own.
     *
     * Same resolution `lingua:pull` uses. An app that extends the entity has its own class
     * mapped, and asking Doctrine for the parent would return a repository for a class the
     * schema does not use.
     */
    private function translationClass(): string
    {
        $appClass = 'App\\Entity\\StrTranslation';
        if (class_exists($appClass)) {
            return $appClass;
        }

        if (!class_exists(BabelStrTranslation::class)) {
            throw new LogicException('Babel StrTranslation entity not available.');
        }

        return BabelStrTranslation::class;
    }
}
