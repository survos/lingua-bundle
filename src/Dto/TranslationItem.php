<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Dto;

final class TranslationItem
{
    public function __construct(
        public readonly string $hash,
        public readonly string $source,
        public readonly string $target,
        public readonly string $text,          // translated text
        public readonly ?string $engine = null,
        public readonly bool $cached = false,
        /** @var array<string,mixed> */
        public readonly array $meta = [],
    ) {}
}
