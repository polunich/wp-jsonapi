<?php

/**
 * wp-jsonapi: JSON:API 1.1 on the WordPress REST API.
 * Copyright (C) 2026 Polunich
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Polunich\WpJsonApi\Tests\Unit\Authentication;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Authentication\Challenge;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(Challenge::class)]
final class ChallengeTest extends TestCase
{
    public function testTheChallengeIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(Challenge::class))->isReadOnly());
    }

    public function testTheConstructorParameterNamesAreThePublicApi(): void
    {
        $names = array_map(
            static function (ReflectionParameter $parameter): string {
                return $parameter->getName();
            },
            (new ReflectionMethod(Challenge::class, '__construct'))->getParameters(),
        );

        self::assertSame(['scheme', 'parameters', 'alwaysQuoted', 'neverQuoted'], $names);
    }

    public function testTheApplicationPasswordsChallengeIsBasicWithTheWordPressRealm(): void
    {
        $challenge = new Challenge('Basic', ['realm' => 'WordPress']);

        self::assertSame('Basic realm="WordPress"', $challenge->toHeaderValue());
    }

    public function testASchemeWithoutAParameterIsAWholeChallenge(): void
    {
        $challenge = new Challenge('Negotiate');

        self::assertSame('Negotiate', $challenge->toHeaderValue());
    }

    #[DataProvider('realms')]
    public function testTheRealmIsAlwaysWrittenAsAQuotedString(string $name, string $value, string $expected): void
    {
        $challenge = new Challenge('Basic', [$name => $value]);

        self::assertSame('Basic ' . $expected, $challenge->toHeaderValue());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function realms(): array
    {
        return [
            'a realm that is a token' => ['realm', 'WordPress', 'realm="WordPress"'],
            'a realm holding a space' => ['realm', 'WordPress API', 'realm="WordPress API"'],
            'a realm holding a quote' => ['realm', 'the "api"', 'realm="the \"api\""'],
            'a realm holding a backslash' => ['realm', 'a\b', 'realm="a\\\\b"'],
            'an empty realm' => ['realm', '', 'realm=""'],
            'the name spelled in another case' => ['Realm', 'WordPress', 'Realm="WordPress"'],
            'the name spelled in upper case' => ['REALM', 'WordPress', 'REALM="WordPress"'],
        ];
    }

    public function testTheRealmKeepsTheCaseItWasGivenIn(): void
    {
        $challenge = new Challenge('Basic', ['realm' => 'WordPress']);

        self::assertNotSame('Basic realm="wordpress"', $challenge->toHeaderValue());
        self::assertSame('Basic realm="WordPress"', $challenge->toHeaderValue());
    }

    /**
     * RFC 7617 2.1 quotes `charset="UTF-8"` in its example; the token form carries the same value
     */
    #[DataProvider('parametersBesideTheRealm')]
    public function testAParameterBesideTheRealmIsWrittenAsATokenWhereItIsOne(
        string $name,
        string $value,
        string $expected,
    ): void {
        $challenge = new Challenge('Newauth', [$name => $value]);

        self::assertSame('Newauth ' . $expected, $challenge->toHeaderValue());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function parametersBesideTheRealm(): array
    {
        return [
            'a value that is a token' => ['charset', 'UTF-8', 'charset=UTF-8'],
            'a value of digits' => ['type', '1', 'type=1'],
            'a value holding a space' => ['title', 'the login', 'title="the login"'],
            'a value holding a delimiter' => ['scope', 'read:pets', 'scope="read:pets"'],
            'a value holding a quote' => ['title', 'Login to "apps"', 'title="Login to \"apps\""'],
            'an empty value' => ['error', '', 'error=""'],
        ];
    }

    /**
     * the expected value is the challenge of the example of RFC 7616 3.9.2 on one line,
     * built with the two lists of RFC 7616 3.3: `qop` is quoted although `auth` is a token
     */
    public function testTheDigestChallengeIsWrittenAsRfc7616WritesItsExample(): void
    {
        $challenge = new Challenge(
            'Digest',
            [
                'realm' => 'api@example.org',
                'qop' => 'auth',
                'algorithm' => 'SHA-512-256',
                'nonce' => '5TsQWLVdgBdmrQ0XsxbDODV+57QdFR34I9HAbC/RVvkK',
                'opaque' => 'HRPCssKJSGjCrkzDg8OhwpzCiGPChXYjwrI2QmXDnsOS',
                'charset' => 'UTF-8',
                'userhash' => 'true',
            ],
            alwaysQuoted: ['realm', 'domain', 'nonce', 'opaque', 'qop'],
            neverQuoted: ['stale', 'algorithm'],
        );

        self::assertSame(
            'Digest realm="api@example.org", qop="auth", algorithm=SHA-512-256,'
            . ' nonce="5TsQWLVdgBdmrQ0XsxbDODV+57QdFR34I9HAbC/RVvkK",'
            . ' opaque="HRPCssKJSGjCrkzDg8OhwpzCiGPChXYjwrI2QmXDnsOS", charset=UTF-8, userhash=true',
            $challenge->toHeaderValue(),
        );
    }

    /**
     * a hexadecimal nonce is a token, yet Digest pins it to a quoted-string (RFC 7616 3.3)
     *
     * @param list<string> $alwaysQuoted
     */
    #[DataProvider('parametersPinnedToAQuotedString')]
    public function testAParameterPinnedToAQuotedStringIsQuotedWhateverItHolds(
        string $name,
        string $value,
        array $alwaysQuoted,
        string $expected,
    ): void {
        $challenge = new Challenge('Digest', [$name => $value], alwaysQuoted: $alwaysQuoted);

        self::assertSame('Digest ' . $expected, $challenge->toHeaderValue());
    }

    /**
     * @return array<string, array{string, string, list<string>, string}>
     */
    public static function parametersPinnedToAQuotedString(): array
    {
        return [
            'a hexadecimal nonce' => ['nonce', '7ypf1b3bd0b37e0a', ['nonce'], 'nonce="7ypf1b3bd0b37e0a"'],
            'a value holding a space' => ['domain', '/a /b', ['domain'], 'domain="/a /b"'],
            'an empty value' => ['opaque', '', ['opaque'], 'opaque=""'],
            'the name pinned in another case' => ['qop', 'auth', ['QOP'], 'qop="auth"'],
            'the parameter named in another case' => ['Nonce', 'abc', ['nonce'], 'Nonce="abc"'],
            'one of several pinned names' => ['opaque', 'abc', ['nonce', 'opaque'], 'opaque="abc"'],
            'the realm pinned as well' => ['realm', 'WordPress', ['realm'], 'realm="WordPress"'],
        ];
    }

    public function testAParameterPinnedToATokenIsWrittenBare(): void
    {
        $challenge = new Challenge(
            'Digest',
            ['stale' => 'false', 'Algorithm' => 'SHA-256'],
            neverQuoted: ['STALE', 'algorithm'],
        );

        self::assertSame('Digest stale=false, Algorithm=SHA-256', $challenge->toHeaderValue());
    }

    #[DataProvider('valuesThatAreNoTokenWherePinnedToOne')]
    public function testAValueThatIsNoTokenWhereTheSchemePinsOneIsRefused(
        string $name,
        string $value,
        string $pinned,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the authentication parameter "' . $name
            . '" holds a value that is no token, and its scheme pins it to one',
        );

        new Challenge('Digest', [$name => $value], neverQuoted: [$pinned]);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function valuesThatAreNoTokenWherePinnedToOne(): array
    {
        return [
            'a value holding a space' => ['algorithm', 'SHA 256', 'algorithm'],
            'the empty value' => ['stale', '', 'stale'],
            'a value holding its own quotes' => ['algorithm', '"SHA-256"', 'algorithm'],
            'the name pinned in another case' => ['algorithm', 'SHA 256', 'ALGORITHM'],
            'the parameter named in another case' => ['Stale', 'not stale', 'stale'],
        ];
    }

    /**
     * refused even when the challenge carries no realm, since the pin belongs to the scheme
     *
     * @param array<array-key, string> $parameters
     */
    #[DataProvider('realmsPinnedToAToken')]
    public function testTheRealmCannotBePinnedToAToken(array $parameters, string $pinned): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the authentication parameter "' . $pinned
            . '" is the realm, which a sender may only write as a quoted-string',
        );

        new Challenge('Digest', $parameters, neverQuoted: [$pinned]);
    }

    /**
     * @return array<string, array{array<array-key, string>, string}>
     */
    public static function realmsPinnedToAToken(): array
    {
        return [
            'a challenge carrying the realm' => [['realm' => 'WordPress'], 'realm'],
            'a challenge carrying no realm' => [[], 'realm'],
            'the realm pinned in upper case' => [['realm' => 'WordPress'], 'REALM'],
        ];
    }

    /**
     * the message names both spellings as given, the first one of a list that repeats a name
     *
     * @param list<string> $alwaysQuoted
     * @param list<string> $neverQuoted
     */
    #[DataProvider('namesPinnedToBothNotations')]
    public function testANameCannotBePinnedToBothNotations(
        array $alwaysQuoted,
        array $neverQuoted,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new Challenge('Digest', ['nonce' => 'abc'], $alwaysQuoted, $neverQuoted);
    }

    /**
     * @return array<string, array{list<string>, list<string>, string}>
     */
    public static function namesPinnedToBothNotations(): array
    {
        return [
            'one spelling' => [
                ['nonce'],
                ['nonce'],
                'the authentication parameter "nonce" is pinned to a token and, as "nonce", to a quoted-string',
            ],
            'two spellings' => [
                ['Nonce'],
                ['NONCE'],
                'the authentication parameter "NONCE" is pinned to a token and, as "Nonce", to a quoted-string',
            ],
            'a name repeated in both lists' => [
                ['nonce', 'Nonce'],
                ['NONCE', 'nOnce'],
                'the authentication parameter "NONCE" is pinned to a token and, as "nonce", to a quoted-string',
            ],
            'a name the challenge does not carry' => [
                ['stale'],
                ['stale'],
                'the authentication parameter "stale" is pinned to a token and, as "stale", to a quoted-string',
            ],
        ];
    }

    public function testAPinnedNameTheChallengeDoesNotCarryChangesNothing(): void
    {
        $challenge = new Challenge(
            'Digest',
            ['realm' => 'api@example.org'],
            alwaysQuoted: ['realm', 'domain', 'nonce', 'opaque', 'qop'],
            neverQuoted: ['stale', 'algorithm'],
        );

        self::assertSame('Digest realm="api@example.org"', $challenge->toHeaderValue());
    }

    /**
     * the expected value is the second challenge of the example of RFC 9110 11.6.1,
     * character for character
     */
    public function testTheParametersAreWrittenAsTheExampleOfTheRfcWritesThem(): void
    {
        $challenge = new Challenge('Newauth', [
            'realm' => 'apps',
            'type' => '1',
            'title' => 'Login to "apps"',
        ]);

        self::assertSame('Newauth realm="apps", type=1, title="Login to \"apps\""', $challenge->toHeaderValue());
    }

    public function testNoWhitespaceIsWrittenAroundTheEqualsSign(): void
    {
        $challenge = new Challenge('Basic', ['realm' => 'WordPress', 'charset' => 'UTF-8']);

        self::assertStringNotContainsString(' =', $challenge->toHeaderValue());
        self::assertStringNotContainsString('= ', $challenge->toHeaderValue());
    }

    public function testTheParametersKeepTheOrderTheyWereDeclaredIn(): void
    {
        $challenge = new Challenge('Basic', ['charset' => 'UTF-8', 'realm' => 'WordPress']);

        self::assertSame('Basic charset=UTF-8, realm="WordPress"', $challenge->toHeaderValue());
    }

    #[DataProvider('valuesThatAreNoToken')]
    public function testASchemeThatIsNoTokenIsRefused(string $scheme): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an authentication scheme must be a token');

        new Challenge($scheme);
    }

    #[DataProvider('valuesThatAreNoToken')]
    public function testAParameterNameThatIsNoTokenIsRefused(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an authentication parameter name must be a token');

        new Challenge('Basic', [$name => 'WordPress']);
    }

    /**
     * a pinned name that is no token matches no parameter, so its pin would be lost silently
     */
    #[DataProvider('valuesThatAreNoToken')]
    public function testANameAlwaysQuotedThatIsNoTokenIsRefused(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an authentication parameter name must be a token');

        new Challenge('Digest', ['nonce' => 'abc'], alwaysQuoted: [$name]);
    }

    #[DataProvider('valuesThatAreNoToken')]
    public function testANameNeverQuotedThatIsNoTokenIsRefused(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an authentication parameter name must be a token');

        new Challenge('Digest', ['stale' => 'false'], neverQuoted: [$name]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function valuesThatAreNoToken(): array
    {
        return [
            'the empty value' => [''],
            'a value holding a space' => ['Ba sic'],
            'a value holding a delimiter' => ['Basic"'],
            'a value holding an equals sign' => ['Basic=1'],
            'a value holding a comma' => ['Basic,Bearer'],
            'a value holding a control character' => ["Basic\r\nX-Injected: 1"],
            'obs-text' => ["Basic\x80"],
        ];
    }

    /**
     * @param array<array-key, string> $parameters
     * @param list<string>             $alwaysQuoted
     * @param list<string>             $neverQuoted
     */
    #[DataProvider('refusedNames')]
    public function testARefusedNameReachesTheMessageAsAnEscape(
        string $scheme,
        array $parameters,
        array $alwaysQuoted,
        array $neverQuoted,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new Challenge($scheme, $parameters, $alwaysQuoted, $neverQuoted);
    }

    /**
     * @return array<string, array{
     *     string,
     *     array<array-key, string>,
     *     list<string>,
     *     list<string>,
     *     string,
     * }>
     */
    public static function refusedNames(): array
    {
        $refused = "Basic\r\nX-Injected: 1\0";
        $escaped = 'got "Basic\r\nX-Injected: 1\000"';

        return [
            'the scheme' => [$refused, [], [], [], 'an authentication scheme must be a token, ' . $escaped],
            'a parameter name' => [
                'Basic',
                [$refused => 'WordPress'],
                [],
                [],
                'an authentication parameter name must be a token, ' . $escaped,
            ],
            'a name always quoted' => [
                'Basic',
                [],
                [$refused],
                [],
                'an authentication parameter name must be a token, ' . $escaped,
            ],
            'a name never quoted' => [
                'Basic',
                [],
                [],
                [$refused],
                'an authentication parameter name must be a token, ' . $escaped,
            ],
        ];
    }

    #[DataProvider('valuesNoFieldCanCarry')]
    public function testAValueThatNoFieldCanCarryIsRefused(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('holds a value that no header field can carry');

        new Challenge('Basic', ['realm' => $value]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function valuesNoFieldCanCarry(): array
    {
        return [
            'a carriage return' => ["WordPress\r"],
            'a line feed' => ["WordPress\nWWW-Authenticate: Basic realm=\"other\""],
            'a NUL' => ["WordPress\0"],
            'a control character' => ["Word\x01Press"],
            'the delete character' => ["WordPress\x7f"],
        ];
    }

    /**
     * an array key cannot repeat, so two spellings of one name are the only way to give it twice.
     * the message quotes both as written, since the caller may never have written the lower case
     *
     * @param array<array-key, string> $parameters
     */
    #[DataProvider('parametersGivenTwice')]
    public function testAParameterGivenTwiceIsRefused(array $parameters, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new Challenge('Basic', $parameters);
    }

    /**
     * @return array<string, array{array<array-key, string>, string}>
     */
    public static function parametersGivenTwice(): array
    {
        return [
            'the lower-case spelling first' => [
                ['realm' => 'WordPress', 'Realm' => 'other'],
                'the authentication parameter "Realm" is given twice, first as "realm"',
            ],
            'no lower-case spelling at all' => [
                ['Realm' => 'WordPress', 'REALM' => 'other'],
                'the authentication parameter "REALM" is given twice, first as "Realm"',
            ],
        ];
    }

    public function testAParameterNamedWithDecimalDigitsIsWritten(): void
    {
        $challenge = new Challenge('Basic', ['0' => 'x', '1' => 'y'], alwaysQuoted: ['1'], neverQuoted: ['0']);

        self::assertSame([0, 1], array_keys($challenge->parameters));
        self::assertSame('Basic 0=x, 1="y"', $challenge->toHeaderValue());
    }

    /**
     * nothing is recased, although schemes and names match without regard to case:
     * a provider reads back what it declared, the pinned names included
     */
    public function testTheChallengeKeepsWhatItWasBuiltFrom(): void
    {
        $challenge = new Challenge('Basic', ['realm' => 'WordPress'], ['Nonce', 'nonce'], ['Stale']);

        self::assertSame('Basic', $challenge->scheme);
        self::assertSame(['realm' => 'WordPress'], $challenge->parameters);
        self::assertSame(['Nonce', 'nonce'], $challenge->alwaysQuoted);
        self::assertSame(['Stale'], $challenge->neverQuoted);
    }
}
