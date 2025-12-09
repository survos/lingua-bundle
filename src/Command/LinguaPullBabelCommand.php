<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
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
    help: <<<'HELP'
FAST PULL (hash lookup, no join)

Selects UNTRANSLATED App\Entity\StrTranslation rows, grouped by target locale,
and fetches translations by *hash* from the Lingua server.

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
        #[Option('Target locales (comma/space separated). Defaults to all')]
        ?string $targets = null,
        #[Option('Preferred translation engine to pass to Lingua (e.g. "libre", "deepl").')]
        ?string $engine = null,
        #[Option('Batch size (hashes per request).', shortcut: 'b')]
        int $batch = 500,
        #[Option('Hard cap on untranslated rows to process (debug).')]
        ?int $limit = null,
    ): int {
        $strTrClass = 'App\\Entity\\StrTranslation';
        if (!class_exists($strTrClass)) {
            throw new LogicException('App\\Entity\\StrTranslation is required.');
        }

        $io->title('Lingua ⇄ Babel: PULL by hash');

        $targetLocales = $this->parseTargets($targets);
//        if (!$targetLocales) {
//            $io->error('No target locales resolved (configure translator.enabled_locales or pass --targets).');
//            return Command::INVALID;
//        }

        $io->writeln('Target locales: <info>'.implode(', ', $targetLocales).'</info>');
        if ($engine) {
            $io->writeln('Engine: <info>'.$engine.'</info>');
        }
        $io->writeln('Batch: <info>'.$batch.'</info>');
        if ($limit !== null) {
            $io->writeln('Global limit: <info>'.$limit.'</info>');
        }

        // 1) Find untranslated StrTranslation rows
        $qb = $this->em->createQueryBuilder()
            ->select('t.hash AS hash, t.locale AS locale')
            ->from($strTrClass, 't')
            ->andWhere('(t.text IS NULL OR t.text = \'\')')
            ->orderBy('t.hash', 'ASC');
        if ($targetLocales) {
            $qb->andWhere('t.locale IN (:locales)')
                ->setParameter('locales', $targetLocales);
        }

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        $rows = $qb->getQuery()->getArrayResult();
        if (!$rows) {
            $io->success('No untranslated rows match filters.');
            return Command::SUCCESS;
        }

        $total = \count($rows);
        $io->writeln(sprintf('Untranslated rows: <info>%d</info>', $total));

        // Group hashes by locale?
        $byLocale = [];
        $hashes = [];
        foreach ($rows as $r) {
//            $byLocale[$r['locale']][] = $r['hash'];
            $byLocale[''][] = $r['hash'];
//            $hashes[] = $r['hash'];
        }


        $progress = new ProgressBar($io, $total);
        $progress->start();

        $updated = 0;
        $flushes = 0;

        foreach ($byLocale as $locale => $hashes) {
            $io->newLine(2);
            $io->section("Locale: $locale");

            foreach (array_chunk($hashes, $batch) as $chunk) {
                // 2) Ask Lingua for translations for this chunk of hashes
                $map = $this->linguaClient->pullBabelByHashes($chunk, $locale, $engine);
                // $map is expected to be: [hash => text]

                if ($map === [] || !\is_array($map)) {
                    // Nothing translated for this chunk – mark as "seen" in progress
                    $progress->advance(\count($chunk));
                    continue;
                }

                // 3) Update rows in-place via DQL UPDATE
                foreach ($chunk as $hash) {
                    if (!array_key_exists($hash, $map)) {
                        $progress->advance(1);
                        continue;
                    }

                    $translated = (string) $map[$hash];
                    if ($translated === '') {
                        $progress->advance(1);
                        continue;
                    }

                    $this->em->createQuery(
                        'UPDATE '.$strTrClass.' t
                         SET t.text = :text, t.status = :st
                         WHERE t.hash = :hash
                         '
                    )
                        ->setParameter('text', $translated)
                        ->setParameter('st', 'translated')
                        ->setParameter('hash', $hash)
                        ->execute();

                    $updated++;
                    $progress->advance(1);
                }

                // keep memory usage low
                $this->em->clear();
                $flushes++;
            }
        }

        $progress->finish();
        $io->newLine(2);
        $io->success(sprintf('Updated translations: %d (flushes: %d)', $updated, $flushes));

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
