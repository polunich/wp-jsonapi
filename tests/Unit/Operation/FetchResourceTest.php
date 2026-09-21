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
use Polunich\WpJsonApi\Operation\FetchResource;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\OperationKind;
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
#[CoversClass(FetchResource::class)]
final class FetchResourceTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(FetchResource::class))->isReadOnly());
    }

    public function testCarriesWhatTheClientAskedFor(): void
    {
        $target = new Identifier('pets', '42');
        $include = new IncludePaths([new IncludePath(['store'])]);
        $fields = new SparseFieldsets(['stores' => ['name']]);
        $languages = new LanguagePreferences([new LanguageRange('uk')]);

        $operation = new FetchResource(
            target: $target,
            include: $include,
            fields: $fields,
            languages: $languages,
            queryParameters: new Values([]),
        );

        self::assertSame($target, $operation->target);
        self::assertSame($include, $operation->include);
        self::assertSame($fields, $operation->fields);
        self::assertSame($languages, $operation->languages);
    }

    public function testTheConstructorDeclaresItsPublicParameters(): void
    {
        self::assertSame(
            [
                ['target', Identifier::class],
                ['include', '?' . IncludePaths::class],
                ['fields', SparseFieldsets::class],
                ['languages', LanguagePreferences::class],
                ['queryParameters', Values::class],
            ],
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                (new ReflectionMethod(FetchResource::class, '__construct'))->getParameters(),
            ),
        );
    }

    public function testIsTheFetchResourceOperationOfTheTypeOfItsTarget(): void
    {
        $operation = self::operation(new Identifier('pets', '42'));

        self::assertSame(OperationKind::FetchResource, $operation->kind());
        self::assertSame('pets', $operation->type);
        self::assertSame('pets', $operation->type());
        self::assertNull($operation->relationship());
    }

    public function testAnAbsentIncludeParameterIsNoEmptyOne(): void
    {
        $operation = new FetchResource(
            new Identifier('pets', '42'),
            null,
            new SparseFieldsets([]),
            new LanguagePreferences([]),
            new Values([]),
        );

        self::assertNull($operation->include);
    }

    private static function operation(Identifier $target): FetchResource
    {
        return new FetchResource(
            target: $target,
            include: new IncludePaths([new IncludePath(['store'])]),
            fields: new SparseFieldsets(['pets' => ['name']]),
            languages: new LanguagePreferences([new LanguageRange('uk')]),
            queryParameters: new Values([]),
        );
    }
}
