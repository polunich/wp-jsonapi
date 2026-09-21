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

namespace Polunich\WpJsonApi\Console;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * a contract is the string a closure of the configuration returns,
 * so the command loads no WordPress and uses no class of the library outside `Console`.
 *
 * the exit statuses follow `diff`.
 * every contract is built before the first file is written or compared,
 * so a failing closure leaves every file as it was.
 *
 * build writes only a file that differs, so a matching file keeps its modification time.
 * it writes the new bytes to a fresh file in the directory of the target
 * and moves that into place with `rename()`, atomic within one file system:
 * a build that stops midway leaves the old file, never half of the new one.
 * an existing file keeps its permissions, a new one gets the umask default.
 * a symbolic link is followed to its target, so the link stays a link
 *
 * @internal
 */
final readonly class OpenApiCommand
{
    public const SUCCESS = 0;

    public const DIFFERENCE = 1;

    public const TROUBLE = 2;

    /**
     * the Linux limit of symbolic links followed in one path resolution
     */
    private const SYMBOLIC_LINKS = 40;

    /** names drawn for the new file before the write gives up */
    private const ATTEMPTS = 10;

    /**
     * @param resource $stdout
     * @param resource $stderr
     *
     * @throws InvalidArgumentException when a stream is no open stream
     */
    public function __construct(
        private mixed $stdout,
        private mixed $stderr,
        private string $projectDirectory,
        private string $workingDirectory,
    ) {
        foreach (['standard output' => $stdout, 'standard error' => $stderr] as $name => $stream) {
            if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
                throw new InvalidArgumentException(sprintf(
                    'the command writes its %s to an open stream, and got %s',
                    $name,
                    get_debug_type($stream),
                ));
            }
        }
    }

    /**
     * @param list<string> $argv
     *
     * @return self::SUCCESS|self::DIFFERENCE|self::TROUBLE
     */
    public function run(array $argv): int
    {
        try {
            $arguments = Arguments::parse($argv);
        } catch (InvalidArgumentException $refusal) {
            $this->error($refusal->getMessage() . "\n\n" . Arguments::USAGE);

            return self::TROUBLE;
        }

        if ($arguments->help) {
            $this->write(Arguments::USAGE);

            return self::SUCCESS;
        }

        try {
            $configuration = Configuration::load($this->projectDirectory, $this->workingDirectory, $arguments->output);
            $contracts = self::contracts($configuration);

            return $arguments->action === Arguments::BUILD
                ? $this->build($contracts)
                : $this->check($contracts, $arguments->output);
        } catch (RuntimeException $trouble) {
            $this->error($trouble->getMessage() . "\n");

            return self::TROUBLE;
        }
    }

    /**
     * @return non-empty-array<string, string>
     *
     * @throws RuntimeException when a method or a build fails, or the contract is no string
     */
    private static function contracts(Configuration $configuration): array
    {
        $contracts = [];

        foreach ($configuration->contracts as $path => $contract) {
            try {
                $json = $contract();
            } catch (Throwable $failure) {
                throw new RuntimeException(
                    sprintf(
                        'the contract of %s failed with %s: %s',
                        Configuration::quote($path),
                        $failure::class,
                        Configuration::printable($failure->getMessage()),
                    ),
                    0,
                    $failure,
                );
            }

            if (!is_string($json)) {
                throw new RuntimeException(sprintf(
                    'the contract of %s is %s, and an API writes its contract as JSON text',
                    Configuration::quote($path),
                    get_debug_type($json),
                ));
            }

            $contracts[$path] = $json;
        }

        return $contracts;
    }

    /**
     * @param non-empty-array<string, string> $contracts
     *
     * @return self::SUCCESS
     *
     * @throws RuntimeException when a file cannot be read or written
     */
    private function build(array $contracts): int
    {
        foreach ($contracts as $path => $contract) {
            $file = self::target($path);

            if (self::read($file) === $contract) {
                $this->write(sprintf("%s is up to date\n", Configuration::quote($path)));

                continue;
            }

            self::replace($file, $contract);
            $this->write(sprintf("wrote %s\n", Configuration::quote($path)));
        }

        return self::SUCCESS;
    }

    /**
     * @param non-empty-array<string, string> $contracts
     * @param ?non-empty-string               $output    repeated in the build hint
     *
     * @return self::SUCCESS|self::DIFFERENCE
     *
     * @throws RuntimeException when a file cannot be read
     */
    private function check(array $contracts, ?string $output): int
    {
        $differs = false;

        foreach ($contracts as $path => $contract) {
            $bytes = self::read(self::target($path));

            if ($bytes === $contract) {
                $this->write(sprintf("%s matches its contract\n", Configuration::quote($path)));

                continue;
            }

            $differs = true;
            $this->write($bytes === null
                ? sprintf("%s does not exist\n", Configuration::quote($path))
                : sprintf(
                    "%s differs from its contract from line %d on\n",
                    Configuration::quote($path),
                    self::firstDifferentLine($contract, $bytes),
                ));
        }

        if (!$differs) {
            return self::SUCCESS;
        }

        $this->write(sprintf(
            "run wp-jsonapi openapi build%s to write the contracts\n",
            $output === null ? '' : ' --output=' . escapeshellarg($output),
        ));

        return self::DIFFERENCE;
    }

    /**
     * @throws RuntimeException when a link cannot be read, or the links do not end
     */
    private static function target(string $path): string
    {
        $file = $path;

        for ($links = 0; is_link($file); ++$links) {
            if ($links === self::SYMBOLIC_LINKS) {
                throw new RuntimeException(sprintf(
                    'the contract file %s leads through more than %d symbolic links',
                    Configuration::quote($path),
                    self::SYMBOLIC_LINKS,
                ));
            }

            $link = self::attempt(static function () use ($file): string|false {
                return readlink($file);
            }, sprintf(
                'the symbolic link %s cannot be read',
                Configuration::quote($file),
            ));

            $file = Configuration::isAbsolute($link) ? $link : dirname($file) . DIRECTORY_SEPARATOR . $link;
        }

        return $file;
    }

    /**
     * @throws RuntimeException when the path is a directory, or the file cannot be read
     */
    private static function read(string $file): ?string
    {
        if (!file_exists($file)) {
            return null;
        }

        if (is_dir($file)) {
            throw new RuntimeException(sprintf('the contract file %s is a directory', Configuration::quote($file)));
        }

        return self::attempt(
            static function () use ($file): string|false {
                return file_get_contents($file);
            },
            sprintf('the contract file %s cannot be read', Configuration::quote($file)),
        );
    }

    /**
     * @throws RuntimeException when the directory does not exist,
     *                          or the new file cannot be created, written or moved into place
     */
    private static function replace(string $file, string $bytes): void
    {
        $directory = dirname($file);

        if (!is_dir($directory)) {
            throw new RuntimeException(sprintf(
                'the contract file %s lies in a directory that does not exist',
                Configuration::quote($file),
            ));
        }

        [$temporary, $handle] = self::create($directory, basename($file));

        try {
            for ($written = 0; $written < strlen($bytes); $written += $count) {
                $count = self::attempt(
                    static function () use ($handle, $bytes, $written): int|false {
                        return fwrite($handle, substr($bytes, $written));
                    },
                    sprintf('the contract file %s cannot be written', Configuration::quote($file)),
                );

                if ($count === 0) {
                    throw new RuntimeException(sprintf(
                        'the contract file %s cannot be written: the stream takes no more bytes',
                        Configuration::quote($file),
                    ));
                }
            }

            self::attempt(
                static function () use ($handle): bool {
                    return fclose($handle);
                },
                sprintf('the contract file %s cannot be written', Configuration::quote($file)),
            );

            if (file_exists($file)) {
                $permissions = self::attempt(
                    static function () use ($file): int|false {
                        return fileperms($file);
                    },
                    sprintf('the permissions of the contract file %s cannot be read', Configuration::quote($file)),
                );
                self::attempt(
                    static function () use ($temporary, $permissions): bool {
                        return chmod($temporary, $permissions & 0o7777);
                    },
                    sprintf('the permissions of the contract file %s cannot be kept', Configuration::quote($file)),
                );
            }

            self::attempt(
                static function () use ($temporary, $file): bool {
                    return rename($temporary, $file);
                },
                sprintf('the contract file %s cannot be replaced', Configuration::quote($file)),
            );
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * @return array{string, resource}
     *
     * @throws RuntimeException when no new file can be created within `ATTEMPTS` names
     */
    private static function create(string $directory, string $name): array
    {
        $error = '';

        for ($attempt = 0; $attempt < self::ATTEMPTS; ++$attempt) {
            $temporary = $directory . DIRECTORY_SEPARATOR . '.' . $name . '.' . bin2hex(random_bytes(6));

            try {
                $handle = self::attempt(static function () use ($temporary) {
                    return fopen($temporary, 'x');
                }, '');

                return [$temporary, $handle];
            } catch (RuntimeException $failure) {
                $error = $failure->getMessage();
            }
        }

        throw new RuntimeException(sprintf(
            'no new file can be created in the directory %s%s',
            Configuration::quote($directory),
            $error,
        ));
    }

    /**
     * turns a `false` from a file system function into an exception carrying the warning PHP raised
     *
     * @template T
     *
     * @param Closure(): (T|false) $call
     * @param string               $failure the start of the message
     *
     * @return T
     *
     * @throws RuntimeException when the function returns false
     */
    private static function attempt(Closure $call, string $failure): mixed
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $result = $call();
        } finally {
            restore_error_handler();
        }

        if ($result === false) {
            throw new RuntimeException($failure . ($warning === null ? '' : ': ' . $warning));
        }

        return $result;
    }

    /**
     * counted from 1
     */
    private static function firstDifferentLine(string $contract, string $file): int
    {
        $length = min(strlen($contract), strlen($file));
        $offset = 0;

        while ($offset < $length && $contract[$offset] === $file[$offset]) {
            ++$offset;
        }

        return substr_count($contract, "\n", 0, $offset) + 1;
    }

    private function write(string $text): void
    {
        fwrite($this->stdout, $text);
    }

    private function error(string $text): void
    {
        fwrite($this->stderr, $text);
    }
}
