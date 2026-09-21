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

namespace Polunich\WpJsonApi\Tests\Fixtures\Store;

use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

/**
 * reads the declaration of a Store class for the tests that pin it: method signatures,
 * and the PHPDoc tags that carry what PHP cannot declare,
 * such as templates, list types and thrown failures
 *
 * @internal
 */
final class PortSignatureReader
{
    /**
     * method name => its parameters as `name:type`, and its return type
     *
     * @param class-string $interface
     *
     * @return array<string, array{list<string>, string}>
     */
    public static function methods(string $interface): array
    {
        $methods = [];

        foreach ((new ReflectionClass($interface))->getMethods() as $method) {
            $methods[$method->getName()] = [
                array_map(
                    static function (ReflectionParameter $parameter): string {
                        return $parameter->getName() . ':' . $parameter->getType();
                    },
                    $method->getParameters(),
                ),
                (string) $method->getReturnType(),
            ];
        }

        return $methods;
    }

    /**
     * the first word after each occurrence of the tag, such as a return type or a thrown class;
     * with `$wholeLine`, the rest of the line, description included,
     * such as the name and the bound of a template
     *
     * @template T of object
     *
     * @param ReflectionClass<T>|ReflectionMethod|ReflectionProperty $reflection
     *
     * @return list<string>
     */
    public static function tags(
        ReflectionClass|ReflectionMethod|ReflectionProperty $reflection,
        string $tag,
        bool $wholeLine = false,
    ): array {
        $pattern = $wholeLine
            ? '/^\s*\*\s*@' . preg_quote($tag, '/') . '\s+(.+?)\s*$/m'
            : '/^\s*\*\s*@' . preg_quote($tag, '/') . '\s+(\S+)/m';

        preg_match_all($pattern, (string) $reflection->getDocComment(), $matches);

        return $matches[1];
    }

    /**
     * the classes of the method's `@throws` tags in tag order,
     * resolved against the namespace of the declaring interface
     *
     * @return list<string>
     */
    public static function throws(ReflectionMethod $method): array
    {
        $namespace = $method->getDeclaringClass()->getNamespaceName();

        return array_map(
            static function (string $name) use ($namespace): string {
                return str_starts_with($name, '\\') ? substr($name, 1) : $namespace . '\\' . $name;
            },
            self::tags($method, 'throws'),
        );
    }

    /**
     * the class name as a PHPDoc of the same namespace writes it
     *
     * @param class-string $class
     */
    public static function shortName(string $class): string
    {
        return (new ReflectionClass($class))->getShortName();
    }
}
