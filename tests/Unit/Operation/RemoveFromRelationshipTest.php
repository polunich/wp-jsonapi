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

namespace Polunich\WpJsonApi\Tests\Unit\Operation;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Localization\LanguageRange;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Operation\Precondition;
use Polunich\WpJsonApi\Operation\RemoveFromRelationship;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Inclusion\IncludePath;
use Polunich\WpJsonApi\Query\Inclusion\IncludePaths;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(RemoveFromRelationship::class)]
final class RemoveFromRelationshipTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(RemoveFromRelationship::class))->isReadOnly());
    }

    public function testCarriesTheParentTheRelationshipTheLinkageThePreconditionAndTheShapeOfTheResponse(): void
    {
        $parent = new Identifier('pets', '1');
        $linkage = Linkage::toMany([new Identifier('orders', '12'), new Identifier('orders', '13')]);
        $precondition = Precondition::listedVersions(['v3']);
        $include = new IncludePaths([new IncludePath(['orders', 'customer'])]);
        $fields = new SparseFieldsets(['orders' => ['address'], 'customers' => ['name']]);
        $languages = new LanguagePreferences([new LanguageRange('uk')]);

        $operation = new RemoveFromRelationship(
            parent: $parent,
            relationship: 'orders',
            linkage: $linkage,
            precondition: $precondition,
            include: $include,
            fields: $fields,
            languages: $languages,
            queryParameters: new Values([]),
        );

        self::assertSame($parent, $operation->parent);
        self::assertSame('orders', $operation->relationship);
        self::assertSame($linkage, $operation->linkage);
        self::assertSame($precondition, $operation->precondition);
        self::assertSame($precondition, $operation->precondition());
        self::assertSame($include, $operation->include);
        self::assertSame($fields, $operation->fields);
        self::assertSame($languages, $operation->languages);
    }

    public function testTheParametersNameTheParent(): void
    {
        self::assertSame(
            [
                ['parent', Identifier::class],
                ['relationship', 'string'],
                ['linkage', Linkage::class],
                ['precondition', '?' . Precondition::class],
                ['include', '?' . IncludePaths::class],
                ['fields', SparseFieldsets::class],
                ['languages', LanguagePreferences::class],
                ['queryParameters', Values::class],
            ],
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                (new ReflectionMethod(RemoveFromRelationship::class, '__construct'))->getParameters(),
            ),
        );
        self::assertFalse(property_exists(RemoveFromRelationship::class, 'target'));
    }

    #[DataProvider('toManyLinkages')]
    public function testTakesAToManyLinkage(Linkage $linkage): void
    {
        self::assertSame($linkage, self::operation($linkage, null, null)->linkage);
    }

    /**
     * @return iterable<string, array{Linkage}>
     */
    public static function toManyLinkages(): iterable
    {
        yield 'identifiers' => [Linkage::toMany([new Identifier('orders', '12'), new Identifier('orders', '13')])];
        yield 'empty' => [Linkage::toMany([])];
    }

    #[DataProvider('toOneLinkages')]
    public function testRefusesAToOneLinkage(Linkage $linkage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('members are removed from a to-many relationship only, and the linkage of'
            . ' relationship "orders" is to-one');

        self::operation($linkage, null, null);
    }

    /**
     * @return iterable<string, array{Linkage}>
     */
    public static function toOneLinkages(): iterable
    {
        yield 'an identifier' => [Linkage::toOne(new Identifier('orders', '12'))];
        yield 'null' => [Linkage::toOne(null)];
    }

    public function testIsTheRemoveFromRelationshipOperationOfTheTypeOfItsParent(): void
    {
        $operation = self::operation(Linkage::toMany([]), null, null);

        self::assertSame(OperationKind::RemoveFromRelationship, $operation->kind());
        self::assertSame('pets', $operation->type);
        self::assertSame('pets', $operation->type());
        self::assertSame('orders', $operation->relationship());
    }

    public function testARequestWithoutIfMatchCarriesNoPrecondition(): void
    {
        self::assertNull(self::operation(Linkage::toMany([]), null, null)->precondition());
    }

    public function testWithPreconditionLeavesTheOriginalUnchanged(): void
    {
        $operation = self::operation(
            Linkage::toMany([new Identifier('orders', '12')]),
            Precondition::anyVersion(),
            new IncludePaths([new IncludePath(['orders', 'customer'])]),
        );
        $precondition = Precondition::listedVersions(['v3']);

        $conditional = $operation->withPrecondition($precondition);

        self::assertNotSame($operation, $conditional);
        self::assertNotSame($precondition, $operation->precondition);
        self::assertSame($precondition, $conditional->precondition);
        self::assertSame($operation->parent, $conditional->parent);
        self::assertSame('pets', $conditional->type);
        self::assertSame('orders', $conditional->relationship);
        self::assertSame($operation->linkage, $conditional->linkage);
        self::assertSame($operation->include, $conditional->include);
        self::assertSame($operation->fields, $conditional->fields);
        self::assertSame($operation->languages, $conditional->languages);
    }

    public function testAnAbsentIncludeParameterIsNoEmptyOne(): void
    {
        $empty = new IncludePaths([]);

        self::assertNull(self::operation(Linkage::toMany([]), null, null)->include);
        self::assertSame($empty, self::operation(Linkage::toMany([]), null, $empty)->include);
    }

    #[DataProvider('pathsFromTheRelationship')]
    public function testTakesIncludePathsThatStartWithTheRelationship(IncludePaths $include): void
    {
        self::assertSame($include, self::operation(Linkage::toMany([]), null, $include)->include);
    }

    /**
     * @return iterable<string, array{IncludePaths}>
     */
    public static function pathsFromTheRelationship(): iterable
    {
        yield 'the relationship itself' => [new IncludePaths([new IncludePath(['orders'])])];
        yield 'a path through the relationship' => [new IncludePaths([
            new IncludePath(['orders']),
            new IncludePath(['orders', 'customer']),
        ])];
    }

    #[DataProvider('pathsElsewhere')]
    public function testRefusesAnIncludePathThatDoesNotStartWithTheRelationship(
        string $relationship,
        IncludePaths $include,
        string $path,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'include path "' . $path . '" does not start with relationship "' . $relationship
            . '" of a parent of type "pets", whose linkage is the primary data every included resource is'
            . ' reached from',
        );

        new RemoveFromRelationship(
            new Identifier('pets', '1'),
            $relationship,
            Linkage::toMany([]),
            null,
            $include,
            new SparseFieldsets([]),
            new LanguagePreferences([]),
            new Values([]),
        );
    }

    /**
     * @return iterable<string, array{string, IncludePaths, string}>
     */
    public static function pathsElsewhere(): iterable
    {
        yield 'another relationship of the parent' => [
            'orders',
            new IncludePaths([new IncludePath(['owner'])]),
            'owner',
        ];
        yield 'a path written from the related type' => [
            'orders',
            new IncludePaths([new IncludePath(['customer', 'supplier'])]),
            'customer.supplier',
        ];
        yield 'the relationship behind another one' => [
            'orders',
            new IncludePaths([new IncludePath(['owner', 'orders'])]),
            'owner.orders',
        ];
        yield 'a second path' => [
            'orders',
            new IncludePaths([new IncludePath(['orders']), new IncludePath(['tags'])]),
            'tags',
        ];
        yield 'a name that begins with the relationship' => [
            'orders',
            new IncludePaths([new IncludePath(['orders-archived'])]),
            'orders-archived',
        ];
        yield 'the relationship in another case' => [
            'orders',
            new IncludePaths([new IncludePath(['Orders'])]),
            'Orders',
        ];
        yield 'a name equal as a number' => ['10', new IncludePaths([new IncludePath(['1e1'])]), '1e1'];
    }

    public function testRefusesAnEmptyRelationshipName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('members are removed from a relationship of a parent of type "pets" by its'
            . ' name, which is never empty');

        new RemoveFromRelationship(
            new Identifier('pets', '1'),
            '',
            Linkage::toMany([]),
            null,
            null,
            new SparseFieldsets([]),
            new LanguagePreferences([]),
            new Values([]),
        );
    }

    #[DataProvider('refusalsNamingOutsideValues')]
    public function testEscapesAControlCharacterOfEveryNameItPutsIntoAMessage(Closure $construct, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $construct();
    }

    /**
     * @return iterable<string, array{Closure(): RemoveFromRelationship, string}>
     */
    public static function refusalsNamingOutsideValues(): iterable
    {
        yield 'the type of the parent of an empty relationship name' => [
            static function (): RemoveFromRelationship {
                return new RemoveFromRelationship(
                    new Identifier("pe\nts", '1'),
                    '',
                    Linkage::toMany([]),
                    null,
                    null,
                    new SparseFieldsets([]),
                    new LanguagePreferences([]),
                    new Values([]),
                );
            },
            'members are removed from a relationship of a parent of type "pe\nts" by its name, which is never empty',
        ];
        yield 'the relationship of a to-one linkage' => [
            static function (): RemoveFromRelationship {
                return new RemoveFromRelationship(
                    new Identifier('pets', '1'),
                    "or\tders",
                    Linkage::toOne(null),
                    null,
                    null,
                    new SparseFieldsets([]),
                    new LanguagePreferences([]),
                    new Values([]),
                );
            },
            'members are removed from a to-many relationship only, and the linkage of relationship "or\tders" is'
            . ' to-one',
        ];
        yield 'the relationship and the type of the parent of an include path that starts elsewhere' => [
            static function (): RemoveFromRelationship {
                return new RemoveFromRelationship(
                    new Identifier("pe\x7Fts", '1'),
                    "or\0ders",
                    Linkage::toMany([]),
                    null,
                    new IncludePaths([new IncludePath(['owner'])]),
                    new SparseFieldsets([]),
                    new LanguagePreferences([]),
                    new Values([]),
                );
            },
            'include path "owner" does not start with relationship "or\000ders" of a parent of type "pe\177ts", whose'
            . ' linkage is the primary data every included resource is reached from',
        ];
    }

    private static function operation(
        Linkage $linkage,
        ?Precondition $precondition,
        ?IncludePaths $include,
    ): RemoveFromRelationship {
        return new RemoveFromRelationship(
            parent: new Identifier('pets', '1'),
            relationship: 'orders',
            linkage: $linkage,
            precondition: $precondition,
            include: $include,
            fields: new SparseFieldsets(['customers' => ['name']]),
            languages: new LanguagePreferences([new LanguageRange('uk')]),
            queryParameters: new Values([]),
        );
    }
}
