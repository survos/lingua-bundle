<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Dto;

use JsonSerializable;
use Symfony\Component\Validator\Constraints as Assert;

/** Client payload sent to the Survos translation server. */
final class BatchRequest implements JsonSerializable
{
    /**
     * @param list<string> $texts
     * @param array<string,mixed> $extra
     */
    // these aren't readonly so they can be modified in test-api
    public function __construct(
        #[Assert\NotBlank]
        public array $texts,
        #[Assert\NotBlank]
        public string $source,            // e.g. "en" or "auto"
        public  array $target {
            set(array|string $value) => is_array($value) ? $value: [$value];
        },
        public bool $html = false,
        public bool $insertNewStrings = true, // polling-only => false (do not insert new strings)
        public array $extra = [],         // engine hints, domain, formality, etc.
        public bool $enqueue = true,      // false => fetch-only lookup
        public bool $force = false,       // force (re)dispatch even if cached/done
        public ?string $callbackUrl = null, // webhook target (server optional)
        public ?string $transport = null,   // e.g. "async" | "sync" | "amqp://..."
        public ?string $engine = 'libre',   // selected engine name
    ) {}

    // hack during transition
    public bool $forceDispatch { get  => $this->force; }

    /** Serialize to a compact JSON object (omit nulls). */
    public function jsonSerialize(): array
    {
        $data = [
            'texts'            => $this->texts,
            'source'           => $this->source,
            'target'           => $this->target,
            'html'             => $this->html,
            'insertNewStrings' => $this->insertNewStrings,
            'extra'            => $this->extra,
            'enqueue'          => $this->enqueue,
            'force'            => $this->force,
            'engine'           => $this->engine,
        ];
        if ($this->callbackUrl !== null) {
            $data['callbackUrl'] = $this->callbackUrl;
        }
        if ($this->transport !== null) {
            $data['transport'] = $this->transport;
        }
        return $data;
    }
}
