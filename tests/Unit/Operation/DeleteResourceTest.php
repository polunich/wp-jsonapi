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
use Polunich\WpJsonApi\Operation\DeleteResource;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Operation\Precondition;
use Polunich\WpJsonApi\Operation\Values;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(DeleteResource::class)]
final class DeleteResourceTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(DeleteResource::class))->isReadOnly());
    }

    public function testCarriesTheTargetAndThePrecondition(): void
    {
        $target = new Identifier('pets', '13');
        $precondition = Precondition::listedVersions(['v2']);
        $languages = new LanguagePreferences([new LanguageRange('uk')]);

        $operation = new DeleteResource(
            target: $target,
            precondition: $precondition,
            languages: $languages,
            queryParameters: new Values([]),
        );

        self::assertSame($target, $operation->target);
        self::assertSame($precondition, $operation->precondition);
        self::assertSame($precondition, $operation->precondition());
        self::assertSame($languages, $operation->languages);
    }

    public function testTheParametersAreTheTargetAndThePrecondition(): void
    {
        self::assertSame(
            [
                ['target', Identifier::class],
                ['precondition', '?' . Precondition::class],
                ['languages', LanguagePreferences::class],
                ['queryParameters', Values::class],
            ],
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                (new ReflectionMethod(DeleteResource::class, '__construct'))->getParameters(),
            ),
        );
    }

    public function testARequestWithoutIfMatchCarriesNoPrecondition(): void
    {
        $operation = self::operation(null);

        self::assertNull($operation->precondition);
        self::assertNull($operation->precondition());
    }

    public function testIsTheDeleteResourceOperationOfTheTypeOfItsTarget(): void
    {
        $operation = self::operation(null);

        self::assertSame(OperationKind::DeleteResource, $operation->kind());
        self::assertSame('pets', $operation->type);
        self::assertSame('pets', $operation->type());
        self::assertNull($operation->relationship());
    }

    public function testThePreconditionOfTheWildcardAdmitsAnyVersion(): void
    {
        $precondition = self::operation(Precondition::anyVersion())->precondition();

        self::assertNotNull($precondition);
        self::assertNull($precondition->versions);
        self::assertTrue($precondition->admits('v2'));
    }

    public function testWithPreconditionLeavesTheOriginalUnchanged(): void
    {
        $operation = self::operation(Precondition::anyVersion());
        $precondition = Precondition::listedVersions(['v2']);

        $conditional = $operation->withPrecondition($precondition);

        self::assertNotSame($operation, $conditional);
        self::assertNotSame($precondition, $operation->precondition);
        self::assertSame($precondition, $conditional->precondition);
        self::assertSame($operation->target, $conditional->target);
        self::assertSame('pets', $conditional->type);
        self::assertSame($operation->languages, $conditional->languages);
    }

    private static function operation(?Precondition $precondition): DeleteResource
    {
        return new DeleteResource(
            target: new Identifier('pets', '13'),
            precondition: $precondition,
            languages: new LanguagePreferences([new LanguageRange('uk')]),
            queryParameters: new Values([]),
        );
    }
}
