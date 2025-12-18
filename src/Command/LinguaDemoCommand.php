<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Command;

use Survos\Lingua\Contracts\Dto\BatchRequest;
use Survos\LinguaBundle\Service\LinguaClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsCommand('lingua:demo', 'Smoke test the Lingua client against the translation server')]
final class LinguaDemoCommand
{
    public function __construct(
        private readonly LinguaClient $client) {}

    public function __invoke(
        SymfonyStyle                                               $io,
        #[Argument('Text to translate')] ?string                   $text = null,
        #[Option('Target locale(s) (comma-separated).')] ?string   $target = null,
        #[Option('Source locale (or "auto").')] ?string            $source = null,
        #[Option('Engine (e.g. "libre", "deepl").')] ?string       $engine = null,
        #[Option('Transport')] ?string                             $transport = null,
        #[Option('Translate immediately (no queue).')] ?bool       $now = null,
        #[Option('Lookup-only; do not insert new strings.')] ?bool $noTranslate = null,
        #[Option('Enqueue if not using --now.')] ?bool             $enqueue = null,
        #[Option('Force re-dispatch even if cached.', name: 'force')] ?bool       $forceDispatch = null,
    ): int {
        $text       = $text    ?? $io->ask('What text would you like to translate?', 'hello, world');
        $target         = $target      ?? 'es';
        $source       = $source    ?? 'en';
        $engine     = $engine  ?? 'libre';
        $now        = $now     ?? false;
        $noTranslate= $noTranslate ?? false;
        $enqueue    = $enqueue ?? !$now;
        $forceDispatch ??= false;
        $transport ??= 'sync';

        if ($now) {
            $item = $this->client->translateNow($text, $target, $source, ['engine' => $engine], $noTranslate);
            $io->writeln(($item->cached ? '[cached] ' : '[fresh] ').$item->text);
            return Command::SUCCESS;
        }

        $req = new BatchRequest(
            source: $source,
            target: [$target],
            texts: [$text],
            engine: $engine,
            insertNewStrings: true,
            forceDispatch: $forceDispatch,
            transport: $transport
        );

//        $req = new BatchRequest(
//            source: $from,
//            target: [$to],
//            texts: [$text],
//            engine: $engine,
//            insertNewStrings: true,
//            forceDispatch: $force,
//            transport: $transport
//        );
        dump($req);
        $res = $this->client->requestBatch($req);
        dd($res);

        if ($res->error) {
            $io->error($res->error);
            return Command::FAILURE;
        }

        if ($res->queued) {
            $io->success(sprintf('Queued job %s (queued=%d)', $res->jobId, $res->queued));
        } else {
            $io->writeln("Already queued");
        }

        foreach ($res->items as $item) {
            $item = (object)$item;
            foreach ($item->translations as $targetLocale => $translation) {
                $io->writeln(sprintf('[%s→%s]%s', $item->locale, $targetLocale, $translation));
            }
        }

        return Command::SUCCESS;
    }
}
