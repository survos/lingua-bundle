<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Service;

/**
 * One call to lingua, reported to whoever set {@see LinguaClient::$onCall}.
 *
 * A long `lingua:push` or `lingua:pull` spends most of its wall clock inside HTTP requests,
 * and until now printed nothing while they were in flight -- a run against a slow server
 * looked identical to a hung one. This carries enough to say what is happening and how long
 * it took, without the client needing to know whether the caller is a console command, a log
 * or a test.
 *
 * `method` is the JSON-RPC method name -- pullTranslations, translateBatch -- which is
 * exactly the sort of thing worth showing: it names the operation rather than a URL.
 * REST calls report the route they hit, so the two transports narrate the same way.
 */
final readonly class LinguaCall
{
    public const PHASE_START = 'start';
    public const PHASE_DONE  = 'done';
    public const PHASE_ERROR = 'error';

    /**
     * @param string      $method     JSON-RPC method name, or the route for a REST call
     * @param string      $phase      one of the PHASE_* constants
     * @param string      $transport  'rpc' or 'rest'
     * @param int         $itemCount  items sent (hashes, texts) -- 0 when not applicable
     * @param ?string     $locale     the locale group this call belongs to, when there is one
     * @param ?int        $resultCount items that came back, on PHASE_DONE
     * @param ?float      $elapsedMs  wall time, on PHASE_DONE / PHASE_ERROR
     * @param ?string     $error      message, on PHASE_ERROR
     */
    public function __construct(
        public string $method,
        public string $phase,
        public string $transport = 'rpc',
        public int $itemCount = 0,
        public ?string $locale = null,
        public ?int $resultCount = null,
        public ?float $elapsedMs = null,
        public ?string $error = null,
    ) {
    }

    /** A one-line summary suitable for a console or a log message. */
    public function describe(): string
    {
        $parts = [$this->method];

        if ($this->locale !== null && $this->locale !== '') {
            $parts[] = $this->locale;
        }

        $parts[] = match ($this->phase) {
            self::PHASE_START => sprintf('sending %d…', $this->itemCount),
            self::PHASE_DONE  => sprintf('%d/%d in %s', $this->resultCount ?? 0, $this->itemCount, $this->elapsed()),
            self::PHASE_ERROR => sprintf('FAILED after %s: %s', $this->elapsed(), $this->error ?? 'unknown error'),
            default           => $this->phase,
        };

        return implode(' ', $parts);
    }

    private function elapsed(): string
    {
        if ($this->elapsedMs === null) {
            return '?';
        }

        return $this->elapsedMs >= 1000
            ? sprintf('%.1fs', $this->elapsedMs / 1000)
            : sprintf('%dms', (int) round($this->elapsedMs));
    }
}
