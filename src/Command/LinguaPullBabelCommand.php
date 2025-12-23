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
    'Pull babel translations by source key into StrTranslation.',
    aliases: ['babel:pull'],
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
        #[Option('Provider engine to pass to Lingua (e.g. "libre", "deepl").')]
        ?string $engine = null,
        #[Option('Batch size (keys per request).', shortcut: 'b')]
        int $batch = 500,
        #[Option('Hard cap on untranslated rows to process (debug).')]
        ?int $limit = null,
        #[Option('Do not group by locale; pull all keys in one stream.')]
        bool $noLocaleGrouping = false,
        #[Option('Update ALL untranslated rows regardless of StrTranslation.engine.')]
        bool $allEngines = false,
    ): int {
        $trClass = $this->resolveBabelTrClass();
        $targetLocales = $this->parseTargets($targets);

        $io->title('Lingua ⇄ Babel: PULL by source key');
        $io->writeln('Target locales: <info>'.($targetLocales ? implode(', ', $targetLocales) : '(all)').'</info>');
        if ($engine) {
            $io->writeln('Provider engine: <info>'.$engine.'</info>');
        }
        $io->writeln('Batch: <info>'.$batch.'</info>');
        $io->writeln('Engine filter: <info>'.($allEngines ? '(none)' : 'engine=babel').'</info>');
        if ($limit !== null) {
            $io->writeln('Global limit: <info>'.$limit.'</info>');
        }

        $qb = $this->em->createQueryBuilder()
            ->select('t.strCode AS str_code, t.targetLocale AS locale')
            ->from($trClass, 't')
            ->andWhere('(t.text IS NULL OR t.text = \'\')')
            ->orderBy('t.strCode', 'ASC');

        if (!$allEngines) {
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

        $rows = $qb->getQuery()->getArrayResult();
        if ($rows === []) {
            $io->success('No untranslated rows match filters.');
            return Command::SUCCESS;
        }

        $total = \count($rows);
        $io->writeln(sprintf('Untranslated rows: <info>%d</info>', $total));

        $byLocale = [];
        foreach ($rows as $r) {
            $key = (string) ($r['str_code'] ?? '');
            $loc = (string) ($r['locale'] ?? '');
            if ($key === '') {
                continue;
            }
            $loc = $loc !== '' ? HashUtil::normalizeLocale($loc) : '';
            $byLocale[$noLocaleGrouping ? '' : $loc][] = $key;
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

                    $dql = 'UPDATE '.$trClass.' t
                            SET t.text = :text
                            WHERE t.strCode = :strCode AND t.targetLocale = :locale'
                        . (!$allEngines ? ' AND t.engine = :stubEngine' : '');

                    $q = $this->em->createQuery($dql);
                    $q->setParameter('text', $translated);
                    $q->setParameter('strCode', $strCode);
                    $q->setParameter('locale', $locale);
                    if (!$allEngines) {
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
