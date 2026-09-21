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
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\NullableCodec;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Codec\UuidCodec;
use Polunich\WpJsonApi\Metadata\AttributeMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;
use Polunich\WpJsonApi\Metadata\SortPath;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(SortPath::class)]
final class SortPathTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(SortPath::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testTheCodecOfIdIsTheIdCodecOfTheType(): void
    {
        $resource = Samples::resource('pets');
        $path = new SortPath(FieldPath::id(), [], $resource, null);

        self::assertSame($resource->id->codec, $path->codec());
        self::assertFalse($path->nullable);
    }

    public function testTheCodecOfARelatedIdIsTheIdCodecOfTheRelatedType(): void
    {
        $customers = Samples::resource('customers', id: Samples::id(codec: new UuidCodec()));
        $never = new SortPath(
            new FieldPath(['owner', 'id']),
            [Samples::toOne('owner', 'customers', nullable: false)],
            $customers,
            null,
        );
        $maybe = new SortPath(new FieldPath(['owner', 'id']), [Samples::toOne('owner', 'customers')], $customers, null);

        self::assertSame($customers->id->codec, $never->codec());
        self::assertFalse($never->nullable);
        self::assertTrue($maybe->nullable);
    }

    public function testTheCodecOfAnAttributeIsItsOwn(): void
    {
        $attribute = Samples::attribute('rank', new IntegerCodec(), sortable: true);
        $path = new SortPath(
            new FieldPath(['rank']),
            [],
            Samples::resource('pets', attributes: [$attribute]),
            $attribute,
        );

        self::assertSame($attribute->codec, $path->codec());
    }

    /**
     * @param list<RelationshipMetadata> $relationships
     */
    #[DataProvider('nullablePaths')]
    public function testAPathIsNullableWhereAnAttributeOrARelationshipOnItMayBeNull(
        array $relationships,
        AttributeMetadata $attribute,
        bool $nullable,
    ): void {
        $segments = array_map(
            static function (RelationshipMetadata $relationship): string {
                return $relationship->name;
            },
            $relationships,
        );
        $path = new SortPath(
            new FieldPath([...$segments, $attribute->name]),
            $relationships,
            Samples::resource('customers', attributes: [$attribute]),
            $attribute,
        );

        self::assertSame($nullable, $path->nullable);
    }

    /**
     * @return iterable<string, array{list<RelationshipMetadata>, AttributeMetadata, bool}>
     */
    public static function nullablePaths(): iterable
    {
        $name = Samples::attribute('name', sortable: true);
        $nickname = Samples::attribute('nickname', new NullableCodec(new StringCodec()), sortable: true);

        yield 'an attribute that is never null' => [[], $name, false];
        yield 'an attribute that may be null' => [[], $nickname, true];
        yield 'through a relationship that is not nullable' => [
            [Samples::toOne('owner', 'customers', nullable: false)],
            $name,
            false,
        ];
        yield 'through a relationship that may be empty' => [[Samples::toOne('owner', 'customers')], $name, true];
    }

    /**
     * @param list<RelationshipMetadata> $relationships
     */
    #[DataProvider('impossibleResolutions')]
    public function testRefusesAResolutionThatIsNotThePath(
        FieldPath $path,
        array $relationships,
        ?AttributeMetadata $attribute,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new SortPath($path, $relationships, Samples::resource('customers'), $attribute);
    }

    /**
     * @return iterable<string, array{
     *     FieldPath,
     *     list<RelationshipMetadata>,
     *     ?AttributeMetadata,
     *     string,
     * }>
     */
    public static function impossibleResolutions(): iterable
    {
        $name = Samples::attribute('name', sortable: true);

        yield 'a relationship the path does not run through' => [
            new FieldPath(['name']),
            [Samples::toOne('owner', 'customers')],
            $name,
            'sort path "name" is resolved through the relationships "owner", which are not the ones it runs through',
        ];
        yield 'no relationship where the path runs through one' => [
            new FieldPath(['owner', 'name']),
            [],
            $name,
            'sort path "owner.name" is resolved through the relationships "", which are not the ones it runs through',
        ];
        yield 'id resolved to an attribute' => [
            FieldPath::id(),
            [],
            $name,
            'sort path "id" is resolved to attribute "name", and a sort path ends at a sortable attribute or at the'
            . ' id',
        ];
        yield 'a related id resolved to an attribute' => [
            new FieldPath(['owner', 'id']),
            [Samples::toOne('owner', 'customers')],
            $name,
            'sort path "owner.id" is resolved to attribute "name", and a sort path ends at a sortable attribute or'
            . ' at the id',
        ];
        yield 'an attribute path resolved to no attribute' => [
            new FieldPath(['name']),
            [],
            null,
            'sort path "name" is resolved to no attribute, and a sort path ends at a sortable attribute or at the id',
        ];
    }
}
