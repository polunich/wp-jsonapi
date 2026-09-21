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

namespace Polunich\WpJsonApi\Tests\Unit\Error;

use JsonException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Document\DocumentEncoder;
use Polunich\WpJsonApi\Document\ErrorDocument;
use Polunich\WpJsonApi\Document\JsonApiObject;
use Polunich\WpJsonApi\Error\DefaultExceptionMapper;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorFactory;
use Polunich\WpJsonApi\Error\ExceptionMapper;
use Polunich\WpJsonApi\Error\InternalServerErrorException;
use Polunich\WpJsonApi\Error\LibraryErrorCatalog;
use Polunich\WpJsonApi\Tests\Fixtures\Error\ThrowableFactory;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Throwable;
use TypeError;
use UnexpectedValueException;

/**
 * @internal
 */
#[CoversClass(DefaultExceptionMapper::class)]
final class DefaultExceptionMapperTest extends TestCase
{
    public function testTheMapperMapsEveryThrowable(): void
    {
        $mapper = new DefaultExceptionMapper();

        self::assertInstanceOf(ExceptionMapper::class, $mapper);
        self::assertSame(Throwable::class, $mapper->exceptionClass());
        self::assertStringContainsString(
            '@internal',
            (string) (new ReflectionClass(DefaultExceptionMapper::class))->getDocComment(),
        );
    }

    public function testTheMapperTakesTheDebugFlagAloneAndItIsOffByDefault(): void
    {
        $parameters = (new ReflectionMethod(DefaultExceptionMapper::class, '__construct'))->getParameters();

        self::assertCount(1, $parameters);
        self::assertSame('debugOutputEnabled', $parameters[0]->getName());
        self::assertSame('bool', (string) $parameters[0]->getType());
        self::assertFalse($parameters[0]->getDefaultValue());
    }

    #[DataProvider('throwables')]
    public function testAThrowableBecomesA500WithoutItsMessage(Throwable $throwable): void
    {
        $exception = (new DefaultExceptionMapper())->map($throwable);

        self::assertInstanceOf(InternalServerErrorException::class, $exception);
        self::assertSame(500, $exception->status());
        self::assertSame($throwable, $exception->getPrevious());
        self::assertCount(1, $exception->descriptions());

        $description = $exception->descriptions()[0];

        self::assertSame(ErrorCode::InternalError, $description->code);
        self::assertSame([], $description->parameters);
        self::assertNull($description->pointer);
        self::assertNull($description->parameter);
        self::assertNull($description->header);
        self::assertSame([], $description->meta);
        self::assertNull($description->typeLink);
        self::assertNull($description->title);
        self::assertNull($description->detail);
        self::assertNull($description->status);
        self::assertStringNotContainsString('secret', $exception->getMessage());
    }

    /**
     * @return array<string, array{Throwable}>
     */
    public static function throwables(): array
    {
        return [
            'an exception' => [new RuntimeException('the secret database password is wrong')],
            'an error of the engine' => [new TypeError('secret argument must be of type int')],
            'an exception with a previous one' => [new LogicException('secret', 0, new RuntimeException('secret'))],
        ];
    }

    public function testDebugOutputListsTheThrowableAndEveryPreviousOne(): void
    {
        $root = new JsonException('Syntax error');
        $cause = new UnexpectedValueException('the body is no JSON', 0, $root);
        $thrown = new RuntimeException('the request could not be read', 0, $cause);

        $meta = (new DefaultExceptionMapper(true))->map($thrown)->descriptions()[0]->meta;

        self::assertSame(['exceptions'], array_keys($meta));
        self::assertIsArray($meta['exceptions']);
        self::assertCount(3, $meta['exceptions']);

        foreach ([$thrown, $cause, $root] as $index => $throwable) {
            $entry = $meta['exceptions'][$index];

            self::assertIsArray($entry);
            self::assertSame(['class', 'message', 'file', 'line', 'trace'], array_keys($entry));
            self::assertSame($throwable::class, $entry['class']);
            self::assertSame($throwable->getMessage(), $entry['message']);
            self::assertSame($throwable->getFile(), $entry['file']);
            self::assertSame($throwable->getLine(), $entry['line']);
        }
    }

