<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Security;

use Survos\Lingua\Contracts\Http\LinguaApi;
use Symfony\Component\HttpFoundation\Request;

/**
 * The lingua API shared secret, server side.
 *
 * lingua has no authentication today: `/batch-translate` creates rows and can spend DeepL
 * money, `/babel/pull` reads the whole translation memory, and both answer anonymously on a
 * public host. LinguaClient has always sent `X-Api-Key` and `Authorization: Bearer`; nothing
 * read either.
 *
 * One setting covers both ends. `survos_lingua.api_key` (from `LINGUA_API_KEY`) is installed
 * on lingua and on every app that calls it: apps send it, lingua compares against it. The
 * key is handed to this service as a constructor argument by
 * {@see \Survos\LinguaBundle\SurvosLinguaBundle::loadExtension()} -- it is never read from
 * the environment here.
 *
 * `isConfigured() === false` means no key is set, and {@see check()} then allows everything.
 * That is today's behaviour preserved on purpose, so installing this cannot lock out a caller
 * that has not been given the key yet; enforcing it is a deployment step (set the var on
 * lingua and on every app), not a code change.
 */
final readonly class LinguaKeyGuard
{
    /** Header LinguaClient already sends. Bearer is accepted as a fallback. */
    public const HEADER = LinguaApi::HEADER_API_KEY;

    public function __construct(private ?string $expectedKey = null)
    {
    }

    public function isConfigured(): bool
    {
        return ($this->expectedKey ?? '') !== '';
    }

    /**
     * @return bool true when the request may proceed: either no key is configured, or the
     *              presented key matches
     */
    public function check(Request $request): bool
    {
        if (!$this->isConfigured()) {
            return true;
        }

        return $this->isValid($this->presentedKey($request));
    }

    public function isValid(?string $presented): bool
    {
        if (!$this->isConfigured()) {
            return true;
        }

        // hash_equals rejects null/'' as well, but only after a constant-time comparison
        // against a real string, so the empty case is answered first and explicitly.
        if ($presented === null || $presented === '') {
            return false;
        }

        return hash_equals((string) $this->expectedKey, $presented);
    }

    /** X-Api-Key first, then `Authorization: Bearer <key>` -- LinguaClient sends both. */
    public function presentedKey(Request $request): ?string
    {
        $key = $request->headers->get(self::HEADER);
        if ($key !== null && $key !== '') {
            return $key;
        }

        $authorization = (string) $request->headers->get('Authorization', '');
        if (stripos($authorization, 'Bearer ') === 0) {
            return trim(substr($authorization, 7)) ?: null;
        }

        return null;
    }
}
