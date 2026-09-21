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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Localization\LanguageRange;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Operation\Precondition;
use Polunich\WpJsonApi\Operation\UpdateResource;
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
#[CoversClass(UpdateResource::class)]
final class UpdateResourceTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(UpdateResource::class))->isReadOnly());
    }

    public function testCarriesTheTargetTheMembersThePreconditionAndTheShapeOfTheResponse(): void
    {
        $target = new Identifier('pets', '1');
        $attributes = new Values(['name' => 'Buddy']);
        $relationships = new Values(['owner' => Linkage::toOne(new Identifier('customers', '1'))]);
        $precondition = Precondition::listedVersions(['v7']);
        $include = new IncludePaths([new IncludePath(['owner'])]);
        $fields = new SparseFieldsets(['pets' => ['name'], 'customers' => ['name']]);
        $languages = new LanguagePreferences([new LanguageRange('uk')]);

        $operation = new UpdateResource(
            target: $target,
            attributes: $attributes,
            relationships: $relationships,
            precondition: $precondition,
            include: $include,
            fields: $fields,
            languages: $languages,
            queryParameters: new Values([]),
        );

        self::assertSame($target, $operation->target);
        self::assertSame($attributes, $operation->attributes);
        self::assertSame($relationships, $operation->relationships);
        self::assertSame($precondition, $operation->precondition);
        self::assertSame($precondition, $operation->precondition());
        self::assertSame($include, $operation->include);
        self::assertSame($fields, $operation->fields);
        self::assertSame($languages, $operation->languages);
    }

    public function testTheParametersAreTheTargetTheMembersThePreconditionAndTheShapeOfTheResponse(): void
    {
        self::assertSame(
            [
                ['target', Identifier::class],
                ['attributes', Values::class],
                ['relationships', Values::class],
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
                (new ReflectionMethod(UpdateResource::class, '__construct'))->getParameters(),
            ),
        );
    }

    public function testARequestWithoutIfMatchCarriesNoPrecondition(): void
    {
        $operation = self::operation(null, null);

        self::assertNull($operation->precondition);
        self::assertNull($operation->precondition());
    }

    public function testIsTheUpdateResourceOperationOfTheTypeOfItsTarget(): void
    {
        $operation = self::operation(null, null);

        self::assertSame(OperationKind::UpdateResource, $operation->kind());
        self::assertSame('pets', $operation->type);
        self::assertSame('pets', $operation->type());
        self::assertNull($operation->relationship());
    }

    public function testThePreconditionAdmitsTheListedVersions(): void
    {
        $precondition = self::operation(Precondition::listedVersions(['v7']), null)->precondition();

        self::assertNotNull($precondition);
        self::assertTrue($precondition->admits('v7'));
        self::assertFalse($precondition->admits('v8'));
    }

    public function testWithPreconditionLeavesTheOriginalUnchanged(): void
    {
        $operation = self::operation(null, new IncludePaths([new IncludePath(['owner'])]));
        $precondition = Precondition::anyVersion();

        $conditional = $operation->withPrecondition($precondition);

        self::assertNotSame($operation, $conditional);
        self::assertNull($operation->precondition);
        self::assertSame($precondition, $conditional->precondition);
        self::assertSame($operation->target, $conditional->target);
        self::assertSame('pets', $conditional->type);
        self::assertSame($operation->attributes, $conditional->attributes);
        self::assertSame($operation->relationships, $conditional->relationships);
        self::assertSame($operation->include, $conditional->include);
        self::assertSame($operation->fields, $conditional->fields);
        self::assertSame($operation->languages, $conditional->languages);
    }

    public function testAnAbsentIncludeParameterIsNoEmptyOne(): void
    {
        $empty = new IncludePaths([]);

        self::assertNull(self::operation(null, null)->include);
        self::assertSame($empty, self::operation(null, $empty)->include);
    }

    private static function operation(?Precondition $precondition, ?IncludePaths $include): UpdateResource
    {
        return new UpdateResource(
            target: new Identifier('pets', '1'),
            attributes: new Values(['name' => 'Buddy', 'description' => null]),
            relationships: new Values(['owner' => Linkage::toOne(new Identifier('customers', '1'))]),
            precondition: $precondition,
            include: $include,
            fields: new SparseFieldsets(['pets' => ['name']]),
            languages: new LanguagePreferences([new LanguageRange('uk')]),
            queryParameters: new Values([]),
        );
    }
}
