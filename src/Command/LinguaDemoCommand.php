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
use Symfony\Component\HttpFoundation\RequestStack;

#[AsCommand('lingua:demo', 'Smoke test the Lingua client against the translation server')]
final class LinguaDemoCommand
{
    public function __construct(
        private RequestStack $requestStack,
        private readonly LinguaClient $client) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Text to translate')] string $text,
        #[Option('Target locale(s) (comma-separated).')] ?string $to = null,
        #[Option('Source locale (or "auto").')] ?string $from = null,
        #[Option('Engine (e.g. "libre", "deepl").')] ?string $engine = null,
        #[Option('Translate immediately (no queue).')] ?bool $now = null,
        #[Option('Lookup-only; do not insert new strings.')] ?bool $noTranslate = null,
        #[Option('Enqueue if not using --now.')] ?bool $enqueue = null,
        #[Option('Force re-dispatch even if cached.')] ?bool $force = null,
    ): int {
        $to         = $to      ?? 'es';
        $from       = $from    ?? 'en';
        $engine     = $engine  ?? 'libre';
        $now        = $now     ?? false;
        $noTranslate= $noTranslate ?? false;
        $enqueue    = $enqueue ?? !$now; // default: enqueue unless --now
        $force      = $force   ?? false;

        if ($now) {
            $item = $this->client->translateNow($text, $to, $from, ['engine' => $engine], $noTranslate);
            $io->writeln(($item->cached ? '[cached] ' : '[fresh] ').$item->text);
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

        if ($res->error) {
            $io->error($res->error);
            return Command::FAILURE;
        }

        if ($res->queued) {
            $io->success(sprintf('Queued job %s (queued=%d)', $res->jobId, $res->queued));
        } else {
            $io->writeln("Already queued");
        }
//        $io->writeln(sprintf('[%s→%s]%s', $item->locale, $targetLocale, $translation));

        foreach ($res->items as $item) {
            $item = (object)$item;
            foreach ($item->translations as $targetLocale => $translation) {
                $io->writeln(sprintf('[%s→%s]%s', $item->locale, $targetLocale, $translation));

            }
        }

        return Command::SUCCESS;
    }
}
