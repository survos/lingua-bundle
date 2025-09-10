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
    public ?string $proxy { get => $this->config['proxy'] ?? (str_contains($this->baseUri, '.wip') ? 'http://127.0.0.1:7080' : null); }
    public int $timeout { get => (int)($this->config['timeout'] ?? 10); }

    /** Deterministic code for a source string + target locale (compat with your server). */
    public static function calcHash(string $string, string $locale): string
    {
        \assert(\strlen($locale) >= 2, "Invalid Locale: $locale");
        $h = \hash('xxh3', $string);
        // keep server-compatible splice of locale into hash early
        return \substr_replace($h, \strtoupper(substr($locale, 0, 2)), 3, 0);
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
            'timeout'  => $this->timeout,
            'proxy'    => $this->proxy,
            'no_proxy' => ['127.0.0.1', 'localhost'],
            'headers'  => $this->headers(),
        ]);

        return $res->toArray(false);
    }

    /** Submit a batch; server decides sync vs async based on payload/flags. */
    public function requestBatch(BatchRequest $req): BatchResponse
    {
        $params = [
            'timeout'  => $this->timeout,
            'proxy'    => $this->proxy,
            'headers'  => $this->headers(json: true),
            'json'     => $req, // BatchRequest implements JsonSerializable
        ];

        $res     = $this->http->request('POST', $this->baseUri.self::ROUTE_BATCH, $params);
        $status  = $res->getStatusCode();
        $content = $res->getContent(false);
        $data    = json_decode($content, true);
        if (!\is_array($data)) {
            $this->logger->error('Lingua batch non-JSON response', ['status' => $status, 'content' => $content]);
            return new BatchResponse(status: 'error', error: 'Non-JSON response from server', message: $content);
        }

        // Server may return {status, response:{...}} or flat {...}
        $top  = $data;
        $resp = \is_array($data['response'] ?? null) ? $data['response'] : $data;

        $statusText = (string)($top['status'] ?? ($status === 200 ? 'ok' : 'error'));
        $jobId      = $top['jobId']    ?? $resp['jobId']    ?? null;
        $queued     = (int)  ($resp['queued']  ?? 0);
        $sources    =         $resp['sources'] ?? [];
        $missing    =         $resp['missing'] ?? [];
        $error      =         $resp['error']   ?? $top['error'] ?? null;
        $message    =         $resp['message'] ?? $top['message'] ?? null;
        $extra      = is_array($resp['extra'] ?? null) ? $resp['extra'] : [];

        // Items may be on either level
        $itemsRaw   = $top['items'] ?? $resp['items'] ?? null;
        $items      = is_array($itemsRaw) ? $this->denormItems($itemsRaw) : [];

        if ($status !== 200 || $error) {
            $this->logger->warning('Lingua batch returned status or error', [
                'status'  => $status,
                'payload' => $data,
            ]);
        }

        return new BatchResponse(
            status:  $statusText,
            jobId:   is_string($jobId) ? $jobId : null,
            queued:  $queued,
            sources: $sources,
            missing: $missing,
            items:   $items,
            error:   is_string($error) ? $error : (is_scalar($error) ? (string)$error : null),
            message: is_string($message) ? $message : null,
            extra:   $extra
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
            items: isset($data['items']) && is_array($data['items']) ? $this->denormItems($data['items']) : [],
            message: $data['message'] ?? null,
        );
    }

    /** One-off translation; returns a TranslationItem (with cached flag) for a single text. */
    public function translateNow(string $text, string $to, ?string $from = null, array $extra = [], bool $noTranslate = false): TranslationItem
    {
        $req = new BatchRequest(
            texts: [$text],
            source: $from ?? 'auto',
            target: is_array($to) ? $to : [$to],
            html: false,
            insertNewStrings: !$noTranslate,
            extra: $extra,
            enqueue: false,
            force: false,
        );
        $res = $this->requestBatch($req);
        return $res->items[0] ?? new TranslationItem(
            hash: self::calcHash($text, is_array($to) ? ($to[0] ?? 'xx') : $to),
            source: $from ?? 'auto',
            target: is_array($to) ? ($to[0] ?? 'xx') : $to,
            text: $text,
            engine: null,
            cached: false,
            meta: []
        );
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
            $h['X-Api-Key']     = $this->apiToken;
            $h['Authorization'] = 'Bearer '.$this->apiToken;
        }
        return $h;
    }
}
