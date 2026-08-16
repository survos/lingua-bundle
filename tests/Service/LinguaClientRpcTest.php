<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Survos\Lingua\Contracts\Dto\BatchRequest;
use Survos\LinguaBundle\Service\LinguaCall;
use Survos\LinguaBundle\Service\LinguaClient;
use Survos\LinguaBundle\Service\LinguaRpcException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The client's JSON-RPC path, against a mock transport.
 *
 * The interesting cases are the failures. Over REST a rejected payload comes back as
 * {"status":"ok","response":{"error":...}} at HTTP 200, so a caller checking `status`
 * treats it as success; over RPC it has to be impossible to miss.
 */
final class LinguaClientRpcTest extends TestCase
{
    /** @var list<array{url:string, body:array}> */
    private array $sent = [];

    private function client(array $responses, string $protocol = LinguaClient::PROTOCOL_RPC): LinguaClient
    {
        $this->sent = [];

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses) {
            $this->sent[] = [
                'url' => $url,
                'body' => json_decode($options['body'] ?? '[]', true, flags: JSON_THROW_ON_ERROR),
                'headers' => $options['normalized_headers'] ?? [],
            ];

            return array_shift($responses) ?? new MockResponse('{}');
        });

        return new LinguaClient(
            $http,
            $this->createStub(HttpKernelInterface::class),
            new NullLogger(),
            server: 'https://lingua.example.com',
            apiKey: 'test-key',
            protocolName: $protocol,
        );
    }

    private static function rpcResult(array $result): MockResponse
    {
        return new MockResponse(
            json_encode(['jsonrpc' => '2.0', 'result' => $result, 'id' => '1'], JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        );
    }

    private static function rpcError(int $code, string $message): MockResponse
    {
        return new MockResponse(
            json_encode(
                ['jsonrpc' => '2.0', 'error' => ['code' => $code, 'message' => $message], 'id' => '1'],
                JSON_THROW_ON_ERROR,
            ),
            ['response_headers' => ['content-type' => 'application/json']],
        );
    }

    public function testPullPostsAJsonRpcEnvelopeToApiV1(): void
    {
        $client = $this->client([self::rpcResult(['translations' => ['abc' => 'hola'], 'missing' => []])]);

        $client->pullBabelByHashes(['abc'], 'es');

        self::assertSame('https://lingua.example.com/api/v1', $this->sent[0]['url']);
        self::assertSame('2.0', $this->sent[0]['body']['jsonrpc']);
        self::assertSame('pullTranslations', $this->sent[0]['body']['method']);
        self::assertSame(['abc'], $this->sent[0]['body']['params']['hashes']);
        self::assertSame('es', $this->sent[0]['body']['params']['locale']);
    }

    public function testPullUnwrapsTheTranslationsMember(): void
    {
        $client = $this->client([
            self::rpcResult(['translations' => ['abc' => 'hola', 'def' => 'adios'], 'missing' => ['ghi']]),
        ]);

        self::assertSame(['abc' => 'hola', 'def' => 'adios'], $client->pullBabelByHashes(['abc', 'def', 'ghi']));
    }

    /** The REST path is untouched, and must keep hitting its own route. */
    public function testRestProtocolStillPostsToBabelPull(): void
    {
        $client = $this->client(
            [new MockResponse('{"abc":"hola"}', ['response_headers' => ['content-type' => 'application/json']])],
            LinguaClient::PROTOCOL_REST,
        );

        self::assertSame(['abc' => 'hola'], $client->pullBabelByHashes(['abc']));
        self::assertStringContainsString('/babel/pull', $this->sent[0]['url']);
    }

    public function testRestIsTheDefaultProtocol(): void
    {
        $client = new LinguaClient(
            new MockHttpClient(),
            $this->createStub(HttpKernelInterface::class),
            new NullLogger(),
            server: 'https://lingua.example.com',
        );

        self::assertSame(LinguaClient::PROTOCOL_REST, $client->protocol);
        self::assertFalse($client->usesRpc);
    }

    public function testAnUnknownProtocolDegradesToRestRatherThanFailing(): void
    {
        $client = $this->client([], 'nonsense');

        self::assertSame(LinguaClient::PROTOCOL_REST, $client->protocol);
    }

    public function testABatchRequestCallsTranslateBatch(): void
    {
        $client = $this->client([self::rpcResult(['queued' => 3, 'items' => [], 'missing' => []])]);

        $result = $client->requestBatch(new BatchRequest(
            source: 'en',
            target: ['es'],
            texts: ['one', 'two', 'three'],
        ));

        self::assertSame('translateBatch', $this->sent[0]['body']['method']);
        self::assertSame('en', $this->sent[0]['body']['params']['source']);
        // The {status, response} shape is rebuilt client-side for callers that read it.
        self::assertSame('ok', $result['status']);
        self::assertSame(3, $result['response']['queued']);
    }

    /** A JSON-RPC error must not be mistakable for an empty result. */
    public function testAnErrorObjectBecomesAnException(): void
    {
        $client = $this->client([self::rpcError(-32602, 'Invalid params. Additional info: Unknown engine "x".')]);

        $this->expectException(LinguaRpcException::class);
        $this->expectExceptionCode(-32602);
        $client->pullBabelByHashes(['abc']);
    }

    public function testAnUnauthorizedErrorIsRecognisable(): void
    {
        $client = $this->client([self::rpcError(-32000, 'Unauthorized.')]);

        try {
            $client->pullBabelByHashes(['abc']);
            self::fail('expected LinguaRpcException');
        } catch (LinguaRpcException $e) {
            self::assertTrue($e->isUnauthorized(), 'a missing/wrong API key must be identifiable');
            self::assertFalse($e->isMethodNotFound());
        }
    }

    /** A lingua too old to have the method -- worth telling apart from a bad payload. */
    public function testAMethodNotFoundErrorIsRecognisable(): void
    {
        $client = $this->client([self::rpcError(-32601, 'Method not found.')]);

        try {
            $client->pullBabelByHashes(['abc']);
            self::fail('expected LinguaRpcException');
        } catch (LinguaRpcException $e) {
            self::assertTrue($e->isMethodNotFound());
        }
    }

    public function testAResponseWithoutAResultMemberIsAnError(): void
    {
        $client = $this->client([
            new MockResponse('{"jsonrpc":"2.0","id":"1"}', ['response_headers' => ['content-type' => 'application/json']]),
        ]);

        $this->expectException(LinguaRpcException::class);
        $client->pullBabelByHashes(['abc']);
    }

    /** The key has to be on the wire, not merely configured -- lingua checks X-Api-Key. */
    public function testTheApiKeyIsSentAsAHeader(): void
    {
        $client = $this->client([self::rpcResult(['translations' => [], 'missing' => []])]);
        $client->pullBabelByHashes(['abc']);

        $headers = $this->sent[0]['headers'];
        $flat = [];
        foreach ($headers as $name => $lines) {
            $flat[strtolower((string) $name)] = implode(',', (array) $lines);
        }

        self::assertArrayHasKey('x-api-key', $flat);
        self::assertStringContainsString('test-key', $flat['x-api-key']);
        self::assertStringContainsString('Bearer test-key', $flat['authorization'] ?? '');
    }

    public function testAnEmptyHashListMakesNoRequestAtAll(): void
    {
        $client = $this->client([]);

        self::assertSame([], $client->pullBabelByHashes([]));
        self::assertSame([], $this->sent);
    }

    // --- progress reporting -------------------------------------------------

    public function testEachCallReportsAStartAndADoneWithTheMethodName(): void
    {
        $client = $this->client([self::rpcResult(['translations' => ['abc' => 'hola'], 'missing' => []])]);

        /** @var list<LinguaCall> $calls */
        $calls = [];
        $client->onCall = static function (LinguaCall $c) use (&$calls): void { $calls[] = $c; };

        $client->pullBabelByHashes(['abc', 'def'], 'es');

        self::assertCount(2, $calls);

        self::assertSame(LinguaCall::PHASE_START, $calls[0]->phase);
        self::assertSame('pullTranslations', $calls[0]->method, 'the RPC method name is what identifies the call');
        self::assertSame(2, $calls[0]->itemCount);
        self::assertSame('es', $calls[0]->locale);

        self::assertSame(LinguaCall::PHASE_DONE, $calls[1]->phase);
        self::assertSame(1, $calls[1]->resultCount);
        self::assertNotNull($calls[1]->elapsedMs);
        self::assertStringContainsString('pullTranslations', $calls[1]->describe());
        self::assertStringContainsString('1/2', $calls[1]->describe());
    }

    public function testAFailedCallReportsAnErrorPhase(): void
    {
        $client = $this->client([self::rpcError(-32602, 'Invalid params.')]);

        /** @var list<LinguaCall> $calls */
        $calls = [];
        $client->onCall = static function (LinguaCall $c) use (&$calls): void { $calls[] = $c; };

        try {
            $client->pullBabelByHashes(['abc']);
        } catch (LinguaRpcException) {
            // expected
        }

        self::assertSame(LinguaCall::PHASE_ERROR, $calls[1]->phase);
        self::assertStringContainsString('FAILED', $calls[1]->describe());
        self::assertStringContainsString('Invalid params.', (string) $calls[1]->error);
    }

    /** REST calls narrate too, so switching protocol does not silence the output. */
    public function testRestCallsAlsoReportProgress(): void
    {
        $client = $this->client(
            [new MockResponse('{"abc":"hola"}', ['response_headers' => ['content-type' => 'application/json']])],
            LinguaClient::PROTOCOL_REST,
        );

        /** @var list<LinguaCall> $calls */
        $calls = [];
        $client->onCall = static function (LinguaCall $c) use (&$calls): void { $calls[] = $c; };

        $client->pullBabelByHashes(['abc']);

        self::assertCount(2, $calls);
        self::assertSame(LinguaClient::PROTOCOL_REST, $calls[0]->transport);
        self::assertSame('/babel/pull', $calls[0]->method);
    }

    public function testDescribeFormatsSubSecondAndMultiSecondTimings(): void
    {
        $fast = new LinguaCall('m', LinguaCall::PHASE_DONE, 'rpc', 10, null, 10, 214.0);
        $slow = new LinguaCall('m', LinguaCall::PHASE_DONE, 'rpc', 10, null, 10, 2140.0);

        self::assertStringContainsString('214ms', $fast->describe());
        self::assertStringContainsString('2.1s', $slow->describe());
    }
}
