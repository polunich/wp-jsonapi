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

use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Http\UploadedFile;
use Polunich\WpJsonApi\Tests\Fixtures\WordPress\CoreFiles;
use Polunich\WpJsonApi\WordPress\RequestFactory;
use ReflectionClass;
use ReflectionProperty;
use WP_REST_Request;

/**
 * @internal
 */
#[CoversClass(RequestFactory::class)]
final class RequestFactoryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreFiles::load();
    }

    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(RequestFactory::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testTheMethodIsTheOneWordPressMatchedTheRouteWith(): void
    {
        $request = new WP_REST_Request('get', '/acme/v1/pets');
        $request->set_method('patch');

        self::assertSame('PATCH', (new RequestFactory())->fromWordPress($request)->method);
    }

    public function testAFieldKeepsEveryValueUnderItsName(): void
    {
        $request = new WP_REST_Request('GET', '/acme/v1/pets');
        $request->set_header('Content-Type', 'application/vnd.api+json');
        $request->set_header('Accept-Language', 'uk');
        $request->add_header('Accept-Language', 'en;q=0.5');

        $headers = (new RequestFactory())->fromWordPress($request)->headers;

        self::assertSame('application/vnd.api+json', $headers->line('content-type'));
        self::assertSame(['uk', 'en;q=0.5'], $headers->values('Accept-Language'));
    }

    public function testANameThatIsNoTokenIsLeftOut(): void
    {
        $request = new WP_REST_Request('GET', '/acme/v1/pets');
        $request->set_headers(['bad name' => 'x', 'accept' => 'application/vnd.api+json']);

        $headers = (new RequestFactory())->fromWordPress($request)->headers;

        self::assertSame(['accept' => ['application/vnd.api+json']], $headers->all());
    }

    public function testTheQueryIsHandedOverAsWordPressDecodedIt(): void
    {
        $query = ['page' => ['number' => '2'], 'rest_route' => '/acme/v1/pets', 0 => 'x'];
        $request = new WP_REST_Request('GET', '/acme/v1/pets');
        $request->set_query_params($query);

        self::assertSame($query, (new RequestFactory())->fromWordPress($request)->query);
    }

    public function testARouteParameterIsPercentDecodedOnce(): void
    {
        $factory = new RequestFactory();

        foreach (['a%2Fb' => 'a/b', 'a%252F' => 'a%2F', 'a+b' => 'a+b', '%C3%A9' => "\u{e9}"] as $sent => $id) {
            $request = new WP_REST_Request('GET', '/acme/v1/pets/' . $sent);
            $request->set_url_params(['id' => $sent]);

            self::assertSame(['id' => $id], $factory->fromWordPress($request)->path);
        }
    }

    /**
     * a type names the parameter of its id, and every parameter of a route arrives under its name
     */
    public function testEveryRouteParameterKeepsItsName(): void
    {
        $request = new WP_REST_Request('GET', '/acme/v1/customers/7/pets/%31');
        $request->set_url_params(['customer_id' => '7', 'pet_id' => '%31']);

        self::assertSame(
            ['customer_id' => '7', 'pet_id' => '1'],
            (new RequestFactory())->fromWordPress($request)->path,
        );
    }

    public function testAnythingButANamedStringIsNoRouteParameter(): void
    {
        $request = new WP_REST_Request('GET', '/acme/v1/pets/1');
        $request->set_url_params(['id' => '1', 'list' => ['x'], 0 => 'y']);

        self::assertSame(['id' => '1'], (new RequestFactory())->fromWordPress($request)->path);
    }

    public function testTheBodyIsTheRawContentOrTheEmptyString(): void
    {
        $factory = new RequestFactory();
        $sent = new WP_REST_Request('POST', '/acme/v1/pets');
        $sent->set_body('{"data":{}}');

        self::assertSame('{"data":{}}', $factory->fromWordPress($sent)->body);
        self::assertSame('', $factory->fromWordPress(new WP_REST_Request('POST', '/acme/v1/pets'))->body);
    }

    #[DataProvider('methods')]
    public function testAnUrlencodedBodyIsParsedFromItsBytes(string $method): void
    {
        $request = new WP_REST_Request($method, '/acme/v1/pets/1');
        $request->set_header('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        $request->set_body('name=Rex&tags[]=good&tags[]=boy&0=zero');

        $read = (new RequestFactory(0))->fromWordPress($request);

        self::assertSame(['name' => 'Rex', 'tags' => ['good', 'boy'], 0 => 'zero'], $read->form);
        self::assertSame([], $read->files);
        self::assertSame([], $request->get_body_params(), 'WordPress is not asked to parse the body');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function methods(): iterable
    {
        yield 'POST' => ['POST'];
        yield 'PUT' => ['PUT'];
        yield 'PATCH' => ['PATCH'];
    }

    public function testAMultipartBodyTakesTheFieldsAndFilesPhpStored(): void
    {
        $request = new WP_REST_Request('POST', '/acme/v1/pets/1/photos');
        $request->set_header('Content-Type', 'multipart/form-data; boundary=x');
        $request->set_body_params(['caption' => 'Rex at the park']);
        self::storeFiles($request, [
            'photo' => [
                'name' => 'rex.jpg',
                'full_path' => 'rex.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => '/tmp/phpA',
                'error' => UPLOAD_ERR_OK,
                'size' => 2048,
            ],
            'extras' => [
                'name' => ['front' => 'a.png', 'more' => ['b.png']],
                'type' => ['front' => 'image/png', 'more' => ['image/png']],
                'tmp_name' => ['front' => '/tmp/phpB', 'more' => ['']],
                'error' => ['front' => UPLOAD_ERR_OK, 'more' => [UPLOAD_ERR_INI_SIZE]],
                'size' => ['front' => 10, 'more' => [0]],
            ],
        ]);

        $read = (new RequestFactory(0))->fromWordPress($request);

        self::assertSame(['caption' => 'Rex at the park'], $read->form);
        self::assertEquals([
            'photo' => new UploadedFile('rex.jpg', 'image/jpeg', 2048, '/tmp/phpA', UPLOAD_ERR_OK),
            'extras' => [
                'front' => new UploadedFile('a.png', 'image/png', 10, '/tmp/phpB', UPLOAD_ERR_OK),
                'more' => [new UploadedFile('b.png', 'image/png', 0, '', UPLOAD_ERR_INI_SIZE)],
            ],
        ], $read->files);
        self::assertFalse($read->formDiscarded);
    }

    public function testAFileEntryOfAnotherShapeIsLeftOut(): void
    {
        $request = new WP_REST_Request('POST', '/acme/v1/pets/1/photos');
        $request->set_header('Content-Type', 'multipart/form-data; boundary=x');
        self::storeFiles($request, [
            'text' => 'no file',
            'broken' => [
                'name' => 'rex.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => '/tmp/phpA',
                'error' => '0',
                'size' => 1,
            ],
            'unknown' => [
                'name' => 'rex.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => '/tmp/phpA',
                'error' => 5,
                'size' => 1,
            ],
            'negative' => [
                'name' => 'rex.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => '/tmp/phpA',
                'error' => 0,
                'size' => -1,
            ],
        ]);

        self::assertSame([], (new RequestFactory(0))->fromWordPress($request)->files);
    }

    public function testABodyOfAnotherMediaTypeHasNoForm(): void
    {
        $request = new WP_REST_Request('POST', '/acme/v1/webhooks/payments');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body('{"name":"Rex"}');
        $request->set_body_params(['name' => 'Rex']);

        $read = (new RequestFactory(0))->fromWordPress($request);

        self::assertSame([], $read->form);
        self::assertSame('{"name":"Rex"}', $read->body);
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('discardedForms')]
    public function testAFormOfAPostLargerThanPostMaxSizeIsDiscarded(
        string $method,
        array $headers,
        int $limit,
        bool $expected,
    ): void {
        $request = new WP_REST_Request($method, '/acme/v1/pets/1/photos');

        foreach ($headers as $name => $value) {
            $request->set_header($name, $value);
        }

        self::assertSame($expected, (new RequestFactory($limit))->fromWordPress($request)->formDiscarded);
    }

    /**
     * @return iterable<string, array{string, array<string, string>, int, bool}>
     */
    public static function discardedForms(): iterable
    {
        $multipart = 'multipart/form-data; boundary=x';

        yield 'a multipart POST above the limit' => [
            'POST',
            ['Content-Type' => $multipart, 'Content-Length' => '9000'],
            8192,
            true,
        ];
        yield 'an urlencoded POST above the limit' => [
            'POST',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Content-Length' => '9000'],
            8192,
            true,
        ];
        yield 'a length more digits long than an int' => [
            'POST',
            ['Content-Type' => $multipart, 'Content-Length' => '99999999999999999999'],
            8192,
            true,
        ];
        yield 'a POST at the limit' => [
            'POST',
            ['Content-Type' => $multipart, 'Content-Length' => '8192'],
            8192,
            false,
        ];
        yield 'no limit' => ['POST', ['Content-Type' => $multipart, 'Content-Length' => '9000'], 0, false];
        yield 'a PUT, whose form PHP never reads' => [
            'PUT',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Content-Length' => '9000'],
            8192,
            false,
        ];
        yield 'another media type' => [
            'POST',
            ['Content-Type' => 'application/json', 'Content-Length' => '9000'],
            8192,
            false,
        ];
        yield 'no length' => ['POST', ['Content-Type' => $multipart], 8192, false];
    }

    /**
     * the limit is `post_max_size`, which bounds the whole body,
     * not `upload_max_filesize`, which bounds one file:
     * a form of exactly that many octets is kept, one octet more is discarded
     */
    #[DataProvider('lengthsAroundTheLimit')]
    public function testTheLimitIsThePostMaxSizeOfTheRunningPhpByDefault(int $beyond, bool $discarded): void
    {
        $limit = ini_parse_quantity((string) ini_get('post_max_size'));

        self::assertGreaterThan(0, $limit, 'the running PHP limits the size of a POST body');

        $request = new WP_REST_Request('POST', '/acme/v1/pets/1/photos');
        $request->set_header('Content-Type', 'multipart/form-data; boundary=x');
        $request->set_header('Content-Length', (string) ($limit + $beyond));

        self::assertSame($discarded, (new RequestFactory())->fromWordPress($request)->formDiscarded);
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function lengthsAroundTheLimit(): iterable
    {
        yield 'at the limit' => [0, false];
        yield 'one octet beyond it' => [1, true];
    }

    public function testNoAccessorThatParsesTheRequestIsCalled(): void
    {
        $request = new class ('GET', '/acme/v1/pets') extends WP_REST_Request {
            /**
             * @return never
             */
            #[Override]
            public function get_params()
            {
                throw new LogicException('get_params() was called');
            }

            /**
             * @return never
             */
            #[Override]
            public function get_json_params()
            {
                throw new LogicException('get_json_params() was called');
            }
        };

        self::assertSame('GET', (new RequestFactory())->fromWordPress($request)->method);
    }

    /**
     * stores `$files` where `serve_request()` puts `$_FILES`, as `set_file_params()` would,
     * but by reflection: the stub of that method types one file per name,
     * while PHP writes parallel arrays for a name with brackets,
     * and an extension may leave anything in the superglobal
     *
     * @param array<array-key, mixed> $files
     */
    private static function storeFiles(WP_REST_Request $request, array $files): void
    {
        $params = new ReflectionProperty(WP_REST_Request::class, 'params');
        $stored = $params->getValue($request);
        self::assertIsArray($stored);
        $stored['FILES'] = $files;
        $params->setValue($request, $stored);
    }
}
