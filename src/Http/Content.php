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

namespace Polunich\WpJsonApi\Http;

use ArrayIterator;
use Generator;
use InvalidArgumentException;
use Iterator;
use IteratorIterator;
use Polunich\WpJsonApi\Text\Printable;
use RuntimeException;
use UnexpectedValueException;

/**
 * the content of a response that is no JSON:API document, in one of four forms:
 *
 * - `bytes()`: a string already in memory;
 * - `file()`: a file by its path, read piece by piece and never whole;
 * - `stream()`: an open stream resource, read piece by piece from its position on;
 * - `chunks()`: pieces an iterable yields, written as they come, such as the rows of an export.
 *
 * the library owns what a content holds: it opens the file,
 * and closes the file or a handed stream once the content is written, or skipped as for HEAD.
 *
 * `open()` runs before the status is sent, so for a file that cannot be opened,
 * or chunks that fail before their first piece, the library still responds with an error;
 * a later failure can only cut the content
 */
final class Content
{
    /** the octets read from a file or a stream per piece */
    private const PIECE = 16384;

    /** @var resource|null the file or the stream, while it is open */
    private mixed $handle = null;

    /** @var Iterator<mixed, mixed>|null the chunks, run to their first piece */
    private ?Iterator $pieces = null;

    private bool $opened = false;

    /**
     * exactly one source is set
     *
     * @param ?iterable<mixed> $chunks
     * @param resource|null    $stream
     */
    private function __construct(
        private readonly ?string $bytes = null,
        private readonly ?string $path = null,
        private readonly ?iterable $chunks = null,
        mixed $stream = null,
    ) {
        $this->handle = $stream;
    }

    public function __destruct()
    {
        $this->close();
    }

    public static function bytes(string $bytes): self
    {
        return new self(bytes: $bytes);
    }

    public static function file(string $path): self
    {
        return new self(path: $path);
    }

    /**
     * @throws InvalidArgumentException when the value is no open stream, or one that does not read
     */
    public static function stream(mixed $stream): self
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException(sprintf(
                'a content stream is an open stream resource, got %s',
                get_debug_type($stream),
            ));
        }

        $mode = stream_get_meta_data($stream)['mode'];

        if (!str_contains($mode, 'r') && !str_contains($mode, '+')) {
            throw new InvalidArgumentException(sprintf(
                'a content stream is read, and this one was opened with the mode "%s", which does not read',
                $mode,
            ));
        }

        return new self(stream: $stream);
    }

    /**
     * a piece that is no string fails the content as a throw from the iterable does
     *
     * @param iterable<mixed> $chunks
     */
    public static function chunks(iterable $chunks): self
    {
        return new self(chunks: $chunks);
    }

    /**
     * @throws RuntimeException         when the file cannot be opened, or the stream was closed
     * @throws UnexpectedValueException when the first chunk is no string
     *
     * @internal
     */
    public function open(): void
    {
        if ($this->opened) {
            return;
        }

        $this->opened = true;

        if ($this->path !== null) {
            $this->handle = self::openFile($this->path);
        } elseif ($this->chunks !== null) {
            $this->pieces = self::iterator($this->chunks);
            $this->pieces->rewind();

            if ($this->pieces->valid()) {
                self::piece($this->pieces->current());
            }
        } elseif ($this->bytes === null && !is_resource($this->handle)) {
            throw new RuntimeException('the content stream was closed before the response was written');
        }
    }

    /**
     * the octets still to write, where known up front: the bytes,
     * or what is left of an open plain file; null for anything else
     *
     * @internal
     */
    public function size(): ?int
    {
        if ($this->bytes !== null) {
            return strlen($this->bytes);
        }

        if (!is_resource($this->handle) || stream_get_meta_data($this->handle)['wrapper_type'] !== 'plainfile') {
            return null;
        }

        $stat = fstat($this->handle);
        $position = ftell($this->handle);

        return $stat === false || $position === false ? null : max(0, $stat['size'] - $position);
    }

    /**
     * the file or the stream is closed once the generator ends, fails or is dropped
     *
     * @return Generator<int, string>
     *
     * @throws UnexpectedValueException when a chunk is no string
     * @throws RuntimeException         when the file or the stream cannot be read
     *
     * @internal
     */
    public function pieces(): Generator
    {
        $this->open();

        try {
            if ($this->bytes !== null) {
                yield $this->bytes;

                return;
            }

            if ($this->pieces !== null) {
                for (; $this->pieces->valid(); $this->pieces->next()) {
                    yield self::piece($this->pieces->current());
                }

                return;
            }

            while (is_resource($this->handle) && !feof($this->handle)) {
                $piece = fread($this->handle, self::PIECE);

                if ($piece === false) {
                    throw new RuntimeException('the content could not be read to its end');
                }

                if ($piece !== '') {
                    yield $piece;
                }
            }
        } finally {
            $this->close();
        }
    }

    /**
     * leaves the chunks where they stand
     *
     * @internal
     */
    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        $this->handle = null;
    }

    /**
     * @return resource
     *
     * @throws RuntimeException
     */
    private static function openFile(string $path): mixed
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $handle = is_dir($path) ? false : fopen($path, 'rb');
        } finally {
            restore_error_handler();
        }

        if ($handle === false) {
            throw new RuntimeException(sprintf(
                'the content file "%s" cannot be opened for reading%s',
                Printable::of($path),
                $warning === null ? '' : ': ' . $warning,
            ));
        }

        return $handle;
    }

    /**
     * @param iterable<mixed> $chunks
     *
     * @return Iterator<mixed, mixed>
     */
    private static function iterator(iterable $chunks): Iterator
    {
        if (is_array($chunks)) {
            return new ArrayIterator($chunks);
        }

        return $chunks instanceof Iterator ? $chunks : new IteratorIterator($chunks);
    }

    /**
     * @throws UnexpectedValueException
     */
    private static function piece(mixed $piece): string
    {
        if (!is_string($piece)) {
            throw new UnexpectedValueException(sprintf(
                'a chunk of a content is a string, got %s',
                get_debug_type($piece),
            ));
        }

        return $piece;
    }
}