    /**
     * the throwables are created in three functions, one of them in another file,
     * so that no entry can pass with the file or the trace of another
     */
    public function testDebugOutputDescribesEachThrowableOfTheChainByItself(): void
    {
        $root = ThrowableFactory::create('the body is no JSON');
        $cause = $this->causeOf($root);
        $thrown = new RuntimeException('the request could not be read', 0, $cause);

        $meta = (new DefaultExceptionMapper(true))->map($thrown)->descriptions()[0]->meta;

        self::assertIsArray($meta['exceptions']);
        self::assertNotSame($root->getFile(), $thrown->getFile());

        $functions = [__FUNCTION__, 'causeOf', 'create'];

        foreach ([$thrown, $cause, $root] as $index => $throwable) {
            $entry = $meta['exceptions'][$index];

            self::assertIsArray($entry);
            self::assertSame($throwable->getFile(), $entry['file']);
            self::assertSame($throwable->getLine(), $entry['line']);
            self::assertIsArray($entry['trace']);
            self::assertCount(count($throwable->getTrace()), $entry['trace']);
            self::assertIsArray($entry['trace'][0]);
            self::assertSame($functions[$index], $entry['trace'][0]['function']);
        }
    }

    public function testDebugOutputHoldingABadByteEncodesAfterTheFactory(): void
    {
        $exception = (new DefaultExceptionMapper(true))->map(new RuntimeException("the row holds caf\xe9"));
        $description = $exception->descriptions()[0];

        self::assertIsArray($description->meta['exceptions']);
        self::assertIsArray($description->meta['exceptions'][0]);
        self::assertSame("the row holds caf\xe9", $description->meta['exceptions'][0]['message']);

        $error = (new ErrorFactory(new LibraryErrorCatalog()))->create($description, $exception->status());
        $encoded = json_encode(
            (new DocumentEncoder())->encode(new ErrorDocument([$error], new JsonApiObject())),
            JSON_THROW_ON_ERROR,
        );
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertIsArray($decoded['errors']);
        self::assertIsArray($decoded['errors'][0]);
        self::assertSame('internal_error', $decoded['errors'][0]['code']);
        self::assertSame('internal server error', $decoded['errors'][0]['title']);
        self::assertIsArray($decoded['errors'][0]['meta']);
        self::assertIsArray($decoded['errors'][0]['meta']['exceptions']);
        self::assertIsArray($decoded['errors'][0]['meta']['exceptions'][0]);
        self::assertSame("the row holds caf\u{FFFD}", $decoded['errors'][0]['meta']['exceptions'][0]['message']);
    }

    /**
     * arguments are recorded here whatever php.ini says, so that the test sees them left out
     */
    public function testDebugOutputKeepsNoArgumentOfTheTrace(): void
    {
        $setting = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        $resource = fopen('php://memory', 'r');

        try {
            $thrown = $this->exceptionCreatedWith($resource, 'secret argument');
        } finally {
            ini_set('zend.exception_ignore_args', (string) $setting);
        }

        self::assertArrayHasKey('args', $thrown->getTrace()[0]);
        self::assertFalse(json_encode($thrown->getTrace()));

        if (is_resource($resource)) {
            fclose($resource);
        }

        $meta = (new DefaultExceptionMapper(true))->map($thrown)->descriptions()[0]->meta;

        self::assertIsArray($meta['exceptions']);
        self::assertIsArray($meta['exceptions'][0]);
        $trace = $meta['exceptions'][0]['trace'];
        self::assertIsArray($trace);
        $first = $trace[0];
        self::assertIsArray($first);
        self::assertSame(
            ['file' => __FILE__, 'function' => 'exceptionCreatedWith', 'class' => self::class, 'type' => '->'],
            array_diff_key($first, ['line' => true]),
        );
        self::assertIsInt($first['line']);

        foreach ($trace as $frame) {
            self::assertIsArray($frame);
            self::assertSame([], array_diff(array_keys($frame), ['file', 'line', 'class', 'type', 'function']));
        }

        $encoded = json_encode($meta, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret argument', $encoded);
    }

    private function causeOf(Throwable $previous): LogicException
    {
        return new LogicException('the body is no document', 0, $previous);
    }

    /**
     * @param resource|false $resource
     */
    private function exceptionCreatedWith(mixed $resource, string $text): RuntimeException
    {
        return new RuntimeException('failed with ' . gettype($resource) . ' and ' . strlen($text) . ' characters');
    }
}
