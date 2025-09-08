<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Dto;

/** Returned by /batch-translate (server decides schema; we normalize here). */
final class BatchResponse
{
    /**
     * @param list<TranslationItem> $items
     */
    public function __construct(
        public readonly string $status,              // "queued" | "ok" | "partial" | "error"
        public readonly array $items = [],           // when results exist
        public readonly ?string $jobId = null,       // when queued
        public readonly ?string $message = null,
    ) {}
}
