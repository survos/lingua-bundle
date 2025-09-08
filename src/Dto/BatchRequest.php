<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Dto;

/** Client payload sent to the Survos translation server. */
final class BatchRequest
{
    /**
     * @param list<string> $texts
     * @param array<string,mixed> $extra
     */
    public function __construct(
        public readonly array $texts,
        public readonly string $source,            // e.g. "en"
        public readonly string|array $target,      // "es" or ["es","fr"]
        public readonly bool $html = false,
        public readonly array $extra = [],         // engine hints, domain, formality, etc.
        public readonly bool $enqueue = true,      // false => fetch-only lookup
        public readonly bool $force = false,       // force (re)dispatch even if cached/done
        public readonly ?string $callbackUrl = null, // webhook target (server optional)
        public readonly ?string $transport = null     // e.g. "async" | "sync" | "amqp://..."
    ) {}
}
