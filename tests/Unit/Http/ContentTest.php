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

namespace Polunich\WpJsonApi\Tests\Unit\Http;

use ArrayIterator;
use Generator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Http\Content;
use ReflectionClass;
use RuntimeException;
use UnexpectedValueException;

/**
 * @internal
 */
#[CoversClass(Content::class)]
final class ContentTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/wp-jsonapi-content-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testTheClassIsFinalAndPublicApi(): void
    {
        $class = new ReflectionClass(Content::class);

        self::assertTrue($class->isFinal());
        self::assertDoesNotMatchRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testBytesAreWrittenAsOnePieceOfKnownSize(): void
    {
        $content = Content::bytes("id,name\n1,Rex\n");

        self::assertSame(14, $content->size());
        self::assertSame(["id,name\n1,Rex\n"], iterator_to_array($content->pieces(), false));
    }

    public function testAFileIsReadInPiecesAndClosedOnceWritten(): void
    {
        $path = $this->file(str_repeat('a', 40000));
        $content = Content::file($path);

        self::assertNull($content->size(), 'the size of a file is known once it is opened');

        $content->open();

        self::assertSame(40000, $content->size());

        $pieces = iterator_to_array($content->pieces(), false);

        self::assertSame([16384, 16384, 7232], array_map(strlen(...), $pieces));
        self::assertSame(str_repeat('a', 40000), implode('', $pieces));
        self::assertNull($content->size(), 'the file is closed');
    }

    public function testAFileThatCannotBeOpenedFailsWhenTheContentIsOpened(): void
    {
        $content = Content::file($this->directory . '/missing.pdf');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the content file "' . $this->directory . '/missing.pdf" cannot be opened for'
            . ' reading: ');

        $content->open();
    }

    public function testADirectoryIsNoFileToOpen(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the content file "' . $this->directory . '" cannot be opened for reading');

        Content::file($this->directory)->open();
    }

    public function testAStreamIsReadFromItsPositionOnAndClosed(): void
    {
        $stream = fopen($this->file('0123456789'), 'rb');
        self::assertIsResource($stream);
        fseek($stream, 4);
        $content = Content::stream($stream);

        self::assertSame(6, $content->size());
        self::assertSame('456789', implode('', iterator_to_array($content->pieces(), false)));
        self::assertFalse(is_resource($stream), 'the library closes the stream it was handed');
    }

    public function testAStreamOfNoPlainFileHasNoKnownSize(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, 'Rex');
        rewind($stream);

        $content = Content::stream($stream);

        self::assertNull($content->size());
        self::assertSame('Rex', implode('', iterator_to_array($content->pieces(), false)));
    }

    public function testAValueThatIsNoStreamIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a content stream is an open stream resource, got string');

        Content::stream('/tmp/rex.jpg');
    }

    public function testAStreamOpenedForWritingAloneIsRefused(): void
    {
        $stream = fopen($this->directory . '/out.csv', 'wb');
        self::assertIsResource($stream);

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('a content stream is read, and this one was opened with the mode "wb",'
                . ' which does not read');

            Content::stream($stream);
        } finally {
            fclose($stream);
        }
    }

    public function testAStreamClosedBeforeTheResponseFailsWhenOpened(): void
    {
        $stream = fopen($this->file('Rex'), 'rb');
        self::assertIsResource($stream);
        $content = Content::stream($stream);
        fclose($stream);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the content stream was closed before the response was written');

        $content->open();
    }

    public function testChunksRunToTheirFirstPieceWhenOpenedAndGoOnFromThere(): void
    {
        $ran = [];
        $content = Content::chunks((static function () use (&$ran): Generator {
            $ran[] = 'header';

            yield "id,name\n";

            $ran[] = 'rows';

            yield "1,Rex\n";
        })());

        $content->open();

        self::assertSame(['header'], $ran);
        self::assertNull($content->size());
        self::assertSame(["id,name\n", "1,Rex\n"], iterator_to_array($content->pieces(), false));
        self::assertSame(['header', 'rows'], $ran);
    }

    public function testChunksFailingBeforeTheirFirstPieceFailWhenOpened(): void
    {
        $rows = static function (): string {
            throw new RuntimeException('the orders cannot be read');
        };
        $content = Content::chunks((static function () use ($rows): Generator {
            yield $rows();
        })());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the orders cannot be read');

        $content->open();
    }

    public function testChunksOfAnArrayOrAnyTraversableAreWritten(): void
    {
        self::assertSame(['a', 'b'], iterator_to_array(Content::chunks(['a', 'b'])->pieces(), false));
        self::assertSame(['c'], iterator_to_array(Content::chunks(new ArrayIterator(['c']))->pieces(), false));
        self::assertSame([], iterator_to_array(Content::chunks([])->pieces(), false));
    }

    public function testAChunkThatIsNoStringIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('a chunk of a content is a string, got int');

        iterator_to_array(Content::chunks(['a', 1])->pieces(), false);
    }

    public function testAFirstChunkThatIsNoStringFailsWhenOpened(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('a chunk of a content is a string, got null');

        Content::chunks([null])->open();
    }

    public function testClosingAContentNeverWrittenClosesItsStream(): void
    {
        $stream = fopen($this->file('Rex'), 'rb');
        self::assertIsResource($stream);
        $content = Content::stream($stream);

        $content->close();
        $content->close();

        self::assertFalse(is_resource($stream));
    }

    public function testAContentLeftUnwrittenClosesItsStreamWhenDestroyed(): void
    {
        $stream = fopen($this->file('Rex'), 'rb');
        self::assertIsResource($stream);
        $content = Content::stream($stream);

        unset($content);

        self::assertFalse(is_resource($stream));
    }

    private function file(string $bytes): string
    {
        $path = $this->directory . '/' . bin2hex(random_bytes(4));
        file_put_contents($path, $bytes);

        return $path;
    }
}
