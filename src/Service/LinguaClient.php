<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Service;

use Psr\Log\LoggerInterface;
use Survos\Lingua\Contracts\Dto\BatchRequest;
use Survos\Lingua\Core\Identity\HashUtil;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LinguaClient
{
    public const ROUTE_BATCH  = '/batch-translate';
    public const ROUTE_PULL   = '/babel/pull';
    public const ROUTE_SOURCE = '/source';
    public const ROUTE_JOB    = '/job';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly HttpKernelInterface $httpKernel,
        private readonly LoggerInterface $logger,
        #[Autowire(param: 'lingua.config')] private array $config = [],
    ) {}

    public string $baseUri { get => rtrim((string)($this->config['server'] ?? 'https://translation-server.survos.com'), '/'); }
    public ?string $apiToken { get => isset($this->config['api_key']) ? (string) $this->config['api_key'] : null; }
    public ?string $proxy { get => $this->config['proxy'] ?? (str_contains($this->baseUri, '.wip') ? 'http://127.0.0.1:7080' : null); }
    public int $timeout { get => (int)($this->config['timeout'] ?? 10); }

    #[\Deprecated('Use Survos\\Lingua\\Core\\Identity\\HashUtil::calcSourceKey()')]
    public static function calcHash(string $string, string $locale): string
    {
        return HashUtil::calcSourceKey($string, $locale);
    }

    /**
     * @param list<string> $hashes
     * @return array<string,string> map[strCode => translatedText]
     */
    public function pullBabelByHashes(array $hashes, ?string $locale = null, ?string $engine = null): array
    {
        $hashes = array_values(array_unique(array_filter(array_map('strval', $hashes))));
        if ($hashes === []) {
            return [];
        }

        $query = [];
        if ($locale) {
            $query['locale'] = $locale;
        }
        if ($engine) {
            $query['engine'] = $engine;
        }

        // Back-compat: send both "hashes" and "keys"
        $payload = [
            'hashes' => $hashes,
            'keys'   => $hashes,
        ];

        $response = $this->http->request('POST', $this->baseUri . self::ROUTE_PULL, [
            'query'    => $query,
            'json'     => $payload,
            'headers'  => $this->headers(json: true),
            'timeout'  => $this->timeout,
            'proxy'    => $this->proxy,
        ]);

        $data = $response->toArray(false);
        if (!is_array($data)) {
            return [];
        }

        // Unwrap common envelopes: {"response": {...}} or {"data": {...}}
        if (isset($data['response']) && is_array($data['response'])) {
            $data = $data['response'];
        } elseif (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        $out = [];
        foreach ($data as $k => $v) {
            if (!is_string($k) || $k === '') {
                continue;
            }
            if ($v === null) {
                continue;
            }
            $out[$k] = is_string($v) ? $v : (string) $v;
        }

        return $out;
    }

    /** @param list<string> $texts */
    public static function textToCodes(array $texts, string $target): array
    {
        return array_map(static fn(string $s) => HashUtil::calcSourceKey($s, $target), $texts);
    }

    public function getSource(string $hash): array
    {
        $res = $this->http->request('GET', $this->baseUri . self::ROUTE_SOURCE . '/' . $hash . '.json', [
            'timeout'  => $this->timeout,
            'proxy'    => $this->proxy,
            'headers'  => $this->headers(),
        ]);

        $data = $res->toArray(false);
        return is_array($data) ? $data : [];
    }

    /**
     * Submit a batch request (contracts DTO).
     *
     * @return array<string,mixed>
     */
    public function requestBatch(BatchRequest $req, ?Request $request = null): array
    {
        $payload = $this->batchRequestPayload($req);

        $params = [
            'timeout'  => $this->timeout,
            'proxy'    => $this->proxy,
            'headers'  => $this->headers(json: true),
            'json'     => $payload,
        ];

        // Local short-circuit: call route handler in-process so you get real PHP stack traces.
        if ($request && parse_url($this->baseUri, PHP_URL_HOST) === $request->getHost())
        {
            $sub = HttpRequest::create(
                self::ROUTE_BATCH,
                'POST',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode($payload, JSON_THROW_ON_ERROR)
            );

            $response = $this->httpKernel->handle($sub, HttpKernelInterface::SUB_REQUEST);
            $status   = $response->getStatusCode();
            $content  = $response->getContent();
            if ($content === false) {
                $content = '';
            }

            try {
                $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
                return is_array($decoded)
                    ? $decoded
                    : ['status' => 'error', 'error' => 'Non-JSON response from server', 'http_status' => $status];
            } catch (\Throwable $e) {
                $this->logger->error('LinguaClient sub-request returned non-JSON', [
                    'status' => $status,
                    'error'  => $e->getMessage(),
                    'body'   => $content,
                ]);
                return ['status' => 'error', 'error' => 'Non-JSON response from server', 'http_status' => $status];
            }
        }

        // Real HTTP
        try {
            $response = $this->http->request('POST', $this->baseUri . self::ROUTE_BATCH, $params);
            $status   = $response->getStatusCode();
            $content  = $response->getContent(false);

            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                $this->logger->error('LinguaClient non-JSON response', [
                    'status' => $status,
                    'body'   => $content,
                ]);
                return ['status' => 'error', 'error' => 'Non-JSON response from server', 'http_status' => $status, 'body' => $content];
            }

            return $decoded;
        } catch (ExceptionInterface $e) {
            // This is the important part: expose the real exception and any partial response.
            $this->logger->error('LinguaClient HTTP exception', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
                'url'       => $this->baseUri . self::ROUTE_BATCH,
            ]);

            return [
                'status' => 'error',
                'error'  => $e->getMessage(),
                'exception' => $e::class,
            ];
        }
    }

    public function getJobStatus(string $jobId): array
    {
        $res = $this->http->request('GET', $this->baseUri . self::ROUTE_JOB . '/' . $jobId . '.json', [
            'timeout'  => $this->timeout,
            'proxy'    => $this->proxy,
        ]);

        $data = $res->toArray(false);
        return is_array($data) ? $data : [];
    }

    public function translateNow(string $text, string $to, ?string $from = null, ?string $engine = null, bool $forceDispatch = false, ?string $transport = null): array
    {
        $req = new BatchRequest(
            source: $from ?? 'auto',
            target: [$to],
            texts: [$text],
            engine: $engine,
            insertNewStrings: true,
            forceDispatch: $forceDispatch,
            transport: $transport
        );

        $raw  = $this->requestBatch($req);
        $resp = (isset($raw['response']) && is_array($raw['response'])) ? $raw['response'] : $raw;

        $items = $resp['items'] ?? null;
        if (is_array($items) && isset($items[0]) && is_array($items[0])) {
            return $items[0];
        }

        return [
            'hash'   => HashUtil::calcSourceKey($text, $to),
            'source' => $from ?? 'auto',
            'target' => $to,
            'text'   => $text,
            'engine' => $engine,
            'cached' => false,
            'meta'   => [],
        ];
    }

    private function batchRequestPayload(BatchRequest $req): array
    {
        return [
            'source'           => $req->source,
            'target'           => $req->target,
            'texts'            => $req->texts,
            'engine'           => $req->engine,
            'insertNewStrings' => $req->insertNewStrings,
            'forceDispatch'    => $req->forceDispatch,
            'transport'        => $req->transport,
        ];
    }

    private function headers(bool $json = false): array
    {
        $h = ['Accept' => 'application/json'];
        if ($json) {
            $h['Content-Type'] = 'application/json';
        }
        if ($this->apiToken) {
            $h['X-Api-Key']     = $this->apiToken;
            $h['Authorization'] = 'Bearer ' . $this->apiToken;
        }
        return $h;
    }
}
