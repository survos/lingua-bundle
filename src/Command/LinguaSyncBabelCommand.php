<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Command;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

#[AsCommand(
    'lingua:sync:babel',
    'Umbrella: push missing translations to Lingua, then poll pull until completion.',
    help: <<<'HELP'
Umbrella sync:
  1) lingua:push   (default mode: from untranslated TR stubs grouped by locale)
  2) lingua:pull   (repeat as needed)
  3) optional: run meili:populate when completion threshold is reached

Polling:
  --poll=10        Sleep 10s between pull attempts (0 disables polling)
  --max-polls=30   Maximum pull attempts
  --stop-when=100  Stop when >= this percent translated for each target locale

Targets:
  --targets=fr,es,en  Optional filter. Default is enabled_locales (when set) otherwise "whatever stubs exist".

Notes:
- Completion is computed locally from TR rows (text IS NULL/empty counts as missing).
HELP
)]
final class LinguaSyncBabelCommand
{
    public function __construct(
        private readonly LinguaPushBabelCommand $push,
        private readonly LinguaPullBabelCommand $pull,
        private readonly Connection $connection,
        #[Autowire('%kernel.enabled_locales%')] private readonly array $enabledLocales = [],
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir = '.',
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Target locales (comma/space separated). Default: enabled_locales (if configured).')]
        ?string $targets = null,

        #[Option('Preferred translation engine to pass to Lingua (e.g. "libre", "deepl").')]
        ?string $engine = null,

        #[Option('Batch size for push and pull.', shortcut: 'b')]
        int $batch = 200,

        #[Option('Hard cap on number of rows to push (0 = no cap).')]
        int $limit = 0,

        #[Option('Set enqueue=true for Lingua (server-side queuing).')]
        bool $enqueue = false,

        #[Option('Set force=true for Lingua (re-translate even if cached, server-dependent).')]
        bool $force = false,

        #[Option('Show raw server payload for each push batch.')]
        bool $showServer = false,

        #[Option('Fail if any push batch reports an error or zero acceptance.')]
        bool $strict = false,

        #[Option('Poll interval in seconds (0 = no polling; run pull once).')]
        int $poll = 0,

        #[Option('Maximum number of pull attempts when polling.')]
        int $maxPolls = 20,

        #[Option('Stop when translation percent for each target locale is >= this threshold.')]
        float $stopWhen = 100.0,

        #[Option('If set, run this command when threshold is met (e.g. "bin/console meili:populate").')]
        ?string $meiliPopulate = null,

        #[Option('If using meili:populate threshold option, pass it here (e.g. 80).')]
        ?int $translationThreshold = null,
    ): int {
        $io->title('Lingua ⇄ Babel: SYNC (push → pull → poll)');

        $targetLocales = $this->resolveTargets($targets);
        if ($targetLocales === []) {
            $io->warning('No targets resolved. Pass --targets=fr,es,... or configure kernel.enabled_locales.');
        } else {
            $io->writeln('Targets: <info>'.implode(', ', $targetLocales).'</info>');
        }

        // 1) PUSH (default lingua:push is already TR-driven now)
        $io->section('Phase 1: Push');
        $code = ($this->push)(
            $io, // SymfonyStyle
            targets: $targets,
            engine: $engine,
            batch: $batch,
            limit: $limit,
            enqueue: $enqueue,
            force: $force,
            transport: null,
            showServer: $showServer,
            strict: $strict,
            mode: 'tr'
        );

        if ($code !== Command::SUCCESS) {
            $io->warning('Push failed/aborted; pull skipped.');
            return $code;
        }

        // 2) PULL once, then optionally poll
        $attempt = 0;
        do {
            $attempt++;

            $io->newLine(2);
            $io->section(sprintf('Phase 2: Pull (attempt %d/%d)', $attempt, max(1, $maxPolls)));

            $pullCode = ($this->pull)(
                $io,
                targets: $targets,
                engine: $engine,
                batch: $batch,
                limit: null
            );

            if ($pullCode !== Command::SUCCESS) {
                $io->warning('Pull failed; aborting sync.');
                return $pullCode;
            }

            // Completion check
            $stats = $this->computeCompletion($targetLocales);
            $this->renderCompletion($io, $stats);

            $ok = $this->meetsThreshold($stats, $stopWhen);
            if ($ok) {
                $io->success(sprintf('Reached translation threshold: %.1f%%', $stopWhen));

                if ($meiliPopulate) {
                    $run = $this->runMeiliPopulate($io, $meiliPopulate, $translationThreshold);
                    if ($run !== Command::SUCCESS) {
                        return $run;
                    }
                }

                return Command::SUCCESS;
            }

            if ($poll <= 0) {
                $io->note('Polling disabled (--poll=0). Sync stopping after one pull.');
                return Command::SUCCESS;
            }

            if ($attempt >= $maxPolls) {
                $io->warning('Max polls reached; stopping.');
                return Command::SUCCESS;
            }

            $io->writeln(sprintf('Sleeping %ds before next pull...', $poll));
            sleep($poll);

        } while (true);
    }

