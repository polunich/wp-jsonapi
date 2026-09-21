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

namespace Polunich\WpJsonApi\Tests\Integration\WordPress;

use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use Polunich\WpJsonApi\Tests\Integration\WordPressTestCase;
use WP_Application_Passwords;
use WP_Error;

/**
 * the requests bypass `rest_api_loaded()`, which defines `REST_REQUEST`,
 * so `setUp()` filters `application_password_is_api_request` to true instead;
 * and WordPress checks an application password only once one is in use on the site,
 * so `setUp()` creates one for the administrator
 *
 * @internal
 */
#[CoversNothing]
final class ApplicationPasswordsChallengeTest extends WordPressTestCase
{
    private const BODY = '{"data": {"type": "pets", "id": "1", "attributes": {"name": "Changed"}}}';

    private string $password;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $created = WP_Application_Passwords::create_new_application_password(
            1,
            ['name' => 'integration ' . $this->name()],
        );

        if ($created instanceof WP_Error) {
            throw new LogicException($created->get_error_message());
        }

        $this->password = $created[0];
        $this->filter('application_password_is_api_request', static function (): bool {
            return true;
        });
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach (WP_Application_Passwords::get_user_application_passwords(1) as $item) {
            WP_Application_Passwords::delete_application_password(1, $item['uuid']);
        }

        parent::tearDown();
    }

    /**
     * the 401 is the library's denial, not WordPress's.
     * WordPress first resolves the user, and so checks the password,
     * in `rest_cookie_check_errors()` at priority 100 of `rest_authentication_errors`,
     * after `rest_application_password_check_errors()` at 90 has found no failure to report:
     * the request is dispatched anonymous, and the permission denies it
     */
    public function testAWrongPasswordGetsTheBasicChallenge(): void
    {
        $this->available(true);
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = 'not the password';

        $response = self::send('PATCH', '/pets/1', [], ['content-type' => self::JSON_API], self::BODY);

        self::assertSame(401, $response->status, $response->body);
        self::assertSame(self::JSON_API, $response->header('Content-Type'));
        self::assertSame('Basic realm="WordPress"', $response->header('WWW-Authenticate'));
        self::assertSame('unauthorized', $response->member('errors', 0, 'code'));
        self::assertInstanceOf(WP_Error::class, $GLOBALS['wp_rest_application_password_status'] ?? null);
        self::assertMatchesContract($response, '/pets/1', 'PATCH');
    }

    public function testTheRightPasswordAuthenticatesTheWrite(): void
    {
        $this->available(true);
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = $this->password;

        $response = self::send('PATCH', '/pets/1', [], ['content-type' => self::JSON_API], self::BODY);

        self::assertSame(200, $response->status, $response->body);
        self::assertSame('Changed', $response->member('data', 'attributes', 'name'));
    }

    public function testNoCredentialsGetNoChallenge(): void
    {
        $this->available(true);

        $response = self::send('PATCH', '/pets/1', [], ['content-type' => self::JSON_API], self::BODY);

        self::assertSame(403, $response->status, $response->body);
        self::assertNull($response->header('WWW-Authenticate'));
        self::assertSame('authentication_required', $response->member('errors', 0, 'code'));
        self::assertMatchesContract($response, '/pets/1', 'PATCH');
    }

    public function testUnavailablePasswordsGetNoChallenge(): void
    {
        $this->available(false);
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = $this->password;

        $response = self::send('PATCH', '/pets/1', [], ['content-type' => self::JSON_API], self::BODY);

        self::assertSame(403, $response->status, $response->body);
        self::assertNull($response->header('WWW-Authenticate'));
        self::assertSame('authentication_required', $response->member('errors', 0, 'code'));
    }

    /**
     * overrides the default, which holds on an HTTPS or a local site alone
     */
    private function available(bool $available): void
    {
        $this->filter('wp_is_application_passwords_available', static function () use ($available): bool {
            return $available;
        });
    }
}
