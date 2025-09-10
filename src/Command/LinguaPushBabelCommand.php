<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Survos\LinguaBundle\Dto\BatchRequest;
use Survos\LinguaBundle\Service\LinguaClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    'lingua:push:babel',
    'Push ALL App\\Entity\\Str originals to Lingua for the specified target locales.',
    help: <<<'HELP'
PUSH ALL SOURCE STRINGS TO LINGUA (public properties; no StrTranslation join)

Reads App\Entity\Str rows and pushes their source `original` text to Lingua for translation to the target locales.

Defaults:
  --targets        Defaults to framework.translator.enabled_locales
  --batch / -b     200
HELP
)]
final class LinguaPushBabelCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LinguaClient $linguaClient,
        #[Autowire('%kernel.enabled_locales%')] private array $enabledLocales=[],
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Target locales (comma/space separated). Defaults to enabled_locales.')]
        ?string $targets = null,
        #[Option('Preferred translation engine to pass to Lingua (e.g. "libre", "deepl").')]
        ?string $engine = null,
        #[Option('Batch size for Lingua requests.', shortcut: 'b')]
        int $batch = 200,
        #[Option('Hard cap on number of Str rows to push (0 = no cap).')]
        int $limit = 0,
        #[Option('Set enqueue=true for Lingua (server-side queuing).')]
        bool $enqueue = false,
        #[Option('Set force=true for Lingua (re-translate even if cached, server-dependent).')]
        bool $force = false,
        #[Option('Show raw server payload for each batch.')]
        bool $showServer = false,
        #[Option('Fail if any batch reports an error or zero accepted items.')]
        bool $strict = false,
    ): int {
        $strClass = 'App\\Entity\\Str';
        if (!class_exists($strClass)) {
            throw new LogicException('App\\Entity\\Str is required. Ensure your concrete Str entity exists.');
        }

        $targetLocales = $this->parseTargets($targets);
        if (!$targetLocales) {
            $io->error('No target locales resolved. Configure translator.enabled_locales or pass --targets=...');
            return Command::INVALID;
        }

        $io->title('Lingua PUSH: send ALL source strings to Lingua');
        $io->writeln('Targets: <info>'.implode(', ', $targetLocales).'</info>');
        if ($engine) $io->writeln('Engine: <info>'.$engine.'</info>');
        $io->writeln('Batch: <info>'.$batch.'</info>');
        if ($limit > 0) $io->writeln('Limit: <info>'.$limit.'</info>');
        $io->writeln('enqueue: <info>'.($enqueue ? 'true' : 'false').'</info>  force: <info>'.($force ? 'true' : 'false').'</info>');
        if ($strict) $io->writeln('<comment>Strict mode: command will fail on server errors or zero acceptance.</comment>');

        $qb = $this->em->createQueryBuilder()
            ->select('s')
            ->from($strClass, 's')
            ->orderBy('s.hash', 'ASC');

        if ($limit > 0) $qb->setMaxResults($limit);

        $iter = $qb->getQuery()->toIterable();

        $textsBySrc = [];
        $countsBySrc = [];
        $total = 0;

        $batches = 0;
        $totalAccepted = 0;   // accepted + queued
        $totalQueued = 0;
        $totalMissing = 0;
        $hadError = false;

        foreach ($iter as $str) {
            /** @var object{original:string,srcLocale:string} $str */
            $original = (string) $str->original;
            if ($original === '') continue;

            $srcLocale = (string) ($str->srcLocale ?? 'en');

            $textsBySrc[$srcLocale][] = $original;
            $countsBySrc[$srcLocale] = ($countsBySrc[$srcLocale] ?? 0) + 1;
            $total++;

            if ($countsBySrc[$srcLocale] >= $batch) {
                $r = $this->sendBatch($io, $srcLocale, $targetLocales, $textsBySrc[$srcLocale], $engine, $enqueue, $force, $showServer);
                $batches++;

                $totalAccepted += $r['accepted'];
                $totalQueued   += $r['queued'];
                $totalMissing  += $r['missing'];
                $hadError      = $hadError || $r['error'] !== null;

                $textsBySrc[$srcLocale] = [];
                $countsBySrc[$srcLocale] = 0;
            }
        }

        foreach ($textsBySrc as $srcLocale => $texts) {
            if (!$texts) continue;
            $r = $this->sendBatch($io, $srcLocale, $targetLocales, $texts, $engine, $enqueue, $force, $showServer);
            $batches++;

            $totalAccepted += $r['accepted'];
            $totalQueued   += $r['queued'];
            $totalMissing  += $r['missing'];
            $hadError      = $hadError || $r['error'] !== null;
        }

        $io->newLine();
        if ($hadError || ($strict && ($totalAccepted + $totalQueued) === 0)) {
            $io->error(sprintf(
                'Push summary: batches=%d, texts=%d, accepted=%d, queued=%d, missing=%d.',
                $batches, $total, $totalAccepted, $totalQueued, $totalMissing
            ));
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Push summary: batches=%d, texts=%d, accepted=%d, queued=%d, missing=%d.',
            $batches, $total, $totalAccepted, $totalQueued, $totalMissing
        ));
        $io->writeln('Then run `lingua:sync:babel` to harvest completed translations.');
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

    /**
     * Send a single batch and print a concise outcome line.
     * Returns ['accepted'=>int,'queued'=>int,'missing'=>int,'error'=>?string]
     */
    private function sendBatch(
        SymfonyStyle $io,
        string $source,
        array $targets,
        array $texts,
        ?string $engine,
        bool $enqueue,
        bool $force,
        bool $showServer
    ): array {
        $count = count($texts);
        if ($count === 0) {
            return ['accepted' => 0, 'queued' => 0, 'missing' => 0, 'error' => null];
        }

        $io->writeln(sprintf(
            'Sending batch: source=<info>%s</info>, targets=<info>%s</info>, count=<info>%d</info>',
            $source, implode(',', $targets), $count
        ));

        $req = new BatchRequest(
            texts: $texts,
            source: $source,
            target: $targets,
            html: false,
            insertNewStrings: true,
            extra: array_filter(['engine' => $engine]),
            enqueue: $enqueue,
            force: $force,
            engine: $engine
        );

        $accepted = 0;
        $queued   = 0;
        $missing  = 0;
        $errorMsg = null;

        try {
            $raw = $this->linguaClient->requestBatch($req);
            [$resp, $top] = $this->normalizeServerResult($raw);

            $queued   = $this->intish($resp['queued'] ?? $top['queued'] ?? 0);
            $accepted = $this->countish($resp['sources'] ?? $top['sources'] ?? $resp['accepted'] ?? $top['accepted'] ?? null);
            $missing  = $this->countish($resp['missing'] ?? $top['missing'] ?? null);

            $errVal   = $resp['error'] ?? $top['error'] ?? null;
            if ($errVal !== null && $errVal !== '') {
                $errorMsg = $this->stringify($errVal);
            }

            if ($showServer) {
                $io->writeln('<comment>Server payload:</comment>');
                $io->writeln(json_encode($top ?: $resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        } catch (\Throwable $e) {
            $errorMsg = $e->getMessage();
        }

        if ($errorMsg) {
            $io->error(sprintf('Batch result: accepted=%d, queued=%d, missing=%d, error=%s', $accepted, $queued, $missing, $errorMsg));
        } else {
            $io->writeln(sprintf('Batch result: accepted=%d, queued=%d, missing=%d', $accepted, $queued, $missing));
        }

        return ['accepted' => $accepted, 'queued' => $queued, 'missing' => $missing, 'error' => $errorMsg];
    }

    /** @return array{0: array, 1: array} [$resp,$top] */
    private function normalizeServerResult(mixed $raw): array
    {
        if ($raw instanceof \JsonSerializable) {
            $resp = (array) $raw->jsonSerialize(); return [$resp, $resp];
        }
        if (is_object($raw)) {
            if (method_exists($raw, 'toArray')) {
                $resp = (array) $raw->toArray(); return [$resp, $resp];
            }
            $resp = get_object_vars($raw); return [$resp, $resp];
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                if (isset($decoded['response']) && is_array($decoded['response'])) return [$decoded['response'], $decoded];
                return [$decoded, $decoded];
            }
            return [[], ['raw' => $raw]];
        }
        if (is_array($raw)) {
            if (isset($raw['response']) && is_array($raw['response'])) return [$raw['response'], $raw];
            return [$raw, $raw];
        }
        return [[], []];
    }

    private function intish(mixed $v): int
    {
        if (is_int($v)) return $v;
        if (is_numeric($v)) return (int) $v;
        if (is_array($v) || $v instanceof \Countable) return (int) count($v);
        return 0;
    }

    private function countish(mixed $v): int
    {
        if (is_int($v)) return $v;
        if (is_array($v) || $v instanceof \Countable) return (int) count($v);
        return 0;
    }

    private function stringify(mixed $v): string
    {
        if (is_string($v)) return $v;
        if (is_scalar($v)) return (string) $v;
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: get_debug_type($v);
    }
}
