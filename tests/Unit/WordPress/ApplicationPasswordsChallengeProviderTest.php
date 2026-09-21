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

namespace Polunich\WpJsonApi\Tests\Unit\WordPress;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Authentication\Challenge;
use Polunich\WpJsonApi\Authentication\ChallengeProvider;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\WordPress\ApplicationPasswordsChallengeProvider;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(ApplicationPasswordsChallengeProvider::class)]
final class ApplicationPasswordsChallengeProviderTest extends TestCase
{
    public function testTheClassIsAFinalReadonlyInternalChallengeProvider(): void
    {
        $class = new ReflectionClass(ApplicationPasswordsChallengeProvider::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertTrue($class->implementsInterface(ChallengeProvider::class));
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testTheDefaultRealmIsWordPress(): void
    {
        self::assertSame('WordPress', ApplicationPasswordsChallengeProvider::DEFAULT_REALM);
    }

    public function testBasicWithTheRealmAppliesWhereAllThreeHold(): void
    {
        $provider = self::provider(true, ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'xxxx'], 'My Site');
        $schemes = [SecurityScheme::restNonce(), SecurityScheme::applicationPasswords()];

        self::assertSame(
            ['Basic realm="My Site"'],
            array_map(static function (Challenge $challenge): string {
                return $challenge->toHeaderValue();
            }, $provider->challenges(self::request(), $schemes)),
        );
    }

    /**
     * @param list<SecurityScheme>    $schemes
     * @param array<array-key, mixed> $server
     */
    #[DataProvider('failures')]
    public function testNoChallengeAppliesWhereOneOfTheThreeFails(array $schemes, bool $available, array $server): void
    {
        self::assertSame([], self::provider($available, $server)->challenges(self::request(), $schemes));
    }

    /**
     * @return iterable<string, array{list<SecurityScheme>, bool, array<array-key, mixed>}>
     */
    public static function failures(): iterable
    {
        $credentials = ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'xxxx'];
        $schemes = [SecurityScheme::applicationPasswords()];

        yield 'the operation declares no scheme' => [[], true, $credentials];
        yield 'the operation takes the nonce alone' => [[SecurityScheme::restNonce()], true, $credentials];
        yield 'application passwords are unavailable' => [$schemes, false, $credentials];
        yield 'no credentials' => [$schemes, true, []];
        yield 'a user without a password' => [$schemes, true, ['PHP_AUTH_USER' => 'admin']];
        yield 'a password without a user' => [$schemes, true, ['PHP_AUTH_PW' => 'xxxx']];
        yield 'a password that is null' => [$schemes, true, ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => null]];
    }

    public function testTheQuestionsAreAskedWhenAChallengeIsAskedFor(): void
    {
        $asked = [];
        $provider = new ApplicationPasswordsChallengeProvider(
            'WordPress',
            static function () use (&$asked): bool {
                $asked[] = 'available';

                return true;
            },
            static function () use (&$asked): array {
                $asked[] = 'server';

                return ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'xxxx'];
            },
        );

        self::assertSame([], $asked);

        $provider->challenges(self::request(), [SecurityScheme::applicationPasswords()]);

        self::assertSame(['server', 'available'], $asked);
    }

    public function testARealmNoFieldCanCarryIsRefusedWhenTheProviderIsBuilt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::provider(true, [], "Word\x7fPress");
    }

    /**
     * @param array<array-key, mixed> $server
     */
    private static function provider(
        bool $available,
        array $server,
        string $realm = 'WordPress',
    ): ApplicationPasswordsChallengeProvider {
        return new ApplicationPasswordsChallengeProvider(
            $realm,
            static function () use ($available): bool {
                return $available;
            },
            static function () use ($server): array {
                return $server;
            },
        );
    }

    private static function request(): ServerRequest
    {
        return new ServerRequest('GET', new Headers(), [], [], '');
    }
}
