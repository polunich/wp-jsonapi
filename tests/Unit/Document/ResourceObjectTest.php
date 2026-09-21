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

namespace Polunich\WpJsonApi\Tests\Unit\Document;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Document\Link;
use Polunich\WpJsonApi\Document\Links;
use Polunich\WpJsonApi\Document\RelationshipObject;
use Polunich\WpJsonApi\Document\ResourceIdentifierObject;
use Polunich\WpJsonApi\Document\ResourceObject;
use ReflectionClass;
use ReflectionNamedType;

/**
 * @internal
 */
#[CoversClass(ResourceObject::class)]
final class ResourceObjectTest extends TestCase
{
    public function testTheResourceCarriesItsFieldsAndItsLinks(): void
    {
        $owner = RelationshipObject::toOne(
            new Links([
                'self' => 'http://example.com/pets/1/relationships/owner',
                'related' => 'http://example.com/pets/1/owner',
            ]),
            new ResourceIdentifierObject('customers', '9'),
        );

        $resource = new ResourceObject(
            'pets',
            '1',
            new Links(['self' => 'http://example.com/pets/1']),
            ['name' => 'Rex'],
            ['owner' => $owner],
            ['version' => 3],
        );

        self::assertSame('pets', $resource->type);
        self::assertSame('1', $resource->id);
        self::assertSame(['name' => 'Rex'], $resource->attributes);
        self::assertSame(['owner' => $owner], $resource->relationships);
        self::assertSame(['version' => 3], $resource->meta);
    }

    public function testFieldsAndMetaAreOptional(): void
    {
        $resource = new ResourceObject('pets', '1', new Links(['self' => 'http://example.com/pets/1']));

        self::assertSame([], $resource->attributes);
        self::assertSame([], $resource->relationships);
        self::assertNull($resource->meta);
    }

    public function testAResourceObjectWithoutLinksIsAccepted(): void
    {
        $links = new Links([]);

        self::assertSame($links, (new ResourceObject('pets', '1', $links))->links);
    }

    public function testASelfLinkObjectIsKept(): void
    {
        $resource = new ResourceObject(
            'pets',
            '1',
            new Links(['self' => new Link('http://example.com/pets/1', title: 'the pet')]),
        );

        self::assertTrue($resource->links->has('self'));
    }

    /**
     * @param array<array-key, mixed>              $attributes
     * @param array<array-key, RelationshipObject> $relationships
     */
    #[DataProvider('collidingFields')]
    public function testANameThatIsBothAnAttributeAndARelationshipIsRejected(
        array $attributes,
        array $relationships,
        string $names,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'the attributes and the relationships of a resource object share one namespace; the object with type'
            . ' "pets" and id "1" carries %s as an attribute and as a relationship',
            $names,
        ));

        new ResourceObject('pets', '1', self::selfLink(), $attributes, $relationships);
    }

    /**
     * @return iterable<string, array{
     *     array<array-key, mixed>,
     *     array<array-key, RelationshipObject>,
     *     string,
     * }>
     */
    public static function collidingFields(): iterable
    {
        yield 'one name' => [self::attributes(['owner']), self::relationships(['owner']), '"owner"'];
        yield 'one of several names' => [
            self::attributes(['name', 'owner']),
            self::relationships(['orders', 'owner']),
            '"owner"',
        ];
        yield 'two names' => [
            self::attributes(['owner', 'orders']),
            self::relationships(['owner', 'orders']),
            '"owner", "orders"',
        ];
        yield 'a name of digits, which PHP stores as an int key' => [
            self::attributes(['0']),
            self::relationships(['0']),
            '"0"',
        ];
    }

    /**
     * @param array<array-key, mixed>              $attributes
     * @param array<array-key, RelationshipObject> $relationships
     */
    #[DataProvider('reservedFieldNames')]
    public function testAFieldNamedTypeOrIdIsRejected(array $attributes, array $relationships, string $names): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'the fields of a resource object share their namespace with type and id; the object with type "pets"'
            . ' and id "1" carries %s among its fields',
            $names,
        ));

        new ResourceObject('pets', '1', self::selfLink(), $attributes, $relationships);
    }

    /**
     * @return iterable<string, array{
     *     array<array-key, mixed>,
     *     array<array-key, RelationshipObject>,
     *     string,
     * }>
     */
    public static function reservedFieldNames(): iterable
    {
        yield 'an attribute named type' => [self::attributes(['type']), [], '"type"'];
        yield 'an attribute named id' => [self::attributes(['id']), [], '"id"'];
        yield 'an attribute named type whose value is null' => [['type' => null], [], '"type"'];
        yield 'a relationship named type' => [[], self::relationships(['type']), '"type"'];
        yield 'a relationship named id' => [[], self::relationships(['id']), '"id"'];
        yield 'both names' => [self::attributes(['id', 'type']), [], '"id", "type"'];
        yield 'one in each member' => [self::attributes(['id']), self::relationships(['type']), '"id", "type"'];
    }

    /**
     * a name of digits is a legal member name and collides only with itself
     */
    public function testFieldsThatShareTheNamespaceWithoutCollidingAreAccepted(): void
    {
        $resource = new ResourceObject(
            'pets',
            '1',
            self::selfLink(),
            self::attributes(['0', 'name']),
            self::relationships(['1', 'owner']),
        );

        self::assertSame(['0', 'name'], self::keys($resource->attributes));
        self::assertSame(['1', 'owner'], self::keys($resource->relationships));
    }

    public function testTheObjectIsIdentifiedByTypeAndIdAndCarriesNoLid(): void
    {
        $class = new ReflectionClass(ResourceObject::class);

        self::assertFalse($class->hasProperty('lid'));

        $id = $class->getProperty('id')->getType();

        self::assertInstanceOf(ReflectionNamedType::class, $id);
        self::assertSame('string', $id->getName());
        self::assertFalse($id->allowsNull());
    }

    private static function selfLink(): Links
    {
        return new Links(['self' => 'http://example.com/pets/1']);
    }

    /**
     * built at runtime and typed `array<array-key, mixed>`,
     * since a name of digits becomes an int key;
     * passing it makes PHPStan hold the parameter types under test to that key type
     *
     * @param list<string> $names
     *
     * @return array<array-key, mixed>
     */
    private static function attributes(array $names): array
    {
        $attributes = [];

        foreach ($names as $name) {
            $attributes[$name] = 'a value';
        }

        return $attributes;
    }

    /**
     * @param list<string> $names
     *
     * @return array<array-key, RelationshipObject>
     */
    private static function relationships(array $names): array
    {
        $relationships = [];

        foreach ($names as $name) {
            $relationships[$name] = RelationshipObject::withoutLinkage(new Links([
                'self' => 'http://example.com/pets/1/relationships/' . $name,
                'related' => 'http://example.com/pets/1/' . $name,
            ]));
        }

        return $relationships;
    }

    /**
     * the names as strings, since PHP stores a name of digits as an int key
     *
     * @param array<array-key, mixed> $members
     *
     * @return list<string>
     */
    private static function keys(array $members): array
    {
        return array_map(static function (int|string $name): string {
            return (string) $name;
        }, array_keys($members));
    }
}
