<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Tests\Security;

use PHPUnit\Framework\TestCase;
use Survos\LinguaBundle\Security\LinguaKeyGuard;
use Symfony\Component\HttpFoundation\Request;

/**
 * The shared secret between lingua and the apps that call it.
 *
 * The case that matters most is the unconfigured one: lingua has never had authentication,
 * so a guard that denied by default would lock out every existing caller the moment it
 * shipped. It must be inert until a key is set.
 */
final class LinguaKeyGuardTest extends TestCase
{
    private const KEY = 'sekrit-value-123';

    private function request(array $headers = []): Request
    {
        $server = [];
        foreach ($headers as $name => $value) {
            // Request::create() expects HTTP_ prefixed, underscored, upper-cased header names.
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create('/babel/pull', 'POST', server: $server);
    }

    public function testAnUnconfiguredGuardIsInertAndAllowsEverything(): void
    {
        $guard = new LinguaKeyGuard(null);

        self::assertFalse($guard->isConfigured());
        self::assertTrue($guard->check($this->request()), 'no key configured must not deny');
        self::assertTrue($guard->isValid(null));
        self::assertTrue($guard->isValid('anything at all'));
    }

    public function testAnEmptyStringCountsAsUnconfigured(): void
    {
        // '%env(default::LINGUA_API_KEY)%' resolves to '' or null when the var is unset, and
        // an empty key must not mean "the empty string is the password".
        $guard = new LinguaKeyGuard('');

        self::assertFalse($guard->isConfigured());
        self::assertTrue($guard->check($this->request()));
    }

    public function testAConfiguredGuardAcceptsTheKeyInXApiKey(): void
    {
        $guard = new LinguaKeyGuard(self::KEY);

        self::assertTrue($guard->isConfigured());
        self::assertTrue($guard->check($this->request(['X-Api-Key' => self::KEY])));
    }

    public function testAConfiguredGuardAcceptsABearerToken(): void
    {
        $guard = new LinguaKeyGuard(self::KEY);

        self::assertTrue($guard->check($this->request(['Authorization' => 'Bearer ' . self::KEY])));
    }

    public function testBearerSchemeIsMatchedCaseInsensitively(): void
    {
        $guard = new LinguaKeyGuard(self::KEY);

        self::assertTrue($guard->check($this->request(['Authorization' => 'bearer ' . self::KEY])));
    }

    public function testXApiKeyWinsOverAuthorizationWhenBothArePresent(): void
    {
        $guard = new LinguaKeyGuard(self::KEY);

        self::assertSame(
            self::KEY,
            $guard->presentedKey($this->request([
                'X-Api-Key' => self::KEY,
                'Authorization' => 'Bearer something-else',
            ])),
        );
    }

    public function testAConfiguredGuardDeniesAMissingKey(): void
    {
        $guard = new LinguaKeyGuard(self::KEY);

        self::assertFalse($guard->check($this->request()));
    }

    public function testAConfiguredGuardDeniesAWrongKey(): void
    {
        $guard = new LinguaKeyGuard(self::KEY);

        self::assertFalse($guard->check($this->request(['X-Api-Key' => 'wrong'])));
        self::assertFalse($guard->isValid('wrong'));
    }

    public function testAConfiguredGuardDeniesAnEmptyPresentedKey(): void
    {
        $guard = new LinguaKeyGuard(self::KEY);

        self::assertFalse($guard->isValid(''));
        self::assertFalse($guard->isValid(null));
    }

    /** A prefix of the real key must not pass -- hash_equals compares the whole string. */
    public function testAPrefixOfTheKeyIsRejected(): void
    {
        $guard = new LinguaKeyGuard(self::KEY);

        self::assertFalse($guard->isValid(substr(self::KEY, 0, -1)));
    }

    public function testAnEmptyBearerValueIsTreatedAsAbsent(): void
    {
        $guard = new LinguaKeyGuard(self::KEY);

        self::assertNull($guard->presentedKey($this->request(['Authorization' => 'Bearer '])));
        self::assertFalse($guard->check($this->request(['Authorization' => 'Bearer '])));
    }

    public function testANonBearerAuthorizationSchemeIsIgnored(): void
    {
        $guard = new LinguaKeyGuard(self::KEY);

        self::assertNull($guard->presentedKey($this->request(['Authorization' => 'Basic ' . self::KEY])));
    }
}
