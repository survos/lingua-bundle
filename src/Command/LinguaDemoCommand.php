<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Command;

use Survos\LinguaBundle\Dto\BatchRequest;
use Survos\LinguaBundle\Service\LinguaClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('lingua:demo', 'Smoke test the Lingua client against the translation server')]
final class LinguaDemoCommand
{
    public function __construct(private readonly LinguaClient $client) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Text to translate')] string $text,
        #[Option('to')] ?string $to = null,
        #[Option('from')] ?string $from = null,
        #[Option('engine')] ?string $engine = null,
        #[Option('now')] ?bool $now = null,               // bypass queue; translate immediately
        #[Option('no-translate')] ?bool $noTranslate = null, // lookup-only: do not insert new strings
        #[Option('enqueue')] ?bool $enqueue = null,       // enqueue when not using --now
        #[Option('force')] ?bool $force = null,
    ): int {
        $to = $to ?? 'es';
        $from = $from ?? 'en';
        $engine = $engine ?? 'libre';
        $now = $now ?? false;
        $noTranslate = $noTranslate ?? false;
        $enqueue = $enqueue ?? !$now; // default: enqueue unless --now
        $force = $force ?? false;

        if ($now) {
            // Use translateNow to get cached/fresh info for a single text
            $item = $this->client->translateNow($text, $to, $from, ['engine' => $engine], $noTranslate);
            $io->writeln(($item->cached ? '[cached] ' : '[fresh] ').$item->text);
            if ($item->cached) { return Command::SUCCESS; }
            return Command::SUCCESS;
        }

        $req = new BatchRequest(
            texts: [$text],
            source: $from,
            target: is_array($to) ? $to : explode(',', $to),
            html: false,
            insertNewStrings: !$noTranslate,
            extra: ['engine' => $engine],
            enqueue: $enqueue,
            force: $force,
            engine: $engine,
        );
        $res = $this->client->requestBatch($req);

        if ($res->status === 'queued' && $res->jobId) {
            $io->success(sprintf('Queued job %s', $res->jobId));
            return Command::SUCCESS;
        }

        foreach ($res->items as $item) {
            $io->writeln(sprintf('[%s→%s]%s %s', $item->source, $item->target, $item->cached ? ' [cached]' : '', ' '.$item->text));
        }

        dump($req, $res);

        return Command::SUCCESS;
    }
}
