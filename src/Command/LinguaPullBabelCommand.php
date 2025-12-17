<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Survos\Lingua\Core\Identity\HashUtil;
use Survos\LinguaBundle\Service\LinguaClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    'lingua:pull',
    'Pull babel translations by hash into App\\Entity\\StrTranslation (no join).',
    aliases: ['babel:pull'],
    help: <<<'HELP'
FAST PULL (hash lookup, no join)

Selects UNTRANSLATED App\Entity\StrTranslation rows, grouped by target locale,
and fetches translations by *source hash* from the Lingua server.

IMPORTANT:
- Lingua /babel/pull returns a map keyed by Source.hash (aka STR.hash).
- In Babel schema, that is stored as StrTranslation.strHash (NOT StrTranslation.hash).

Defaults:
  --targets      Defaults to framework.translator.enabled_locales
  --batch / -b   500
HELP
)]
final class LinguaPullBabelCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LinguaClient $linguaClient,
        #[Autowire('%kernel.enabled_locales%')] private array $enabledLocales = [],
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Target locales (comma/space separated). Defaults to enabled_locales.')]
        ?string $targets = null,
        #[Option('Preferred translation engine to pass to Lingua (e.g. "libre", "deepl").')]
        ?string $engine = null,
        #[Option('Batch size (hashes per request).', shortcut: 'b')]
        int $batch = 500,
        #[Option('Hard cap on untranslated rows to process (debug).')]
        ?int $limit = null,
        #[Option('Do not group by locale; pull all hashes in one stream.')]
        bool $noLocaleGrouping = false,
    ): int {
        $strTrClass = 'App\\Entity\\StrTranslation';
        if (!class_exists($strTrClass)) {
            throw new LogicException('App\\Entity\\StrTranslation is required.');
        }

        $targetLocales = $this->parseTargets($targets);

        $io->title('Lingua ⇄ Babel: PULL by hash');
        $io->writeln('Target locales: <info>'.($targetLocales ? implode(', ', $targetLocales) : '(all)').'</info>');
        if ($engine) {
            $io->writeln('Engine: <info>'.$engine.'</info>');
        }
        $io->writeln('Batch: <info>'.$batch.'</info>');
        if ($limit !== null) {
            $io->writeln('Global limit: <info>'.$limit.'</info>');
        }

        // 1) Find untranslated StrTranslation rows.
        // IMPORTANT: We need the SOURCE HASH used by /babel/pull, which is StrTranslation.strHash.
        $qb = $this->em->createQueryBuilder()
            ->select('t.strHash AS str_hash, t.locale AS locale')
            ->from($strTrClass, 't')
            ->andWhere('(t.text IS NULL OR t.text = \'\')')
            ->orderBy('t.strHash', 'ASC');

        if ($targetLocales !== []) {
            $qb->andWhere('t.locale IN (:locales)')
                ->setParameter('locales', $targetLocales);
        }

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        /** @var list<array{str_hash:mixed, locale:mixed}> $rows */
        $rows = $qb->getQuery()->getArrayResult();
        if ($rows === []) {
            $io->success('No untranslated rows match filters.');
            return Command::SUCCESS;
        }

        $total = \count($rows);
        $io->writeln(sprintf('Untranslated rows: <info>%d</info>', $total));

        // 2) Group by locale (default) so we can pass locale hint to server.
        /** @var array<string, list<string>> $byLocale */
        $byLocale = [];

        foreach ($rows as $r) {
            $h = (string) ($r['str_hash'] ?? '');
            $l = (string) ($r['locale'] ?? '');
            if ($h === '') {
                continue;
            }

            // Normalize locale keys so DB matching + server calls align.
            $l = $l !== '' ? HashUtil::normalizeLocale($l) : '';

            if ($noLocaleGrouping) {
                $byLocale[''][] = $h;
            } else {
                $byLocale[$l][] = $h;
            }
        }

        if ($byLocale === []) {
            $io->success('No untranslated rows after normalization.');
            return Command::SUCCESS;
        }

        $progress = new ProgressBar($io, $total);
        $progress->start();

        $updated = 0;
        $chunksRequested = 0;

        foreach ($byLocale as $locale => $strHashes) {
            $locale = trim($locale);
            $locale = $locale !== '' ? HashUtil::normalizeLocale($locale) : '';

            if (!$noLocaleGrouping) {
                $io->newLine(2);
                $io->section('Locale: ' . ($locale !== '' ? $locale : '(none)'));
            }

            foreach (array_chunk($strHashes, $batch) as $chunk) {
                $chunksRequested++;

                // 3) Ask Lingua for translations for this chunk of SOURCE hashes.
                // Server returns: [ <sourceHash> => <translatedText>, ... ]
                $map = $this->linguaClient->pullBabelByHashes(
                    $chunk,
                    $locale !== '' ? $locale : null,
                    $engine
                );

                if (!is_array($map) || $map === []) {
                    // Still advance progress for every row in this chunk.
                    $progress->advance(\count($chunk));
                    continue;
                }

                // 4) Update rows in-place via DQL UPDATE (avoid loading entities).
                // IMPORTANT: Update by (strHash, locale), NOT by StrTranslation.hash.
                foreach ($chunk as $strHash) {
                    if (!array_key_exists($strHash, $map)) {
                        $progress->advance(1);
                        continue;
                    }

                    $translated = $map[$strHash];
                    $translated = is_string($translated) ? $translated : (string) $translated;

                    if ($translated === '') {
                        $progress->advance(1);
                        continue;
                    }

                    $q = $this->em->createQuery(
                        'UPDATE '.$strTrClass.' t
                         SET t.text = :text, t.status = :st
                         WHERE t.strHash = :strHash AND t.locale = :locale'
                    );
                    $q->setParameter('text', $translated);
                    $q->setParameter('st', 'translated');
                    $q->setParameter('strHash', $strHash);
                    $q->setParameter('locale', $locale);

                    // DQL UPDATE returns number of affected rows.
                    $affected = (int) $q->execute();
                    if ($affected > 0) {
                        $updated += $affected;
                    }

                    $progress->advance(1);
                }

                // Keep memory usage low; DQL UPDATE bypasses UoW but clearing is still fine.
                $this->em->clear();
            }
        }

        $progress->finish();
        $io->newLine(2);
        $io->success(sprintf('Updated translations: %d (chunks: %d)', $updated, $chunksRequested));

        return Command::SUCCESS;
    }

    /** @return list<string> */
    private function parseTargets(?string $targets): array
    {
        if ($targets === null || trim($targets) === '') {
            return array_values(array_unique(array_filter(array_map('trim', $this->enabledLocales))));
        }

        $parts = preg_split('/[,\s]+/', $targets) ?: [];
        return array_values(array_unique(array_filter(array_map('trim', $parts))));
    }
}
