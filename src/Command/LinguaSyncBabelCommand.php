<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    'lingua:sync:babel',
    'Umbrella: push sources to Lingua, then pull results back by hash.',
    help: <<<'HELP'
Umbrella sync:
  1) lingua:push:babel
  2) lingua:pull:babel

Options (applied to both phases unless noted):
  --targets=es,fr      Default: framework.translator.enabled_locales
  --engine=libre
  --batch=200 | -b     For push (pull will use the same unless you keep its default)
  --limit=0            For push (pull has its own --limit; you can adjust later)
  --enqueue
  --force
  --show-server
  --strict             Fail if push reports errors or zero acceptance
HELP
)]
final class LinguaSyncBabelCommand
{
    public function __construct(
        private readonly LinguaPushBabelCommand $push,
        private readonly LinguaPullBabelCommand $pull,
        #[Autowire('%kernel.enabled_locales%')] private array $enabledLocales=[],

    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Target locales (comma/space separated). Defaults to enabled_locales.')]
        ?string $targets = null,
        #[Option('Preferred translation engine to pass to Lingua (e.g. "libre", "deepl").')]
        ?string $engine = null,
        #[Option('Batch size for push (also used for pull unless you run pull separately).', shortcut: 'b')]
        int $batch = 200,
        #[Option('Hard cap on number of Str rows to push (0 = no cap).')]
        int $limit = 0,
        #[Option('Set enqueue=true for Lingua (server-side queuing).')]
        bool $enqueue = false,
        #[Option('Set force=true for Lingua (re-translate even if cached, server-dependent).')]
        bool $force = false,
        #[Option('Show raw server payload for each push batch.')]
        bool $showServer = false,
        #[Option('Fail if any push batch reports an error or zero acceptance.')]
        bool $strict = false,
    ): int {
        $io->title('Lingua ⇄ Babel: SYNC (push → pull)');
        $targets ??= $this->enabledLocales;

        // 1) PUSH
        $io->section('Phase 1: Push');
        $code = ($this->push)(
            $io, $targets, $engine, $batch, $limit, $enqueue, $force, $showServer, $strict
        );
        if ($code !== Command::SUCCESS) {
            $io->warning('Push failed/aborted; pull skipped.');
            return $code;
        }

        // 2) PULL (use same targets/engine/batch)
        $io->newLine(2);
        $io->section('Phase 2: Pull');
        return ($this->pull)($io, $targets, $engine, $batch, null);
    }
}
