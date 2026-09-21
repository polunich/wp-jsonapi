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
use Polunich\WpJsonApi\Operation\ReplaceRelationship;
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
#[CoversClass(ReplaceRelationship::class)]
final class ReplaceRelationshipTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(ReplaceRelationship::class))->isReadOnly());
    }

    public function testCarriesTheParentTheRelationshipTheLinkageThePreconditionAndTheShapeOfTheResponse(): void
    {
        $parent = new Identifier('pets', '1');
        $linkage = Linkage::toOne(new Identifier('customers', '12'));
        $precondition = Precondition::listedVersions(['v3']);
        $include = new IncludePaths([new IncludePath(['owner', 'supplier'])]);
        $fields = new SparseFieldsets(['customers' => ['name']]);
        $languages = new LanguagePreferences([new LanguageRange('uk')]);

        $operation = new ReplaceRelationship(
            parent: $parent,
            relationship: 'owner',
            linkage: $linkage,
            precondition: $precondition,
            include: $include,
            fields: $fields,
            languages: $languages,
            queryParameters: new Values([]),
        );

        self::assertSame($parent, $operation->parent);
        self::assertSame('owner', $operation->relationship);
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
                (new ReflectionMethod(ReplaceRelationship::class, '__construct'))->getParameters(),
            ),
        );
        self::assertFalse(property_exists(ReplaceRelationship::class, 'target'));
    }

    #[DataProvider('linkages')]
    public function testTakesTheLinkageOfEitherCardinality(Linkage $linkage): void
    {
        self::assertSame($linkage, self::operation($linkage, null)->linkage);
    }

    /**
     * @return iterable<string, array{Linkage}>
     */
    public static function linkages(): iterable
    {
        yield 'a to-one identifier' => [Linkage::toOne(new Identifier('customers', '12'))];
        yield 'to-one null' => [Linkage::toOne(null)];
        yield 'to-many identifiers' => [Linkage::toMany([new Identifier('tags', '2'), new Identifier('tags', '3')])];
        yield 'to-many empty' => [Linkage::toMany([])];
    }

    public function testIsTheReplaceRelationshipOperationOfTheTypeOfItsParent(): void
    {
        $operation = self::operation(Linkage::toOne(null), null);

        self::assertSame(OperationKind::ReplaceRelationship, $operation->kind());
        self::assertSame('pets', $operation->type);
        self::assertSame('pets', $operation->type());
        self::assertSame('owner', $operation->relationship());
    }

    public function testARequestWithoutIfMatchCarriesNoPrecondition(): void
    {
        self::assertNull(self::operation(Linkage::toOne(null), null)->precondition());
    }

    public function testWithPreconditionLeavesTheOriginalUnchanged(): void
    {
        $operation = self::operation(
            Linkage::toOne(new Identifier('customers', '12')),
            new IncludePaths([new IncludePath(['owner'])]),
        );
        $precondition = Precondition::listedVersions(['v3', 'v4']);

        $conditional = $operation->withPrecondition($precondition);

        self::assertNotSame($operation, $conditional);
        self::assertNull($operation->precondition);
        self::assertSame($precondition, $conditional->precondition);
        self::assertSame($operation->parent, $conditional->parent);
        self::assertSame('pets', $conditional->type);
        self::assertSame('owner', $conditional->relationship);
        self::assertSame($operation->linkage, $conditional->linkage);
        self::assertSame($operation->include, $conditional->include);
        self::assertSame($operation->fields, $conditional->fields);
        self::assertSame($operation->languages, $conditional->languages);
    }

    public function testAnAbsentIncludeParameterIsNoEmptyOne(): void
    {
        $empty = new IncludePaths([]);

        self::assertNull(self::operation(Linkage::toOne(null), null)->include);
        self::assertSame($empty, self::operation(Linkage::toOne(null), $empty)->include);
    }

    #[DataProvider('pathsFromTheRelationship')]
    public function testTakesIncludePathsThatStartWithTheRelationship(IncludePaths $include): void
    {
        self::assertSame($include, self::operation(Linkage::toOne(null), $include)->include);
    }

    /**
     * @return iterable<string, array{IncludePaths}>
     */
    public static function pathsFromTheRelationship(): iterable
    {
        yield 'the relationship itself' => [new IncludePaths([new IncludePath(['owner'])])];
        yield 'a path through the relationship' => [new IncludePaths([
            new IncludePath(['owner']),
            new IncludePath(['owner', 'supplier']),
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

        new ReplaceRelationship(
            new Identifier('pets', '1'),
            $relationship,
            Linkage::toOne(null),
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
            'owner',
            new IncludePaths([new IncludePath(['orders'])]),
            'orders',
        ];
        yield 'a path written from the related type' => [
            'owner',
            new IncludePaths([new IncludePath(['supplier'])]),
            'supplier',
        ];
        yield 'the relationship behind another one' => [
            'owner',
            new IncludePaths([new IncludePath(['breeder', 'owner'])]),
            'breeder.owner',
        ];
        yield 'a second path' => [
            'owner',
            new IncludePaths([new IncludePath(['owner']), new IncludePath(['breeder'])]),
            'breeder',
        ];
        yield 'a name that begins with the relationship' => [
            'owner',
            new IncludePaths([new IncludePath(['owner-profile'])]),
            'owner-profile',
        ];
        yield 'the relationship in another case' => ['owner', new IncludePaths([new IncludePath(['Owner'])]), 'Owner'];
        yield 'a name equal as a number' => ['10', new IncludePaths([new IncludePath(['1e1'])]), '1e1'];
    }

    public function testRefusesAnEmptyRelationshipName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a relationship of a parent of type "pets" is replaced by its name, which is'
            . ' never empty');

        new ReplaceRelationship(
            new Identifier('pets', '1'),
            '',
            Linkage::toOne(null),
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
     * @return iterable<string, array{Closure(): ReplaceRelationship, string}>
     */
    public static function refusalsNamingOutsideValues(): iterable
    {
        yield 'the type of the parent of an empty relationship name' => [
            static function (): ReplaceRelationship {
                return new ReplaceRelationship(
                    new Identifier("pe\nts", '1'),
                    '',
                    Linkage::toOne(null),
                    null,
                    null,
                    new SparseFieldsets([]),
                    new LanguagePreferences([]),
                    new Values([]),
                );
            },
            'a relationship of a parent of type "pe\nts" is replaced by its name, which is never empty',
        ];
        yield 'the relationship and the type of the parent of an include path that starts elsewhere' => [
            static function (): ReplaceRelationship {
                return new ReplaceRelationship(
                    new Identifier("pe\x7Fts", '1'),
                    "own\0er",
                    Linkage::toOne(null),
                    null,
                    new IncludePaths([new IncludePath(['breeder'])]),
                    new SparseFieldsets([]),
                    new LanguagePreferences([]),
                    new Values([]),
                );
            },
            'include path "breeder" does not start with relationship "own\000er" of a parent of type "pe\177ts", whose'
            . ' linkage is the primary data every included resource is reached from',
        ];
    }

    private static function operation(Linkage $linkage, ?IncludePaths $include): ReplaceRelationship
    {
        return new ReplaceRelationship(
            parent: new Identifier('pets', '1'),
            relationship: 'owner',
            linkage: $linkage,
            precondition: null,
            include: $include,
            fields: new SparseFieldsets(['customers' => ['name']]),
            languages: new LanguagePreferences([new LanguageRange('uk')]),
            queryParameters: new Values([]),
        );
    }
}
