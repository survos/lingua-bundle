<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Dto;

/** Polling response for async jobs. */
final class JobStatus
{
    /**
     * @param list<TranslationItem> $items
     */
    public function __construct(
        public readonly string $jobId,
        public readonly string $state,   // "pending" | "running" | "completed" | "failed"
        public readonly ?int $progress = null,
        public readonly array $items = [],
        public readonly ?string $message = null,
    ) {}
}
