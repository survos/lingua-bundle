<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Command;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Survos\BabelBundle\Entity\Str as BabelStr;
use Survos\BabelBundle\Entity\StrTranslation as BabelStrTranslation;
use Survos\Lingua\Contracts\Dto\BatchRequest;
use Survos\LinguaBundle\Service\LinguaClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    'lingua:push',
    'Push translation requests to Lingua. Default: push only untranslated StrTranslation stubs (grouped by locale).',
    aliases: ['babel:push'],
    help: <<<'HELP'
LINGUA PUSH (Babel-aware)

Default mode (recommended): reads StrTranslation rows with missing text and pushes
translation requests grouped by (source-locale, target-locale). This prevents "extra locales"
from leaking in because targets come from existing TR stubs.

Legacy mode: push ALL Str originals to the specified target locales.

Options:
  --mode=tr     Default. Push from untranslated TR stubs (grouped by locale).
  --mode=str    Legacy. Push all Str originals to targets.
  --targets     Optional: restrict target locales (comma/space separated).
  --batch / -b  Batch size per request (default 200).
HELP
)]
final class LinguaPushBabelCommand
{
    private const string STUB_ENGINE = 'babel';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LinguaClient $linguaClient,
        #[Autowire('%kernel.enabled_locales%')] private readonly array $enabledLocales = [],
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Mode: "tr" (default, from untranslated StrTranslation) or "str" (legacy, all Str).')]
        string $mode = 'tr',
        #[Option('Target locales filter (comma/space separated). In "tr" mode this restricts which locales are pushed.')]
        ?string $targets = null,
        #[Option('Preferred translation engine (e.g. "libre", "deepl").')]
        ?string $engine = null,
        #[Option('Batch size for Lingua requests.', shortcut: 'b')]
        int $batch = 200,
        #[Option('Hard cap on number of rows considered (0 = no cap).')]
        int $limit = 0,
        #[Option('Queue work on server (alias for --transport=async when not provided).')]
        bool $enqueue = false,
        #[Option('Force dispatch even if cached/already translated (server-dependent).')]
        bool $force = false,
        #[Option('Messenger transport override (e.g. "async").')]
        ?string $transport = null,
        #[Option('Show raw server payload for each batch.')]
        bool $showServer = false,
        #[Option('Fail if any batch reports an error or zero accepted items.')]
        bool $strict = false,

    ): int {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['tr', 'str'], true)) {
            $io->error('Invalid --mode. Use "tr" or "str".');
            return Command::INVALID;
        }

        if ($enqueue && $transport === null) {
            $transport = 'async';
        }

        $targetFilter = $this->parseTargets($targets);

        $io->title('Lingua PUSH: ' . ($this->linguaClient->baseUri ?? '(no baseUri)'));
        $io->writeln(sprintf('Mode: <info>%s</info>', $mode));
        $io->writeln(sprintf('Batch: <info>%d</info>', $batch));
        if ($limit > 0) {
            $io->writeln(sprintf('Limit: <info>%d</info>', $limit));
        }
        $io->writeln('Transport: <info>'.($transport ?? '(none)').'</info>  Force: <info>'.($force ? 'true' : 'false').'</info>');
        if ($engine) {
            $io->writeln('Engine: <info>'.$engine.'</info>');
        }
        if ($targetFilter !== []) {
            $io->writeln('Target filter: <info>'.implode(', ', $targetFilter).'</info>');
        }
        if ($strict) {
            $io->writeln('<comment>Strict mode: command will fail on server errors or zero acceptance.</comment>');
        }

        return $mode === 'tr'
            ? $this->pushFromTrStubs($io, $targetFilter, $engine, $batch, $limit, $transport, $force, $showServer, $strict)
            : $this->pushAllStr($io, $targetFilter, $engine, $batch, $limit, $transport, $force, $showServer, $strict);
    }

    /**
     * Default mode: push from untranslated TR stubs.
     * Uses Babel bundle entities by default, falls back to App overrides if present.
     */
    private function pushFromTrStubs(
        SymfonyStyle $io,
        array $targetFilter,
        ?string $engine,
        int $batch,
        int $limit,
        ?string $transport,
        bool $forceDispatch,
        bool $showServer,
        bool $strict
    ): int {
        [$strClass, $trClass] = $this->resolveBabelEntityClasses();

        $em = $this->em;
        $conn = $em->getConnection();

        $mStr = $em->getClassMetadata($strClass);
        $mTr  = $em->getClassMetadata($trClass);

        $tStr = $mStr->getTableName();
        $tTr  = $mTr->getTableName();

        $strRepo = $em->getRepository($strClass);

        // New schema field names
        $cStrCode = $mStr->getColumnName('code');
        $cSource  = $mStr->getColumnName('source');
        $cSrcLoc  = $mStr->getColumnName('sourceLocale');

        $cTrStrCode = $mTr->getColumnName('strCode');
        $cTrLoc     = $mTr->getColumnName('targetLocale');
        $cTrText    = $mTr->getColumnName('text');
        $cTrEngine  = $mTr->getColumnName('engine');

        $pf = $conn->getDatabasePlatform();
        $q = static fn(string $id) => $pf->quoteIdentifier($id);

        $where = sprintf('(%1$s IS NULL OR %1$s = \'\')', $q($cTrText));
        $params = [];
//        $params = [
//            'stubEngine' => self::STUB_ENGINE,
//        ];
        $types = [];

        // Only push from Babel-created stubs
//        $where .= sprintf(' AND %s = :stubEngine', $q($cTrEngine));

        if ($targetFilter !== []) {
            $where .= sprintf(' AND %s IN (:targets)', $q($cTrLoc));
            $params['targets'] = $targetFilter;
            $types['targets']  = ArrayParameterType::STRING;
        }

        $sql = sprintf(
            'SELECT %1$s.%2$s AS target_locale,
                    %3$s.%4$s AS source_locale,
                    %3$s.%5$s AS original
               FROM %1$s
               JOIN %3$s ON %1$s.%6$s = %3$s.%7$s
              WHERE %8$s
              ORDER BY target_locale, source_locale, %3$s.%7$s',
            $q($tTr),
            $q($cTrLoc),
            $q($tStr),
            $q($cSrcLoc),
            $q($cSource),
            $q($cTrStrCode),
            $q($cStrCode),
            $where
        );

        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $rows = $conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
        if ($rows === []) {
            $io->success('No untranslated StrTranslation stubs found (nothing to push).');
            return Command::SUCCESS;
        }

        // Group by (source_locale, target_locale)
        $groups = []; // [$src][$target] = list<string originals>
        foreach ($rows as $r) {
            $tgt = trim((string)($r['target_locale'] ?? ''));
            $src = trim((string)($r['source_locale'] ?? ''));
            $txt = (string)($r['original'] ?? '');
            if ($tgt === '' || $src === '' || $txt === '') {
                continue;
            }
            $groups[$src][$tgt][] = $txt;
        }

        $totalTexts = 0;
        foreach ($groups as $byTarget) {
            foreach ($byTarget as $texts) {
                $totalTexts += count($texts);
            }
        }

        $io->writeln(sprintf(
            'Found <info>%d</info> untranslated texts across <info>%d</info> (src,target) groups.',
            $totalTexts,
            $this->countGroups($groups)
        ));

        $batches = 0;
        $totalAccepted = 0;
        $totalQueued   = 0;
        $totalMissing  = 0;
        $hadError      = false;

        foreach ($this->sortGroupsByTargetThenSource($groups) as [$src, $tgt, $texts]) {
            $io->section(sprintf('Target %s (from %s) — %d texts', $tgt, $src, count($texts)));

            foreach (array_chunk($texts, $batch) as $chunk) {
                $r = $this->sendBatch(
                    $io,
                    $src,
                    [$tgt],
                    $chunk,
                    $engine,
                    $transport,
                    $forceDispatch,
                    $showServer
                );
                $batches++;

                $totalAccepted += $r['accepted'];
                $totalQueued   += $r['queued'];
                $totalMissing  += $r['missing'];
                $hadError      = $hadError || $r['error'] !== null;
            }
        }

        $io->newLine();

        if ($hadError || ($strict && ($totalAccepted + $totalQueued) === 0)) {
            $io->error(sprintf(
                'Push summary: batches=%d, texts=%d, accepted=%d, queued=%d, missing=%d.',
                $batches, $totalTexts, $totalAccepted, $totalQueued, $totalMissing
            ));
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Push summary: batches=%d, texts=%d, accepted=%d, queued=%d, missing=%d.',
            $batches, $totalTexts, $totalAccepted, $totalQueued, $totalMissing
        ));

        $io->writeln('Then run `lingua:pull` / `lingua:sync:*` to harvest completed translations.');
        return Command::SUCCESS;
    }

    private function pushAllStr(
        SymfonyStyle $io,
        array $targetLocales,
        ?string $engine,
        int $batch,
        int $limit,
        ?string $transport,
        bool $forceDispatch,
        bool $showServer,
        bool $strict
    ): int {
        [$strClass] = $this->resolveBabelEntityClasses();

        if ($targetLocales === []) {
            $targetLocales = array_values(array_unique(array_filter(array_map('trim', $this->enabledLocales))));
        }
        if ($targetLocales === []) {
            $io->error('No target locales resolved. Configure enabled_locales or pass --targets=...');
            return Command::INVALID;
        }

        $io->writeln('Legacy STR mode targets: <info>'.implode(', ', $targetLocales).'</info>');

        $qb = $this->em->createQueryBuilder()
            ->select('s')
            ->from($strClass, 's')
            ->orderBy('s.code', 'ASC');

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        $iter = $qb->getQuery()->toIterable();

        /** @var array<string, list<string>> $textsBySrc */
        $textsBySrc = [];
        /** @var array<string, int> $countsBySrc */
        $countsBySrc = [];

        $total = 0;
        $batches = 0;

        $totalAccepted = 0;
        $totalQueued   = 0;
        $totalMissing  = 0;

        $hadError = false;

        foreach ($iter as $str) {
            /** @var object{source:string,sourceLocale?:string} $str */
            $original = (string) ($str->source ?? '');
            if ($original === '') {
                continue;
            }

            $srcLocale = (string) ($str->sourceLocale ?? 'en');
            $textsBySrc[$srcLocale][] = $original;
            $countsBySrc[$srcLocale] = ($countsBySrc[$srcLocale] ?? 0) + 1;
            $total++;

            if ($countsBySrc[$srcLocale] >= $batch) {
                $r = $this->sendBatch($io, $srcLocale, $targetLocales, $textsBySrc[$srcLocale], $engine, $transport, $forceDispatch, $showServer);
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
            if ($texts === []) {
                continue;
            }

            $r = $this->sendBatch($io, $srcLocale, $targetLocales, $texts, $engine, $transport, $forceDispatch, $showServer);
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
        $io->writeln('Then run `lingua:pull` / `lingua:sync:*` to harvest completed translations.');
        return Command::SUCCESS;
    }

    /**
     * Prefer App overrides if present; otherwise use Babel bundle entities.
     *
     * @return array{0:class-string,1:class-string}
     */
    private function resolveBabelEntityClasses(): array
    {
        $appStr = 'App\\Entity\\Str';
        $appTr  = 'App\\Entity\\StrTranslation';

        if (class_exists($appStr) && class_exists($appTr)) {
            return [$appStr, $appTr];
        }

        return [BabelStr::class, BabelStrTranslation::class];
    }

    /** @return list<string> */
    private function parseTargets(?string $targets): array
    {
        if ($targets === null || trim($targets) === '') {
            return [];
        }
        $parts = preg_split('/[,\s]+/', $targets) ?: [];
        return array_values(array_unique(array_filter(array_map('trim', $parts))));
    }

    /**
     * @param array<string, array<string, list<string>>> $groups
     */
    private function countGroups(array $groups): int
    {
        $n = 0;
        foreach ($groups as $byTarget) {
            $n += count($byTarget);
        }
        return $n;
    }

    /**
     * @param array<string, array<string, list<string>>> $groups
     * @return list<array{0:string,1:string,2:list<string>}>
     */
    private function sortGroupsByTargetThenSource(array $groups): array
    {
        $flat = [];
        foreach ($groups as $src => $byTarget) {
            foreach ($byTarget as $tgt => $texts) {
                $flat[] = [$src, $tgt, $texts];
            }
        }

        usort($flat, static function(array $a, array $b): int {
            $cmp = strcmp($a[1], $b[1]);
            return $cmp !== 0 ? $cmp : strcmp($a[0], $b[0]);
        });

        return $flat;
    }

    /**
     * @param list<string> $targets
     * @param list<string> $texts
     * @return array{accepted:int, queued:int, missing:int, error:?string}
     */
    private function sendBatch(
        SymfonyStyle $io,
        string $source,
        array $targets,
        array $texts,
        ?string $engine,
        ?string $transport,
        bool $forceDispatch,
        bool $showServer
    ): array {
        $count = count($texts);
        if ($count === 0) {
            return ['accepted' => 0, 'queued' => 0, 'missing' => 0, 'error' => null];
        }

//        $io->writeln('<comment>Outgoing request:</comment>');
//        $io->writeln(json_encode([
//            'source' => $source,
//            'target' => $targets,
//            'texts'  => $texts,
//            'engine' => $engine,
//            'transport' => $transport,
//            'forceDispatch' => $forceDispatch,
//        ], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

        $io->writeln(sprintf(
            'Sending batch: source=<info>%s</info>, targets=<info>%s</info>, count=<info>%d</info>',
            $source, implode(',', $targets), $count
        ));

        $req = new BatchRequest(
            source: $source,
            target: $targets,
            texts: $texts,
            engine: $engine,
            insertNewStrings: true,
            forceDispatch: $forceDispatch,
            transport: $transport
        );

        $accepted = 0;
        $queued   = 0;
        $missing  = 0;
        $errorMsg = null;

        try {
            $raw = $this->linguaClient->requestBatch($req);
            [$resp, $top] = $this->normalizeServerResult($raw);

            $queued  = $this->intish($resp['queued'] ?? $top['queued'] ?? 0);
            $missing = $this->countish($resp['missing'] ?? $top['missing'] ?? null);
            $accepted = $this->countish($resp['items'] ?? $top['items'] ?? $resp['sources'] ?? $top['sources'] ?? null);

            $errVal = $resp['error'] ?? $top['error'] ?? null;
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

    /** @return array{0: array, 1: array} */
    private function normalizeServerResult(mixed $raw): array
    {
        if ($raw instanceof \JsonSerializable) {
            $resp = (array) $raw->jsonSerialize();
            return [$resp, $resp];
        }
        if (is_object($raw)) {
            if (method_exists($raw, 'toArray')) {
                $resp = (array) $raw->toArray();
                return [$resp, $resp];
            }
            $resp = get_object_vars($raw);
            return [$resp, $resp];
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                if (isset($decoded['response']) && is_array($decoded['response'])) {
                    return [$decoded['response'], $decoded];
                }
                return [$decoded, $decoded];
            }
            return [[], ['raw' => $raw]];
        }
        if (is_array($raw)) {
            if (isset($raw['response']) && is_array($raw['response'])) {
                return [$raw['response'], $raw];
            }
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
