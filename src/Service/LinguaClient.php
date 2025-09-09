<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Service;

use Psr\Log\LoggerInterface;
use Survos\LinguaBundle\Dto\BatchRequest;
use Survos\LinguaBundle\Dto\BatchResponse;
use Survos\LinguaBundle\Dto\JobStatus;
use Survos\LinguaBundle\Dto\TranslationItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LinguaClient
{
    public const ROUTE_BATCH  = '/batch-translate';
    public const ROUTE_SOURCE = '/source';
    public const ROUTE_JOB    = '/job'; // e.g. /job/{id}.json

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        #[Autowire(param: 'lingua.config')] private array $config = [],
    ) {}

    // === Property hooks for config access ===
    public string $baseUri { get => rtrim($this->config['server'] ?? 'https://translation-server.survos.com', '/'); }
    public ?string $apiToken { get => $this->config['api_key'] ?? null; }
    public ?string $proxy { get => $this->config['proxy'] ?? (str_contains($this->baseUri, '.wip') ? '127.0.0.1:7080' : null); }
    public int $timeout { get => (int)($this->config['timeout'] ?? 10); }

    /** Deterministic code for a source string + target locale (compat with your server). */
    public static function calcHash(string $string, string $locale): string
    {
        \assert(\strlen($locale) === 2, "Invalid Locale: $locale");
        $h = \hash('xxh3', $string);
        return \substr_replace($h, \strtoupper($locale), 3, 0);
    }

    /** @param list<string> $texts */
    public static function textToCodes(array $texts, string $target): array
    {
        return \array_map(fn($s) => self::calcHash($s, $target), $texts);
    }

    /** GET /source/{hash}.json (handy for debugging/backfills). */
    public function getSource(string $hash): array
    {
        $res = $this->http->request('GET', $this->baseUri.self::ROUTE_SOURCE.'/'.$hash.'.json', [
            'timeout' => $this->timeout,
            'proxy'   => $this->proxy,
            'headers' => $this->headers(),
        ]);

        return $res->toArray(false);
    }

    /** Submit a batch; server decides sync vs async based on payload/flags. */
    public function requestBatch(BatchRequest $req): BatchResponse
    {
        $res = $this->http->request('POST', $this->baseUri.self::ROUTE_BATCH, [
            'timeout' => $this->timeout,
            'proxy'   => $this->proxy,
            'headers' => $this->headers(json: true),
            // Thanks to JsonSerializable on BatchRequest, send the object directly.
            'json'    => $req,
        ]);

        $status = $res->getStatusCode();
        $data   = $res->toArray(false);

        if ($status !== 200) {
            $this->logger->error('Lingua batch error', ['status' => $status, 'data' => $data]);
        }

        return new BatchResponse(
            status: $data['status'] ?? ($status === 200 ? 'ok' : 'error'),
            sourceItems: $data['items'] ?? null,
            items: isset($data['items']) ? $this->denormItems($data['items']) : [],
            jobId: $data['jobId'] ?? null,
            message: $data['message'] ?? null,
        );
    }

    /** Poll a job by id (server: /job/{id}.json). */
    public function getJobStatus(string $jobId): JobStatus
    {
        $res  = $this->http->request('GET', $this->baseUri.self::ROUTE_JOB.'/'.$jobId.'.json', [
            'timeout' => $this->timeout,
            'proxy'   => $this->proxy,
            'headers' => $this->headers(),
        ]);
        $data = $res->toArray(false);

        return new JobStatus(
            jobId: $data['jobId'] ?? $jobId,
            state: $data['state'] ?? 'unknown',
            progress: $data['progress'] ?? null,
            items: isset($data['items']) ? $this->denormItems($data['items']) : [],
            message: $data['message'] ?? null,
        );
    }

    /** One-off translation; returns a TranslationItem (with cached flag) for a single text. */
    public function translateNow(string $text, string $to, ?string $from = null, array $extra = [], bool $noTranslate = false): TranslationItem
    {
        $req = new BatchRequest(
            texts: [$text],
            source: $from ?? 'auto',
            target: is_array($to) ? $to: [$to],
            html: false,
            insertNewStrings: !$noTranslate,
            extra: $extra,
            enqueue: false,
            force: false,
        );
        $res = $this->requestBatch($req);
        return $res->items[0] ?? new TranslationItem(hash: self::calcHash($text, $to), source: $from ?? 'auto', target: $to, text: $text, engine: null, cached: false, meta: []);
    }

    /** @param list<array<string,mixed>> $rows */
    private function denormItems(array $rows): array
    {
        $items = [];
        foreach ($rows as $r) {

            $items[] = new TranslationItem(
                hash: (string)($r['hash'] ?? ''),
                source: (string)($r['source'] ?? ''),
                target: (string)($r['target'] ?? ''),
                text: (string)($r['text'] ?? ''),
                engine: $r['engine'] ?? null,
                cached: (bool)($r['cached'] ?? false),
                meta: (array)($r['meta'] ?? []),
            );
        }
        return $items;
    }

    /** Default headers incl. API key. */
    private function headers(bool $json = false): array
    {
        $h = [ 'Accept' => 'application/json' ];
        if ($json) {
            $h['Content-Type'] = 'application/json';
        }
        if ($this->apiToken) {
            // Prefer a simple API key header to keep proxies happy
            $h['X-Api-Key'] = $this->apiToken;
            // Optionally also send bearer; comment out if not needed server-side
            $h['Authorization'] = 'Bearer '.$this->apiToken;
        }
        return $h;
    }
}
