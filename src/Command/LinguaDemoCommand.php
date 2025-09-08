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

#[AsCommand('lingua:demo')]
final class LinguaDemoCommand
{
    public function __construct(private readonly LinguaClient $client) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Phrase to translate')] string $text,
        #[Option('to')] ?string $to = null,
        #[Option('from')] ?string $from = null,
        #[Option('enqueue')] ?bool $enqueue = null,
        #[Option('force')] ?bool $force = null,
    ): int {
        $to = $to ?? 'es';
        $from = $from ?? 'en';
        $enqueue = $enqueue ?? false; // default to fetch-only for demo
        $force = $force ?? false;

        $req = new BatchRequest(texts: [$text], source: $from, target: $to, enqueue: $enqueue, force: $force);
        $res = $this->client->requestBatch($req);

        if ($res->status === 'queued' && $res->jobId) {
            $io->writeln(sprintf('Queued job %s (status=%s)', $res->jobId, $res->status));
            return Command::SUCCESS;
        }

        foreach ($res->items as $item) {
            $io->writeln(sprintf('[%s→%s]%s %s', $item->source, $item->target, $item->cached ? ' [cached]' : '', ' '.$item->text));
        }

        return Command::SUCCESS;
    }
}
