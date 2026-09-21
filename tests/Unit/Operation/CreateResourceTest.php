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

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Localization\LanguageRange;
use Polunich\WpJsonApi\Operation\CreateResource;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Linkage;
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
#[CoversClass(CreateResource::class)]
final class CreateResourceTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(CreateResource::class))->isReadOnly());
    }

    public function testCarriesTheResourceObjectOfTheRequestAndTheShapeOfTheResponse(): void
    {
        $attributes = new Values(['title' => 'Ember Hamster']);
        $relationships = new Values(['photographer' => Linkage::toOne(new Identifier('customers', '9'))]);
        $include = new IncludePaths([new IncludePath(['photographer'])]);
        $fields = new SparseFieldsets(['photos' => ['title'], 'customers' => ['name']]);
        $languages = new LanguagePreferences([new LanguageRange('uk')]);

        $operation = new CreateResource(
            type: 'photos',
            id: null,
            attributes: $attributes,
            relationships: $relationships,
            include: $include,
            fields: $fields,
            languages: $languages,
            queryParameters: new Values([]),
        );

        self::assertSame('photos', $operation->type);
        self::assertNull($operation->id);
        self::assertSame($attributes, $operation->attributes);
        self::assertSame($relationships, $operation->relationships);
        self::assertSame($include, $operation->include);
        self::assertSame($fields, $operation->fields);
        self::assertSame($languages, $operation->languages);
    }

    public function testTheParametersAreTheResourceObjectAndTheShapeOfTheResponse(): void
    {
        self::assertSame(
            [
                ['type', 'string'],
                ['id', '?string'],
                ['attributes', Values::class],
                ['relationships', Values::class],
                ['include', '?' . IncludePaths::class],
                ['fields', SparseFieldsets::class],
                ['languages', LanguagePreferences::class],
                ['queryParameters', Values::class],
            ],
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                (new ReflectionMethod(CreateResource::class, '__construct'))->getParameters(),
            ),
        );
        self::assertFalse(property_exists(CreateResource::class, 'lid'));
    }

    public function testIsTheCreateResourceOperationOfItsType(): void
    {
        $operation = self::operation(null, null);

        self::assertSame(OperationKind::CreateResource, $operation->kind());
        self::assertSame('photos', $operation->type());
        self::assertNull($operation->relationship());
    }

    public function testCarriesAClientGeneratedId(): void
    {
        self::assertSame(
            '550e8400-e29b-41d4-a716-446655440000',
            self::operation('550e8400-e29b-41d4-a716-446655440000', null)->id,
        );
    }

    public function testAnAbsentIncludeParameterIsNoEmptyOne(): void
    {
        $empty = new IncludePaths([]);

        self::assertNull(self::operation(null, null)->include);
        self::assertSame($empty, self::operation(null, $empty)->include);
    }

    #[DataProvider('emptyMembers')]
    public function testRefusesAnEmptyTypeOrId(string $type, ?string $id, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new CreateResource(
            $type,
            $id,
            new Values([]),
            new Values([]),
            null,
            new SparseFieldsets([]),
            new LanguagePreferences([]),
            new Values([]),
        );
    }

    /**
     * @return iterable<string, array{string, ?string, string}>
     */
    public static function emptyMembers(): iterable
    {
        yield 'an empty type' => ['', null, 'a resource is created with a type, and a type is never empty'];
        yield 'an empty id' => [
            'photos',
            '',
            'a resource of type "photos" is created with an empty id, which identifies no resource',
        ];
        yield 'an empty id of a type with a control character, which the message writes as an escape' => [
            "pho\ntos",
            '',
            'a resource of type "pho\ntos" is created with an empty id, which identifies no resource',
        ];
    }

    private static function operation(?string $id, ?IncludePaths $include): CreateResource
    {
        return new CreateResource(
            type: 'photos',
            id: $id,
            attributes: new Values(['title' => 'Ember Hamster', 'caption' => null]),
            relationships: new Values([]),
            include: $include,
            fields: new SparseFieldsets([]),
            languages: new LanguagePreferences([new LanguageRange('uk')]),
            queryParameters: new Values([]),
        );
    }
}
