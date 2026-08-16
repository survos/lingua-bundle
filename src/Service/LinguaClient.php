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
    // Literals, not Survos\Lingua\Contracts\Http\LinguaApi::ROUTE_*. Apps run on published
    // vendor copies, so a stale lingua-contracts would silently point this client at a
    // different path. Contracts carries the same values; they are checked, not shared.
    public const ROUTE_BATCH = '/batch-translate';
    public const ROUTE_PULL  = '/babel/pull';

    public const DEFAULT_SERVER = 'https://lingua.survos.com';

    /** JSON-RPC endpoint. Versioned in the path, so v2 can coexist rather than replace. */
    public const ROUTE_RPC = '/api/v1';

    public const PROTOCOL_REST = 'rest';
    public const PROTOCOL_RPC  = 'rpc';

    public const METHOD_PULL  = 'pullTranslations';
    public const METHOD_BATCH = 'translateBatch';

    /**
     * Called at the start and end of every request to lingua, with a {@see LinguaCall}.
     *
     * A public mutable hook rather than an event or an injected listener: the only consumers
     * are the console commands in this bundle, they set it for the duration of one run, and
     * an event dispatcher would mean apps wiring a subscriber to see progress they asked for
     * on the command line. Left null, the client is silent as before.
     */
    public ?\Closure $onCall = null;

    /**
     * Every setting arrives as an explicit, typed argument, resolved by
     * {@see \Survos\LinguaBundle\SurvosLinguaBundle::loadExtension()} from the
     * `survos_lingua` config tree. Nothing here reads an env var: no
     * `#[Autowire('%env(...)%')]`, and no opaque `$config` array to fish keys out of at
     * call time. Configuration is the bundle extension's job; a service should receive
     * values, not go looking for them.
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly HttpKernelInterface $httpKernel,
        private readonly LoggerInterface $logger,
        // Nullable because `%env(default::LINGUA_BASE_URI)%` resolves to null, not '',
        // when the variable is unset.
        private readonly ?string $server = null,
        private readonly ?string $apiKey = null,
        private readonly int $timeoutSeconds = 10,
        private readonly ?string $proxyUrl = null,
        // 'rest' or 'rpc'. Defaults to rest so upgrading this bundle changes nothing for an
        // app pointing at a lingua that predates /api/v1; flip it per app once the server is
        // deployed. Deliberately not auto-detected -- probing costs a round trip, and a
        // silent fallback would hide a misconfigured server rather than report it.
        // Nullable for the same reason as $server: '%env(default::LINGUA_PROTOCOL)%' resolves
        // to null when unset, and a `?:` in the extension runs against the *unresolved*
        // placeholder string, which is truthy -- so the null arrives here regardless.
        private readonly ?string $protocolName = null,
    ) {}

    public string $protocol {
        get => $this->protocolName === self::PROTOCOL_RPC ? self::PROTOCOL_RPC : self::PROTOCOL_REST;
    }

    public bool $usesRpc { get => $this->protocol === self::PROTOCOL_RPC; }

    /** null/'' when LINGUA_BASE_URI is unset, so fall back rather than build relative URLs. */
    public string $baseUri { get => rtrim($this->server ?: self::DEFAULT_SERVER, '/'); }
    public ?string $apiToken { get => $this->apiKey ?: null; }
    public ?string $proxy { get => $this->proxyUrl ?: (str_contains($this->baseUri, '.wip') ? 'http://127.0.0.1:7080' : null); }
    public int $timeout { get => $this->timeoutSeconds; }

    #[\Deprecated('Use Survos\\Lingua\\Core\\Identity\\HashUtil::calcSourceKey()')]
    public static function calcHash(string $string, string $locale): string
    {
        return HashUtil::calcSourceKey($string, $locale);
    }

    /**
     * One JSON-RPC call. Returns the `result` member.
     *
     * A JSON-RPC error is raised as {@see LinguaRpcException} carrying the code, rather than
     * folded into the return value: -32602 for a payload lingua rejected and -32000 for a bad
     * API key are things a caller must not mistake for an empty result. This is the whole
     * reason the write path moved -- the REST route reports a rejected payload as
     * {"status":"ok","response":{"error":...}} at HTTP 200.
     *
     * @param  array<string,mixed> $params
     * @return array<string,mixed> the `result` member
     * @throws LinguaRpcException
     */
    public function rpc(string $method, array $params, int $itemCount = 0, ?string $locale = null): array
    {
        $this->report(new LinguaCall($method, LinguaCall::PHASE_START, self::PROTOCOL_RPC, $itemCount, $locale));
        $startedAt = microtime(true);

        try {
            $response = $this->http->request('POST', $this->baseUri . self::ROUTE_RPC, [
                'json'    => ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params, 'id' => '1'],
                'headers' => $this->headers(json: true),
                'timeout' => $this->timeout,
                'proxy'   => $this->proxy,
            ]);

            $decoded = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->reportError($method, $itemCount, $locale, $startedAt, $e->getMessage());

            throw new LinguaRpcException(
                sprintf('Transport error calling %s: %s', $method, $e->getMessage()),
                previous: $e,
            );
        }

        if (isset($decoded['error'])) {
            $error = $decoded['error'];
            $message = (string) ($error['message'] ?? 'Unknown JSON-RPC error');
            $code = (int) ($error['code'] ?? 0);

            $this->reportError($method, $itemCount, $locale, $startedAt, sprintf('%s (%d)', $message, $code));

            throw new LinguaRpcException(sprintf('%s: %s', $method, $message), $code);
        }

        $result = $decoded['result'] ?? null;
        if (!is_array($result)) {
            $this->reportError($method, $itemCount, $locale, $startedAt, 'response had no result member');

            throw new LinguaRpcException(sprintf('%s: response had no result member', $method));
        }

        $this->report(new LinguaCall(
            $method,
            LinguaCall::PHASE_DONE,
            self::PROTOCOL_RPC,
            $itemCount,
            $locale,
            $this->countResult($result),
            (microtime(true) - $startedAt) * 1000,
        ));

        return $result;
    }

    /** Best-effort "how much came back", purely for the progress line. */
    private function countResult(array $result): ?int
    {
        foreach (['translations', 'items'] as $key) {
            if (isset($result[$key]) && is_array($result[$key])) {
                return count($result[$key]);
            }
        }

        return $result['queued'] ?? null;
    }

    private function report(LinguaCall $call): void
    {
        $this->logger->info('lingua ' . $call->describe());

        if ($this->onCall !== null) {
            ($this->onCall)($call);
        }
    }

    private function reportError(string $method, int $itemCount, ?string $locale, float $startedAt, string $error): void
    {
        $this->report(new LinguaCall(
            $method,
            LinguaCall::PHASE_ERROR,
            $this->protocol,
            $itemCount,
            $locale,
            null,
            (microtime(true) - $startedAt) * 1000,
            $error,
        ));
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

        if ($this->usesRpc) {
            return $this->pullViaRpc($hashes, $locale, $engine);
        }

        return $this->pullViaRest($hashes, $locale, $engine);
    }

    /**
     * @param  list<string> $hashes
     * @return array<string,string>
     * @throws LinguaRpcException
     */
    private function pullViaRpc(array $hashes, ?string $locale, ?string $engine): array
    {
        $params = ['hashes' => $hashes];
        if ($locale) {
            $params['locale'] = $locale;
        }
        if ($engine) {
            $params['engine'] = $engine;
        }

        $result = $this->rpc(self::METHOD_PULL, $params, count($hashes), $locale);

        $translations = $result['translations'] ?? [];
        if (!is_array($translations)) {
            return [];
        }

        // `missing` is the reason this method exists over RPC: it separates "lingua has never
        // seen this hash" from "lingua has it but has not translated it yet", which the REST
        // response cannot express -- both are simply absent from the map. Logged rather than
        // returned, because the return shape is shared with the REST path and callers index
        // it by hash; surfacing it properly is a caller-facing API change for another day.
        $missing = $result['missing'] ?? [];
        if (is_array($missing) && $missing !== []) {
            $this->logger->debug('lingua pullTranslations: not yet translated', [
                'count'  => count($missing),
                'locale' => $locale,
            ]);
        }

        $out = [];
        foreach ($translations as $hash => $text) {
            if (!is_string($hash) || $hash === '' || $text === null) {
                continue;
            }
            $out[$hash] = is_string($text) ? $text : (string) $text;
        }

        return $out;
    }

    /**
     * @param  list<string> $hashes
     * @return array<string,string>
     */
    private function pullViaRest(array $hashes, ?string $locale, ?string $engine): array
    {
        $this->report(new LinguaCall(
            self::ROUTE_PULL,
            LinguaCall::PHASE_START,
            self::PROTOCOL_REST,
            count($hashes),
            $locale,
        ));
        $startedAt = microtime(true);

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

        $this->report(new LinguaCall(
            self::ROUTE_PULL,
            LinguaCall::PHASE_DONE,
            self::PROTOCOL_REST,
            count($hashes),
            $locale,
            count($out),
            (microtime(true) - $startedAt) * 1000,
        ));

        return $out;
    }

    /** @param list<string> $texts */
    public static function textToCodes(array $texts, string $target): array
    {
        return array_map(static fn(string $s) => HashUtil::calcSourceKey($s, $target), $texts);
    }

    /**
     * Submit a batch request (contracts DTO).
     *
     * @return array<string,mixed>
     */
    public function requestBatch(BatchRequest $req, ?Request $request = null): array
    {
        $payload = $this->batchRequestPayload($req);

        // The in-process short-circuit below only exists for running lingua against itself,
        // so it stays on the REST route regardless of protocol -- there is no HTTP round trip
        // to save, and the sub-request needs a real controller.
        if ($this->usesRpc && !$this->isLocalSubRequest($request)) {
            return $this->requestBatchViaRpc($req);
        }

        $params = [
            'timeout'  => $this->timeout,
            'proxy'    => $this->proxy,
            'headers'  => $this->headers(json: true),
            'json'     => $payload,
        ];

        // Local short-circuit: call route handler in-process so you get real PHP stack traces.
        if ($this->isLocalSubRequest($request))
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

        // Real HTTP. Narrated the same way the RPC path is, so switching protocol changes the
        // wire format and the error handling, not whether you can see what is happening.
        $locale = is_array($req->target) ? implode(',', $req->target) : (string) $req->target;
        $this->report(new LinguaCall(
            self::ROUTE_BATCH,
            LinguaCall::PHASE_START,
            self::PROTOCOL_REST,
            count($req->texts),
            $locale,
        ));
        $startedAt = microtime(true);

        try {
            $response = $this->http->request('POST', $this->baseUri . self::ROUTE_BATCH, $params);
            $status   = $response->getStatusCode();
            $content  = $response->getContent(false);

            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $inner = (isset($decoded['response']) && is_array($decoded['response'])) ? $decoded['response'] : $decoded;
                $this->report(new LinguaCall(
                    self::ROUTE_BATCH,
                    LinguaCall::PHASE_DONE,
                    self::PROTOCOL_REST,
                    count($req->texts),
                    $locale,
                    $this->countResult($inner),
                    (microtime(true) - $startedAt) * 1000,
                ));
            }

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
            $this->reportError(self::ROUTE_BATCH, count($req->texts), $locale, $startedAt, $e->getMessage());

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

    private function isLocalSubRequest(?Request $request): bool
    {
        return $request !== null && parse_url($this->baseUri, PHP_URL_HOST) === $request->getHost();
    }

    /**
     * The write path over JSON-RPC.
     *
     * Returns the same {status, response} shape the REST path returns, because callers --
     * LinguaPushBabelCommand among them -- already read it that way. The shape is
     * reconstructed here rather than sent by the server: translateBatch deliberately drops
     * `status`, since JSON-RPC states success by returning `result` instead of `error`.
     *
     * A rejection is an exception now, not a value. That is the substantive difference: over
     * REST an invalid payload came back as {"status":"ok","response":{"error":...}} at HTTP
     * 200 and a caller that only checked `status` treated it as success.
     *
     * @return array<string,mixed>
     * @throws LinguaRpcException
     */
    private function requestBatchViaRpc(BatchRequest $req): array
    {
        $params = array_filter(
            $this->batchRequestPayload($req),
            static fn(mixed $v): bool => $v !== null,
        );

        $result = $this->rpc(
            self::METHOD_BATCH,
            $params,
            count($req->texts),
            is_array($req->target) ? implode(',', $req->target) : (string) $req->target,
        );

        return ['status' => 'ok', 'response' => $result];
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
