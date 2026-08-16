<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Service;

/**
 * A JSON-RPC error object from lingua, or a transport failure reaching it.
 *
 * Thrown rather than returned so a caller cannot mistake a rejection for an empty result.
 * That distinction is the point of moving off the REST route, which answers a rejected
 * payload with {"status":"ok","response":{"error":"..."}} at HTTP 200 -- indistinguishable
 * from success unless you go looking.
 *
 * `$code` is the JSON-RPC error code when the server sent one, 0 for a transport failure:
 *   -32602  invalid params (bad payload, unknown engine, wrong types)
 *   -32601  method not found -- usually a lingua too old to have the method
 *   -32000  unauthorized (LinguaApiKeyListener) -- the key is missing, wrong, or not set
 *           on this app while lingua has one
 */
final class LinguaRpcException extends \RuntimeException
{
    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /** True when lingua does not know the method -- typically a server that predates it. */
    public function isMethodNotFound(): bool
    {
        return $this->getCode() === -32601;
    }

    /** True when lingua rejected the key, or wants one this app has not been given. */
    public function isUnauthorized(): bool
    {
        return $this->getCode() === -32000;
    }
}
