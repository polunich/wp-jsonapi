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

namespace Polunich\WpJsonApi\Tests\Unit\Metadata;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Metadata\ResourceFieldName;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(ResourceFieldName::class)]
final class ResourceFieldNameTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(ResourceFieldName::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    /**
     * @param 'attribute'|'relationship' $field
     */
    #[DataProvider('fields')]
    public function testAMemberNameNamesAField(string $field): void
    {
        $this->expectNotToPerformAssertions();

        ResourceFieldName::check('first-name', $field);
        ResourceFieldName::check('identity', $field);
    }

    /**
     * @param 'attribute'|'relationship' $field
     */
    #[DataProvider('refusedNames')]
    public function testRefusesANameNoFieldCarries(string $name, string $field, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        ResourceFieldName::check($name, $field);
    }

    /**
     * @return iterable<string, array{'attribute'|'relationship'}>
     */
    public static function fields(): iterable
    {
        yield 'an attribute' => ['attribute'];
        yield 'a relationship' => ['relationship'];
    }

    /**
     * @return iterable<string, array{string, 'attribute'|'relationship', string}>
     */
    public static function refusedNames(): iterable
    {
        foreach (self::fields() as [$field]) {
            yield 'no member name, of ' . $field => [
                "first\nname",
                $field,
                $field . ' "first\nname" is no member name',
            ];
            yield 'an @-member, of ' . $field => ['@context', $field, $field . ' "@context" is an @-member'];
            yield 'type, of ' . $field => ['type', $field, $field . ' "type" shares the namespace of type and id'];
            yield 'id, of ' . $field => ['id', $field, $field . ' "id" shares the namespace of type and id'];
        }
    }
}
