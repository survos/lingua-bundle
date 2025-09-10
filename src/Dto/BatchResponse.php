<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Dto;

use JsonSerializable;

/**
 * Normalized response for LinguaClient::requestBatch().
 * Covers both "queued/sources/missing/error" and "items/jobId" shapes.
 *
 * - $items are fully denormalized TranslationItem objects when present.
 */
final class BatchResponse implements JsonSerializable
{
    /**
     * @param list<TranslationItem> $items
     * @param list<string>|int      $sources
     * @param list<string>|int      $missing
     */
    public function __construct(
        public ?string $status = null,          // "ok" | "error" | ...
        public ?string $jobId = null,           // async job id (if any)
        public int $queued = 0,                 // count queued server-side
        public array|int $sources = [],         // accepted items (list or count)
        public array|int $missing = [],         // missing/rejected (list or count)
        public array $items = [],               // list<TranslationItem>
        public ?string $error = null,           // error message if any
        public ?string $message = null,         // optional server message
        public array $extra = [],               // extra payload
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'status'  => $this->status,
            'jobId'   => $this->jobId,
            'queued'  => $this->queued,
            'sources' => $this->sources,
            'missing' => $this->missing,
            'items'   => array_map(static fn($i) => [
                'hash'   => $i->hash,
                'source' => $i->source,
                'target' => $i->target,
                'text'   => $i->text,
                'engine' => $i->engine,
                'cached' => $i->cached,
                'meta'   => $i->meta,
            ], $this->items),
            'error'   => $this->error,
            'message' => $this->message,
            'extra'   => $this->extra,
        ];
    }

    public function toArray(): array
    {
        return $this->jsonSerialize();
    }
}
