<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Survos\BabelBundle\Entity\StrTranslation as BabelStrTranslation;
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
    'Pull babel translations by source key into StrTranslation (no join).',
    aliases: ['babel:pull'],
    help: <<<'HELP'
FAST PULL (source-key lookup, no join)

Selects UNTRANSLATED StrTranslation rows, grouped by target locale,
and fetches translations by *source key* from the Lingua server.

IMPORTANT:
- Lingua /babel/pull returns a map keyed by Source.key (aka STR.code).
- In Babel schema, that is stored as StrTranslation.strCode.

Defaults:
  --targets      Defaults to enabled_locales
  --batch / -b   500
HELP
)]
final class LinguaPullBabelCommand
{
    private const string STUB_ENGINE = 'babel';

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
        #[Option('Batch size (keys per request).', shortcut: 'b')]
        int $batch = 500,
        #[Option('Hard cap on untranslated rows to process (debug).')]
        ?int $limit = null,
        #[Option('Do not group by locale; pull all keys in one stream.')]
        bool $noLocaleGrouping = false,
        #[Option('Only process rows created by Babel stubs (engine="babel").')]
        bool $onlyBabelEngine = true,
    ): int {
        $trClass = $this->resolveBabelTrClass();

        $targetLocales = $this->parseTargets($targets);

        $io->title('Lingua ⇄ Babel: PULL by source key');
        $io->writeln('Target locales: <info>'.($targetLocales ? implode(', ', $targetLocales) : '(all)').'</info>');
        if ($engine) {
            $io->writeln('Engine: <info>'.$engine.'</info>');
        }
        $io->writeln('Batch: <info>'.$batch.'</info>');
        if ($limit !== null) {
            $io->writeln('Global limit: <info>'.$limit.'</info>');
        }

        // 1) Find untranslated StrTranslation rows.
        // We need: strCode (the source key) + targetLocale
        $qb = $this->em->createQueryBuilder()
            ->select('t.strCode AS str_code, t.targetLocale AS locale')
            ->from($trClass, 't')
            ->andWhere('(t.text IS NULL OR t.text = \'\')')
            ->orderBy('t.strCode', 'ASC');

        if ($onlyBabelEngine) {
            $qb->andWhere('t.engine = :stubEngine')
                ->setParameter('stubEngine', self::STUB_ENGINE);
        }

        if ($targetLocales !== []) {
            $qb->andWhere('t.targetLocale IN (:locales)')
                ->setParameter('locales', $targetLocales);
        }

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        /** @var list<array{str_code:mixed, locale:mixed}> $rows */
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
            $key = (string) ($r['str_code'] ?? '');
            $loc = (string) ($r['locale'] ?? '');

            if ($key === '') {
                continue;
            }

            $loc = $loc !== '' ? HashUtil::normalizeLocale($loc) : '';

            if ($noLocaleGrouping) {
                $byLocale[''][] = $key;
            } else {
                $byLocale[$loc][] = $key;
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

        foreach ($byLocale as $locale => $keys) {
            $locale = trim($locale);
            $locale = $locale !== '' ? HashUtil::normalizeLocale($locale) : '';

            if (!$noLocaleGrouping) {
                $io->newLine(2);
                $io->section('Locale: ' . ($locale !== '' ? $locale : '(none)'));
            }

            foreach (array_chunk($keys, $batch) as $chunk) {
                $chunksRequested++;

                // 3) Ask Lingua for translations for this chunk of SOURCE keys.
                // Server returns: [ <strCode> => <translatedText>, ... ]
                $map = $this->linguaClient->pullBabelByHashes(
                    $chunk,
                    $locale !== '' ? $locale : null,
                    $engine
                );

                if (!is_array($map) || $map === []) {
                    $progress->advance(\count($chunk));
                    continue;
                }

                // 4) Update rows in-place via DQL UPDATE (avoid loading entities).
                foreach ($chunk as $strCode) {
                    if (!array_key_exists($strCode, $map)) {
                        $progress->advance(1);
                        continue;
                    }

                    $translated = $map[$strCode];
                    $translated = is_string($translated) ? $translated : (string) $translated;

                    if ($translated === '') {
                        $progress->advance(1);
                        continue;
                    }

                    $q = $this->em->createQuery(
                        'UPDATE '.$trClass.' t
                         SET t.text = :text
                         WHERE t.strCode = :strCode AND t.targetLocale = :locale'
                        . ($onlyBabelEngine ? ' AND t.engine = :stubEngine' : '')
                    );

                    $q->setParameter('text', $translated);
                    $q->setParameter('strCode', $strCode);
                    $q->setParameter('locale', $locale);
                    if ($onlyBabelEngine) {
                        $q->setParameter('stubEngine', self::STUB_ENGINE);
                    }

                    $affected = (int) $q->execute();
                    if ($affected > 0) {
                        $updated += $affected;
                    }

                    $progress->advance(1);
                }

                $this->em->clear();
            }
        }

        $progress->finish();
        $io->newLine(2);
        $io->success(sprintf('Updated translations: %d (chunks: %d)', $updated, $chunksRequested));

        return Command::SUCCESS;
    }

    /**
     * Prefer App override if present; otherwise use Babel bundle entity.
     *
     * @return class-string
     */
    private function resolveBabelTrClass(): string
    {
        $appTr  = 'App\\Entity\\StrTranslation';
        if (class_exists($appTr)) {
            return $appTr;
        }

        if (!class_exists(BabelStrTranslation::class)) {
            throw new LogicException('Babel StrTranslation entity not available. Install survos/babel-bundle or provide App\\Entity\\StrTranslation.');
        }

        return BabelStrTranslation::class;
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
