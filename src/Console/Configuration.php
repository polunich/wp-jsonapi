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
use JsonException;
use ReflectionException;
use ReflectionMethod;
use RuntimeException;
use stdClass;

/**
 * the contracts the `composer.json` of the project names under `extra.wp-jsonapi.api`,
 * each an absolute output path mapped to a closure that returns the contract JSON.
 *
 * `api` holds either one method, written `Class::method`, whose contract goes to `openapi.json`
 * beside `composer.json` or to the file `--output` names,
 * or an object that maps the path of each contract file, relative to the project, to its method.
 * the method is public, static and takes no argument, and returns the `ApiBuilder` of its API.
 * the command reaches the builder and the built API through their method names alone,
 * so it builds from any copy of the library, prefixed or not.
 *
 * two paths equal once `.`, `..` and repeated separators are resolved as text are refused:
 * a build would write one file twice. a symbolic link that joins two paths is not seen
 *
 * @internal
 */
final readonly class Configuration
{
    public const COMPOSER_FILE = 'composer.json';

    public const DEFAULT_CONTRACT = 'openapi.json';

    /**
     * the class and method names of PHP, the class possibly qualified
     */
    private const METHOD = '/^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*'
        . '(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*'
        . '::[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D';

    /**
     * @param string                           $file      the `composer.json` naming the contracts
     * @param non-empty-array<string, Closure> $contracts the contract closures by output path,
     *                                                    in the order of the file
     */
    private function __construct(
        public string $file,
        public array $contracts,
    ) {}

    /**
     * @param string  $projectDirectory the root of the project, which holds its `composer.json`
     * @param string  $workingDirectory resolves a relative `--output`;
     *                                  empty when the process cannot tell its working directory
     * @param ?string $output           the `--output` path, absolute or relative
     *
     * @throws RuntimeException when `composer.json` cannot be read or names no usable API,
     *                          or `--output` is given beside several APIs
     */
    public static function load(string $projectDirectory, string $workingDirectory, ?string $output): self
    {
        $file = rtrim($projectDirectory, '/\\') . DIRECTORY_SEPARATOR . self::COMPOSER_FILE;
        $api = self::api($file, self::read($file));

        if (is_string($api)) {
            $path = $output === null
                ? rtrim($projectDirectory, '/\\') . DIRECTORY_SEPARATOR . self::DEFAULT_CONTRACT
                : self::resolve($workingDirectory, $output);

            return new self($file, [$path => self::contract($file, $api)]);
        }

        if ($output !== null) {
            throw new RuntimeException(sprintf(
                '--output names one contract file, and %s maps several APIs to their files in extra.wp-jsonapi.api',
                self::quote($file),
            ));
        }

        return new self($file, self::contracts($file, $projectDirectory, $api));
    }

    /**
     * a URL or a stream wrapper does not count: a contract is a file on disk
     */
    public static function isAbsolute(string $path): bool
    {
        if (str_starts_with($path, '/')) {
            return true;
        }

        if (DIRECTORY_SEPARATOR !== '\\') {
            return false;
        }

        return str_starts_with($path, '\\') || preg_match('~^[A-Za-z]:[/\\\\]~', $path) === 1;
    }

    public static function quote(string $path): string
    {
        return '"' . self::printable($path) . '"';
    }

    /**
     * the one place of the module that makes a value printable for a message:
     * a control character from the command line or a file would otherwise break the message.
     * `Console` depends on no other module, so it keeps this copy of the rule of `Text\Printable`
     */
    public static function printable(string $text): string
    {
        return addcslashes($text, "\0..\37\177");
    }

    /**
     * @throws RuntimeException when the file is missing, unreadable or no JSON object
     */
    private static function read(string $file): stdClass
    {
        if (!is_file($file)) {
            throw new RuntimeException(sprintf(
                'the project has no %s, and the command reads the APIs it builds from its extra.wp-jsonapi.api',
                self::quote($file),
            ));
        }

        $json = is_readable($file) ? file_get_contents($file) : false;

        if ($json === false) {
            throw new RuntimeException(sprintf('the file %s cannot be read', self::quote($file)));
        }

        try {
            $composer = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new RuntimeException(
                sprintf('the file %s is no JSON: %s', self::quote($file), $failure->getMessage()),
                0,
                $failure,
            );
        }

        if (!$composer instanceof stdClass) {
            throw new RuntimeException(sprintf('the file %s holds no JSON object', self::quote($file)));
        }

        return $composer;
    }

    /**
     * @throws RuntimeException when `extra.wp-jsonapi.api` is missing,
     *                          or neither a string nor an object
     */
    private static function api(string $file, stdClass $composer): string|stdClass
    {
        $extra = $composer->extra ?? null;
        $options = $extra instanceof stdClass ? ($extra->{'wp-jsonapi'} ?? null) : null;
        $api = $options instanceof stdClass ? ($options->api ?? null) : null;

        if ($api === null) {
            throw new RuntimeException(sprintf(
                '%s names no API: add "extra": {"wp-jsonapi": {"api": "Vendor\\\\Package\\\\Api::define"}},'
                . ' a public static method that returns the ApiBuilder of the API',
                self::quote($file),
            ));
        }

        if (!is_string($api) && !$api instanceof stdClass) {
            throw new RuntimeException(sprintf(
                'extra.wp-jsonapi.api of %s is %s, and it is one method, written Class::method,'
                . ' or an object that maps the path of each contract file to its method',
                self::quote($file),
                get_debug_type($api),
            ));
        }

        return $api;
    }

    /**
     * @return non-empty-array<string, Closure>
     *
     * @throws RuntimeException when the object maps no API, a path is absolute or named twice,
     *                          or a method is no usable one
     */
    private static function contracts(string $file, string $projectDirectory, stdClass $api): array
    {
        $contracts = [];
        $canonical = [];

        foreach (get_object_vars($api) as $key => $method) {
            $path = (string) $key;

            if ($path === '' || self::isAbsolute($path)) {
                throw new RuntimeException(sprintf(
                    'extra.wp-jsonapi.api of %s maps the contract file %s, and a path of composer.json is'
                    . ' relative to the project',
                    self::quote($file),
                    self::quote($path),
                ));
            }

            if (!is_string($method)) {
                throw new RuntimeException(sprintf(
                    'extra.wp-jsonapi.api of %s maps the contract file %s to %s, and a contract is built by a'
                    . ' method, written Class::method',
                    self::quote($file),
                    self::quote($path),
                    get_debug_type($method),
                ));
            }

            $absolute = rtrim($projectDirectory, '/\\') . DIRECTORY_SEPARATOR . $path;
            $name = self::canonical($absolute);

            if (array_key_exists($name, $canonical)) {
                throw new RuntimeException(sprintf(
                    'extra.wp-jsonapi.api of %s maps %s and %s, which name one file',
                    self::quote($file),
                    self::quote($canonical[$name]),
                    self::quote($path),
                ));
            }

            $canonical[$name] = $path;
            $contracts[$absolute] = self::contract($file, $method);
        }

        if ($contracts === []) {
            throw new RuntimeException(sprintf(
                'extra.wp-jsonapi.api of %s maps no API, so a build would write nothing and a check would compare'
                . ' nothing',
                self::quote($file),
            ));
        }

        return $contracts;
    }

    /**
     * the method is checked here, and called only when the closure runs
     *
     * @throws RuntimeException when the method is not written Class::method,
     *                          or is no public static method that takes no argument
     */
    private static function contract(string $file, string $method): Closure
    {
        if (preg_match(self::METHOD, $method) !== 1) {
            throw new RuntimeException(sprintf(
                'extra.wp-jsonapi.api of %s names the API %s, and an API is named by its method, written'
                . ' Class::method',
                self::quote($file),
                self::quote($method),
            ));
        }

        [$class, $name] = explode('::', ltrim($method, '\\'), 2);

        if (!class_exists($class)) {
            throw new RuntimeException(sprintf(
                'the API %s names the class %s, which the autoloader of the project cannot load',
                self::quote($method),
                $class,
            ));
        }

        try {
            $reflection = new ReflectionMethod($class, $name);
        } catch (ReflectionException $failure) {
            throw new RuntimeException(
                sprintf('the API %s names a method that %s does not declare', self::quote($method), $class),
                0,
                $failure,
            );
        }

        if (!$reflection->isPublic() || !$reflection->isStatic() || $reflection->getNumberOfRequiredParameters() > 0) {
            throw new RuntimeException(sprintf(
                'the API %s is no public static method that takes no argument',
                self::quote($method),
            ));
        }

        return static function () use ($reflection, $method): mixed {
            $builder = $reflection->invoke(null);

            if (!is_object($builder) || !method_exists($builder, 'build')) {
                throw new RuntimeException(sprintf(
                    'the API %s returns %s, and the contract is built from the ApiBuilder it returns',
                    self::quote($method),
                    get_debug_type($builder),
                ));
            }

            $api = $builder->build();

            if (!is_object($api) || !method_exists($api, 'openApiJson')) {
                throw new RuntimeException(sprintf(
                    'the builder of the API %s builds %s, which writes no contract',
                    self::quote($method),
                    get_debug_type($api),
                ));
            }

            return $api->openApiJson();
        };
    }

    /**
     * @throws RuntimeException when the path is relative and the working directory is unknown
     */
    private static function resolve(string $workingDirectory, string $path): string
    {
        if (self::isAbsolute($path)) {
            return $path;
        }

        if ($workingDirectory === '') {
            throw new RuntimeException(sprintf(
                'the contract file %s is named relative to the working directory, which the process cannot'
                . ' tell; name it with an absolute --output',
                self::quote($path),
            ));
        }

        return rtrim($workingDirectory, '/\\') . DIRECTORY_SEPARATOR . $path;
    }

    /**
     * resolves `.`, `..` and repeated separators as text, without the file system;
     * `..` at the root stays at the root
     */
    private static function canonical(string $path): string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = str_replace('\\', '/', $path);
        }

        $root = preg_match('~^[A-Za-z]:/~', $path) === 1 ? substr($path, 0, 3) : '/';
        $segments = [];

        foreach (explode('/', substr($path, strlen($root))) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return $root . implode('/', $segments);
    }
}