    /** @return list<string> */
    private function resolveTargets(?string $targets): array
    {
        if ($targets !== null && trim($targets) !== '') {
            $parts = preg_split('/[,\s]+/', $targets) ?: [];
            $parts = array_values(array_unique(array_filter(array_map('trim', $parts))));
            return $parts;
        }

        // If enabled_locales set, use it; otherwise return empty => "whatever is in the DB"
        $enabled = array_values(array_unique(array_filter(array_map('trim', $this->enabledLocales))));
        return $enabled;
    }

    /**
     * Compute completion per target locale from TR rows.
     *
     * Assumes table is `str_translation` with columns: (locale, text).
     * If your table is `tr`, adjust $table accordingly.
     *
     * @param list<string> $targets
     * @return array<string, array{total:int, translated:int, missing:int, pct:float}>
     */
    private function computeCompletion(array $targets): array
    {
        // If no explicit targets, compute for all locales present in the table
        if ($targets === []) {
            $targets = array_values(array_filter(array_map('strval', $this->connection->fetchFirstColumn(
                'SELECT DISTINCT locale FROM str_translation ORDER BY locale'
            ))));
        }

        if ($targets === []) {
            return [];
        }

        $rows = $this->connection->executeQuery(
            "SELECT locale,
                    COUNT(*) AS total,
                    SUM(CASE WHEN text IS NOT NULL AND text <> '' THEN 1 ELSE 0 END) AS translated
               FROM str_translation
              WHERE locale IN (?)
              GROUP BY locale",
            [$targets],
            [ArrayParameterType::STRING]
        )->fetchAllAssociative();

        $out = [];
        foreach ($rows as $r) {
            $loc = (string)($r['locale'] ?? '');
            if ($loc === '') continue;

            $total = (int)($r['total'] ?? 0);
            $translated = (int)($r['translated'] ?? 0);
            $missing = max(0, $total - $translated);
            $pct = $total > 0 ? round(($translated / $total) * 100, 1) : 0.0;

            $out[$loc] = compact('total', 'translated', 'missing', 'pct');
        }

        // Ensure all requested targets are present even if total=0
        foreach ($targets as $loc) {
            if (!isset($out[$loc])) {
                $out[$loc] = ['total' => 0, 'translated' => 0, 'missing' => 0, 'pct' => 0.0];
            }
        }

        ksort($out);
        return $out;
    }

    /** @param array<string, array{total:int, translated:int, missing:int, pct:float}> $stats */
    private function meetsThreshold(array $stats, float $threshold): bool
    {
        if ($stats === []) {
            return false;
        }

        foreach ($stats as $loc => $s) {
            if (($s['total'] ?? 0) === 0) {
                continue;
            }
            if (($s['pct'] ?? 0.0) + 0.0001 < $threshold) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, array{total:int, translated:int, missing:int, pct:float}> $stats */
    private function renderCompletion(SymfonyStyle $io, array $stats): void
    {
        if ($stats === []) {
            $io->warning('No TR rows found to compute completion.');
            return;
        }

        $rows = [];
        foreach ($stats as $loc => $s) {
            $rows[] = [
                $loc,
                number_format($s['translated']),
                number_format($s['total']),
                $s['pct'] . '%',
                number_format($s['missing']),
            ];
        }

        $io->table(['Target', 'Translated', 'Total', '% Complete', 'Missing'], $rows);
    }

    private function runMeiliPopulate(SymfonyStyle $io, string $cmd, ?int $threshold): int
    {
        $cmd = trim($cmd);
        if ($cmd === '') {
            return Command::SUCCESS;
        }

        $parts = preg_split('/\s+/', $cmd) ?: [];
        if ($threshold !== null) {
            $parts[] = '--translation-threshold=' . (int)$threshold;
        }

        $io->section('Running Meili populate');
        $io->writeln('<info>' . implode(' ', $parts) . '</info>');

        $p = new Process($parts, $this->projectDir);
        $p->setTimeout(null);
        $p->run(function($type, $buffer) use ($io) {
            $io->write($buffer);
        });

        return $p->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
    }
}
