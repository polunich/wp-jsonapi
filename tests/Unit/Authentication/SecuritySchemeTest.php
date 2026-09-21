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
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use stdClass;

/**
 * @internal
 */
#[CoversClass(SecurityScheme::class)]
final class SecuritySchemeTest extends TestCase
{
    public function testTheSchemeIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(SecurityScheme::class))->isReadOnly());
    }

    public function testTheConstructorParameterNamesAreThePublicApi(): void
    {
        $names = array_map(
            static function (ReflectionParameter $parameter): string {
                return $parameter->getName();
            },
            (new ReflectionMethod(SecurityScheme::class, '__construct'))->getParameters(),
        );

        self::assertSame(['name', 'openApi'], $names);
    }

    public function testApplicationPasswordsIsDeclaredAsHttpBasic(): void
    {
        $scheme = SecurityScheme::applicationPasswords();

        self::assertSame('applicationPasswords', $scheme->name);
        self::assertSame(['type' => 'http', 'scheme' => 'basic'], $scheme->openApi);
    }

    public function testTheRestNonceIsAnApiKeyInTheNonceHeader(): void
    {
        $scheme = SecurityScheme::restNonce();

        self::assertSame('restNonce', $scheme->name);
        self::assertSame(
            ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-WP-Nonce'],
            array_intersect_key($scheme->openApi, ['type' => true, 'in' => true, 'name' => true]),
        );
        self::assertIsString($scheme->openApi['description'] ?? null);
    }

    public function testTheDefaultSchemeIsWrittenAsAJsonObject(): void
    {
        $json = json_encode(SecurityScheme::applicationPasswords()->openApi, JSON_THROW_ON_ERROR);

        self::assertSame('{"type":"http","scheme":"basic"}', $json);
    }

    /**
     * @param array<string, mixed> $openApi
     */
    #[DataProvider('schemesAProjectMayDeclare')]
    public function testAProjectDeclaresItsOwnScheme(string $name, array $openApi): void
    {
        $scheme = new SecurityScheme($name, $openApi);

        self::assertSame($name, $scheme->name);
        self::assertSame($openApi, $scheme->openApi);
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function schemesAProjectMayDeclare(): array
    {
        return [
            'a bearer token' => ['bearerAuth', ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT']],
            'an API key' => ['apiKey', ['type' => 'apiKey', 'name' => 'api-key', 'in' => 'header']],
            'mutual TLS' => ['mutualTLS', ['type' => 'mutualTLS']],
            'a name holding the characters the key pattern allows' => [
                'my.org_Scheme-1',
                ['type' => 'http', 'scheme' => 'basic'],
            ],
        ];
    }

    public function testAMemberThatIsAnEmptyObjectIsDeclaredWithAnObject(): void
    {
        $flow = new stdClass();
        $flow->authorizationUrl = 'https://a.example/oauth';
        $flow->scopes = new stdClass();

        $scheme = new SecurityScheme('oauth', ['type' => 'oauth2', 'flows' => ['implicit' => $flow]]);

        self::assertSame(
            '{"type":"oauth2","flows":{"implicit":{"authorizationUrl":"https:\/\/a.example\/oauth","scopes":{}}}}',
            json_encode($scheme->openApi, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * the plus sign, the tilde, the caret and the grave accent are the marks a slightly wider class
     * would admit; the caret and the grave accent lie inside the range `A-z`
     */
    #[DataProvider('namesNoComponentKeyMayHold')]
    public function testANameThatIsNoComponentKeyIsRefused(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a security scheme name must match');

        new SecurityScheme($name, ['type' => 'http', 'scheme' => 'basic']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function namesNoComponentKeyMayHold(): array
    {
        return [
            'the empty name' => [''],
            'a name holding a space' => ['application passwords'],
            'a name holding a slash' => ['application/passwords'],
            'a name holding a colon' => ['app:passwords'],
            'a name holding a brace' => ['{name}'],
            'a name holding a control character' => ["app\r\npasswords"],
            'a name outside US-ASCII' => ["appl\xc3\xa9"],
            'a name ending in a line feed' => ["applicationPasswords\n"],
            'a name holding a plus sign' => ['app+passwords'],
            'a name holding a tilde' => ['app~passwords'],
            'a name holding a caret' => ['app^passwords'],
            'a name holding a grave accent' => ['app`passwords'],
        ];
    }

    #[DataProvider('refusedNames')]
    public function testARefusedNameReachesTheMessageAsAnEscape(string $name, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new SecurityScheme($name, ['type' => 'http']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedNames(): array
    {
        return [
            'a line break inside the name' => [
                "app\r\npasswords",
                'a security scheme name must match ^[a-zA-Z0-9\.\-_]+$, got "app\r\npasswords"',
            ],
            'a line feed at the end of the name' => [
                "applicationPasswords\n",
                'a security scheme name must match ^[a-zA-Z0-9\.\-_]+$, got "applicationPasswords\n"',
            ],
        ];
    }

    public function testAnEmptyObjectIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('carries no Security Scheme Object: type is required');

        new SecurityScheme('applicationPasswords', []);
    }
}
