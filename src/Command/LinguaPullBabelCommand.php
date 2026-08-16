<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Survos\BabelBundle\Entity\Str as BabelStr;
use Survos\BabelBundle\Entity\StrTranslation as BabelStrTranslation;
use Survos\Lingua\Core\Identity\HashUtil;
use Survos\LinguaBundle\Service\LinguaCall;
use Survos\LinguaBundle\Service\LinguaClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    'lingua:pull',
    'Pull babel translations by source key into StrTranslation.',
    aliases: ['babel:pull'],
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
        #[Option('Provider engine to pass to Lingua (e.g. "libre", "deepl").')]
        ?string $engine = null,
        #[Option('Batch size (keys per request).', shortcut: 'b')]
        int $batch = 500,
        #[Option('Hard cap on untranslated rows to process (debug).')]
        ?int $limit = null,
        #[Option('Do not group by locale; pull all keys in one stream.')]
        bool $noLocaleGrouping = false,
    ): int {
        $trClass = $this->resolveBabelTrClass();
        $targetLocales = $this->parseTargets($targets);

        $io->title('Lingua ⇄ Babel: PULL by source key');
        $io->writeln('Target locales: <info>'.($targetLocales ? implode(', ', $targetLocales) : '(all)').'</info>');
        if ($engine) {
            $io->writeln('Provider engine: <info>'.$engine.'</info>');
        }
        $io->writeln('Batch: <info>'.$batch.'</info>');
        if ($limit !== null) {
            $io->writeln('Global limit: <info>'.$limit.'</info>');
        }

        // No engine filter: `engine` records real provenance (libre/deepl/...), not a
        // "this is our stub" marker — the row is a pull candidate purely by having empty
        // text. One StrTranslation row per (str_code, target_locale) is the current
        // invariant, so there's no competing-candidate case to disambiguate here yet.
        $qb = $this->em->createQueryBuilder()
            ->select('t.strCode AS str_code, t.targetLocale AS locale')
            ->from($trClass, 't')
            ->andWhere('(t.text IS NULL OR t.text = \'\')')
            ->orderBy('t.strCode', 'ASC');

        if ($targetLocales !== []) {
            $qb->andWhere('t.targetLocale IN (:locales)')
                ->setParameter('locales', $targetLocales);
        }

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        $rows = $qb->getQuery()->getArrayResult();
        if ($rows === []) {
            $io->success('No untranslated rows match filters.');
            return Command::SUCCESS;
        }

        $total = \count($rows);
        $io->writeln(sprintf('Untranslated rows: <info>%d</info>', $total));

        // The server matches by Source.hash (a hash of the source text + source locale),
        // not by our local str_code -- so resolve each code's source text/locale and
        // compute the same hash the server used when the source was first pushed.
        $codes = array_values(array_unique(array_filter(array_map(
            static fn(array $r): string => (string) ($r['str_code'] ?? ''),
            $rows
        ))));
        $strRows = $this->em->createQueryBuilder()
            ->select('s.code AS code, s.source AS source, s.sourceLocale AS source_locale')
            ->from($this->resolveStrClass(), 's')
            ->andWhere('s.code IN (:codes)')
            ->setParameter('codes', $codes)
            ->getQuery()
            ->getArrayResult();

        $sourceByCode = [];
        foreach ($strRows as $s) {
            $sourceByCode[(string) $s['code']] = [
                'source' => (string) $s['source'],
                'sourceLocale' => (string) $s['source_locale'],
            ];
        }

        $byLocale = [];
        $codeByHash = [];
        foreach ($rows as $r) {
            $key = (string) ($r['str_code'] ?? '');
            $loc = (string) ($r['locale'] ?? '');
            if ($key === '' || !isset($sourceByCode[$key])) {
                continue;
            }
            $loc = $loc !== '' ? HashUtil::normalizeLocale($loc) : '';
            $hash = HashUtil::calcSourceKey($sourceByCode[$key]['source'], $sourceByCode[$key]['sourceLocale']);
            $codeByHash[$hash] = $key;
            $byLocale[$noLocaleGrouping ? '' : $loc][] = $hash;
        }

        if ($byLocale === []) {
            $io->success('No untranslated rows after normalization.');
            return Command::SUCCESS;
        }

        // Show what each request is doing while it is in flight. A long pull spends nearly
        // all its wall clock inside HTTP calls, and a bare progress bar that sits still for
        // seconds is indistinguishable from a hung run. %message% carries the JSON-RPC method
        // name (pullTranslations) or the REST route, plus the locale and the counts.
        $progress = new ProgressBar($io, $total);
        $progress->setFormat(" %current%/%max% [%bar%] %percent:3s%%  %message%");
        $progress->setMessage(sprintf('via %s', strtoupper($this->linguaClient->protocol)));

        $this->linguaClient->onCall = static function (LinguaCall $call) use ($progress, $io): void {
            $progress->setMessage($call->describe());
            $progress->display();

            if ($call->phase === LinguaCall::PHASE_ERROR) {
                $progress->clear();
                $io->warning($call->describe());
                $progress->display();
            }
        };

        $progress->start();

        $updated = 0;
        $chunksRequested = 0;

        foreach ($byLocale as $locale => $keys) {
            $locale = trim((string) $locale);
            $locale = $locale !== '' ? HashUtil::normalizeLocale($locale) : '';

            if (!$noLocaleGrouping) {
                $io->newLine(2);
                $io->section('Locale: ' . ($locale !== '' ? $locale : '(none)'));
            }

            foreach (array_chunk($keys, $batch) as $chunk) {
                $chunksRequested++;

                $map = $this->linguaClient->pullBabelByHashes(
                    $chunk,
                    $locale !== '' ? $locale : null,
                    $engine
                );

                if (is_array($map) && $io->isVeryVerbose()) {
                    $io->writeln(sprintf('<comment>pull returned %d/%d</comment>', count($map), count($chunk)));
                }

                if (!is_array($map) || $map === []) {
                    $progress->advance(\count($chunk));
                    continue;
                }

                foreach ($chunk as $hash) {
                    if (!array_key_exists($hash, $map) || !isset($codeByHash[$hash])) {
                        $progress->advance(1);
                        continue;
                    }

                    $strCode = $codeByHash[$hash];
                    $translated = $map[$hash];
                    $translated = is_string($translated) ? $translated : (string) $translated;
                    if ($translated === '') {
                        $progress->advance(1);
                        continue;
                    }

                    // Stamp the real provider engine (from --engine) alongside the text —
                    // whichever engine actually produced this translation, not a placeholder.
                    // Skip the SET when $engine is unknown rather than clobber it with null.
                    $dql = 'UPDATE '.$trClass.' t
                            SET t.text = :text' . ($engine !== null ? ', t.engine = :engine' : '') . '
                            WHERE t.strCode = :strCode AND t.targetLocale = :locale';

                    $q = $this->em->createQuery($dql);
                    $q->setParameter('text', $translated);
                    $q->setParameter('strCode', $strCode);
                    $q->setParameter('locale', $locale);
                    if ($engine !== null) {
                        $q->setParameter('engine', $engine);
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

    private function resolveBabelTrClass(): string
    {
        $appTr  = 'App\\Entity\\StrTranslation';
        if (class_exists($appTr)) {
            return $appTr;
        }
        if (!class_exists(BabelStrTranslation::class)) {
            throw new LogicException('Babel StrTranslation entity not available.');
        }
        return BabelStrTranslation::class;
    }

    private function resolveStrClass(): string
    {
        $appStr = 'App\\Entity\\Str';
        if (class_exists($appStr)) {
            return $appStr;
        }
        if (!class_exists(BabelStr::class)) {
            throw new LogicException('Babel Str entity not available.');
        }
        return BabelStr::class;
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
