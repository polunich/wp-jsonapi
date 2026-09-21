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

use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Authentication\Challenge;
use Polunich\WpJsonApi\Authentication\ChallengeProvider;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\ServerRequest;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;

/**
 * `CoversClass` on an interface warns once coverage is collected:
 * php-code-coverage maps no interface as a class target
 *
 * @internal
 */
#[CoversNothing]
final class ChallengeProviderTest extends TestCase
{
    /**
     * the parameter name is public API: PHP matches named arguments against it
     */
    public function testThePortDeclaresTheRequestAndTheSchemesItReturnsChallengesFor(): void
    {
        $method = new ReflectionMethod(ChallengeProvider::class, 'challenges');
        [$request, $schemes] = $method->getParameters() + [null, null];

        self::assertInstanceOf(ReflectionParameter::class, $request);
        self::assertSame('request', $request->getName());
        self::assertSame(ServerRequest::class, self::typeName($request->getType()));
        self::assertInstanceOf(ReflectionParameter::class, $schemes);
        self::assertSame('schemes', $schemes->getName());
        self::assertSame('array', self::typeName($schemes->getType()));
        self::assertCount(2, $method->getParameters());
        self::assertSame('array', self::typeName($method->getReturnType()));
    }

    /**
     * the native return type is `array`, so the `list` type lives in the docblock alone
     */
    public function testThePortDeclaresAListOfChallenges(): void
    {
        $declaration = (new ReflectionMethod(ChallengeProvider::class, 'challenges'))->getDocComment();

        self::assertIsString($declaration);
        self::assertMatchesRegularExpression('/@return\s+list<Challenge>/', $declaration);
    }

    /**
     * @param list<string> $expected the header value of each challenge
     */
    #[DataProvider('requests')]
    public function testTheProviderReturnsChallengesForTheRequestItIsGiven(
        ?string $authorization,
        array $expected,
    ): void {
        $headers = $authorization === null ? new Headers() : new Headers(['Authorization' => $authorization]);
        $request = new ServerRequest('GET', $headers, [], [], '');

        $written = array_map(
            static function (Challenge $challenge): string {
                return $challenge->toHeaderValue();
            },
            self::applicationPasswords('WordPress')->challenges($request, [SecurityScheme::applicationPasswords()]),
        );

        self::assertSame($expected, $written);
    }

    /**
     * @return array<string, array{string|null, list<string>}>
     */
    public static function requests(): array
    {
        return [
            'Basic credentials' => ['Basic dXNlcjpwYXNz', ['Basic realm="WordPress"']],
            'credentials of another scheme' => ['Bearer abc', []],
            'no Authorization field' => [null, []],
        ];
    }

    public function testAProviderMayReturnNoChallengeAtAll(): void
    {
        $provider = new class implements ChallengeProvider {
            /**
             * @return list<Challenge>
             */
            #[Override]
            public function challenges(ServerRequest $request, array $schemes): array
            {
                return [];
            }
        };

        self::assertSame([], $provider->challenges(new ServerRequest('GET', new Headers(), [], [], ''), []));
    }

    /**
     * the Bearer challenge carries a realm, since RFC 6750 3 requires at least one auth-param
     */
    public function testAProviderMayReturnMoreThanOneChallenge(): void
    {
        $provider = new class implements ChallengeProvider {
            /**
             * @return list<Challenge>
             */
            #[Override]
            public function challenges(ServerRequest $request, array $schemes): array
            {
                return [
                    new Challenge('Basic', ['realm' => 'WordPress']),
                    new Challenge('Bearer', ['realm' => 'example']),
                ];
            }
        };

        $written = array_map(
            static function (Challenge $challenge): string {
                return $challenge->toHeaderValue();
            },
            $provider->challenges(new ServerRequest('GET', new Headers(), [], [], ''), []),
        );

        self::assertSame(['Basic realm="WordPress"', 'Bearer realm="example"'], $written);
    }

    /**
     * offers Basic only to a request that carries Basic credentials,
     * so a client that cannot respond to Basic gets no challenge
     */
    private static function applicationPasswords(string $realm): ChallengeProvider
    {
        return new class ($realm) implements ChallengeProvider {
            public function __construct(private readonly string $realm) {}

            /**
             * @return list<Challenge>
             */
            #[Override]
            public function challenges(ServerRequest $request, array $schemes): array
            {
                $authorization = $request->headers->line('Authorization') ?? '';

                if (stripos($authorization, 'Basic ') !== 0) {
                    return [];
                }

                return [new Challenge('Basic', ['realm' => $this->realm])];
            }
        };
    }

    private static function typeName(?ReflectionType $type): string
    {
        return $type instanceof ReflectionNamedType ? $type->getName() : '';
    }
}
