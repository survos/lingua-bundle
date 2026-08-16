<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Command;

use Survos\Lingua\Contracts\Dto\BatchRequest;
use Survos\LinguaBundle\Service\LinguaCall;
use Survos\LinguaBundle\Service\LinguaClient;
use Survos\LinguaBundle\Service\LinguaRpcException;
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

        $io->writeln(sprintf(
            'Server: <info>%s</info>  transport: <info>%s</info>',
            $this->client->baseUri,
            strtoupper($this->client->protocol),
        ));

        // Narrate the request while it is in flight, and name the operation -- over RPC that
        // is the method name (translateBatch), over REST the route.
        $this->client->onCall = static function (LinguaCall $call) use ($io): void {
            $io->writeln(sprintf('  <comment>%s</comment>', $call->describe()));
        };

        if ($now) {
            $item = $this->client->translateNow(
                $text,
                $target,
                $source,
                $engine,
                $forceDispatch,
                $transport,
            );

            $io->writeln((($item['cached'] ?? false) ? '[cached] ' : '[fresh] ') . ($item['text'] ?? ''));

            return Command::SUCCESS;
        }

        $req = new BatchRequest(
            source: $source,
            target: [$target],
            texts: [$text],
            engine: $engine,
            // --no-translate means "tell me what you have, do not create anything".
            insertNewStrings: !$noTranslate,
            forceDispatch: $forceDispatch,
            transport: $transport
        );

        try {
            $raw = $this->client->requestBatch($req);
        } catch (LinguaRpcException $e) {
            // Over RPC a rejected payload is an exception with a real code, rather than an
            // "ok" envelope with an error buried in it.
            $io->error(sprintf('%s (code %d)', $e->getMessage(), $e->getCode()));

            return Command::FAILURE;
        }

        $res = (isset($raw['response']) && is_array($raw['response'])) ? $raw['response'] : $raw;

        if (($res['error'] ?? null) || ($raw['error'] ?? null)) {
            $io->error((string) ($res['error'] ?? $raw['error']));

            return Command::FAILURE;
        }

        $queued = (int) ($res['queued'] ?? 0);
        if ($queued > 0) {
            $io->success(sprintf('Queued %d translation job(s)', $queued));
        } else {
            $io->writeln('Nothing queued (already translated, or --no-translate).');
        }

        foreach ((array) ($res['missing'] ?? []) as $missingText) {
            $io->writeln(sprintf('<comment>missing:</comment> %s', (string) $missingText));
        }

        foreach ((array) ($res['items'] ?? []) as $item) {
            $item = (array) $item;
            foreach ((array) ($item['translations'] ?? []) as $targetLocale => $translation) {
                $io->writeln(sprintf('[%s→%s] %s', $item['locale'] ?? '?', $targetLocale, (string) $translation));
            }
        }

        return Command::SUCCESS;
    }
}
