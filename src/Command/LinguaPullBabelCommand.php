<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Survos\LinguaBundle\Dto\BatchRequest;
use Survos\LinguaBundle\Service\ApiPlatformDataFetcher;
use Survos\LinguaBundle\Service\LinguaClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    'lingua:pull:babel',
    'Pull translations by hash into App\\Entity\\StrTranslation (no join).',
    help: <<<'HELP'
FAST PULL (hash lookup, no join)

Selects UNTRANSLATED App\Entity\StrTranslation rows, grouped by target locale, and fetches translations by *hash*.

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
        private readonly HttpClientInterface $httpClient,
//        private readonly ApiPlatformDataFetcher  $apiPlatformDataFetcher, // hmm, we need to pass the base to do this.
        #[Autowire('%kernel.enabled_locales%')] private array $enabledLocales=[],
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
    ): int {
        $strTrClass = 'App\\Entity\\StrTranslation';
        if (!class_exists($strTrClass)) {
            throw new LogicException('App\\Entity\\StrTranslation is required.');
        }

        $io->title('Lingua ⇄ Babel: PULL by hash');

        $targetLocales = $this->parseTargets($targets);
        if (!$targetLocales) {
            $io->error('No target locales resolved (configure translator.enabled_locales or pass --targets).');
            return Command::INVALID;
        }

        $io->writeln('Target locales: <info>'.implode(', ', $targetLocales).'</info>');
        if ($engine) $io->writeln('Engine: <info>'.$engine.'</info>');
        $io->writeln('Batch: <info>'.$batch.'</info>');
        if ($limit !== null) $io->writeln('Global limit: <info>'.$limit.'</info>');

        // prepare the babel translation query where there's no text. We are not using a workflow component here, but we could use a status
        $qb = $this->em->createQueryBuilder()
            ->select('t.hash AS hash, t.locale AS locale')
            ->from($strTrClass, 't')
            ->andWhere('(t.text IS NULL OR t.text = \'\')')
            ->andWhere('t.locale IN (:locales)')
            ->setParameter('locales', $targetLocales)
            ->orderBy('t.hash', 'ASC');

        if ($limit !== null) $qb->setMaxResults($limit);

        $rows = $qb->getQuery()->getArrayResult();
        if (!$rows) {
            $io->success('No untranslated rows match filters.');
            return Command::SUCCESS;
        }

        $byLocale = [];
        $ids = [];
        foreach ($rows as $r) {
            $byLocale[$r['locale']][] = $r['hash'];
            $ids[] = $r['hash'];
        }
        $fetcher = new ApiPlatformDataFetcher($this->httpClient, $this->linguaClient->baseUri);
// Get all data across all pages

        $allTargets = $fetcher->fetchAllDataByIds($ids, '/api/targets', 'key');

        dd($ids, $allTargets);

        $url = $base . '/api/targets?page=1&key%5B%5D=abc&key%5B%5D=def';

        $total = count($rows);
        $io->writeln(sprintf('Untranslated rows: <info>%d</info>', $total));

        $progress = new ProgressBar($io, $total);
        $progress->start();

        $updated = 0;
        $flushes = 0;

        foreach ($byLocale as $locale => $hashes) {
            $io->newLine(2);
            $io->section("Locale: $locale");

            foreach (array_chunk($hashes, $batch) as $chunk) {
                $req = new BatchRequest(
                    texts: $chunk,           // hashes
                    source: 'en',            // irrelevant for hash lookup
                    target: [$locale],
                    html: false,
                    insertNewStrings: false, // pull-only
                    extra: ['lookup' => 'hash'] + ($engine ? ['engine' => $engine] : []),
                    enqueue: false,
                    force: false,
                    engine: $engine
                );

                dd($req);
                $res = $this->linguaClient->requestBatch($req);
                $data = is_array($res) ? $res : (is_array($res->items ?? null) ? [] : []);
                dd($data, $res);

                // Expect flat map[hash => translation]; if server returns items, prefer items
                if (is_array($res->items) && $res->items !== []) {
                    $map = [];
                    foreach ($res->items as $item) {
                        $map[$item->hash] = $item->text;
                    }
                    $data = $map;
                } elseif (is_array($res) && $res !== []) {
                    $data = $res;
                } else {
                    // if server returned the hash map in extra
                    if (isset($res->extra['map']) && is_array($res->extra['map'])) {
                        $data = $res->extra['map'];
                    }
                }

                if ($data) {
                    foreach ($chunk as $hash) {
                        dd($chunk, $data, $hash);
                        if (!array_key_exists($hash, $data)) continue;
                        $translated = (string)$data[$hash];
                        if ($translated === '') continue;

                        $this->em->createQuery(
                            'UPDATE '.$strTrClass.' t SET t.text = :text, t.status = :st WHERE t.hash = :hash AND t.locale = :locale'
                        )
                        ->setParameter('text', $translated)
                        ->setParameter('st', 'translated')
                        ->setParameter('hash', $hash)
                        ->setParameter('locale', $locale)
                        ->execute();

                        $updated++;
                        $progress->advance(1);
                    }
                } else {
                    $progress->advance(count($chunk));
                }

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
