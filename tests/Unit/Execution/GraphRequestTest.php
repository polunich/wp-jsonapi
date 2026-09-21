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

namespace Polunich\WpJsonApi\Tests\Unit\Execution;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Execution\GraphRequest;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Localization\LanguageRange;
use Polunich\WpJsonApi\Operation\CreateResource;
use Polunich\WpJsonApi\Operation\FetchCollection;
use Polunich\WpJsonApi\Operation\FetchRelated;
use Polunich\WpJsonApi\Operation\FetchRelationship;
use Polunich\WpJsonApi\Operation\FetchResource;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Operation\UpdateResource;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Inclusion\IncludePath;
use Polunich\WpJsonApi\Query\Inclusion\IncludePaths;
use Polunich\WpJsonApi\Query\Pagination\PageRequest;
use Polunich\WpJsonApi\Query\Sort\Sort;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(GraphRequest::class)]
final class GraphRequestTest extends TestCase
{
    public function testTheRequestCarriesEveryValueItWasBuiltWith(): void
    {
        $include = self::include();
        $fields = self::fields();
        $languages = self::languages();

        $request = new GraphRequest($include, $fields, $languages);

        self::assertSame($include, $request->include);
        self::assertSame($fields, $request->fields);
        self::assertSame($languages, $request->languages);
    }

    public function testARequestWithoutAnIncludeParameterHasNoIncludePaths(): void
    {
        self::assertNull((new GraphRequest(null, self::fields(), self::languages()))->include);
    }

    #[DataProvider('operations')]
    public function testTheRequestOfAnOperationCarriesItsIncludePathsFieldsetsAndLanguages(
        FetchCollection|FetchResource|FetchRelated|FetchRelationship|CreateResource|UpdateResource $operation,
    ): void {
        $request = GraphRequest::of($operation);

        self::assertSame($operation->include, $request->include);
        self::assertSame($operation->fields, $request->fields);
        self::assertSame($operation->languages, $request->languages);
    }

    /**
     * @return iterable<string, array{
     *     FetchCollection|FetchResource|FetchRelated|FetchRelationship
     *     |CreateResource|UpdateResource,
     * }>
     */
    public static function operations(): iterable
    {
        $pet = new Identifier('pets', '1');
        $include = self::include();
        $fields = self::fields();
        $languages = self::languages();

        yield OperationKind::FetchCollection->value => [
            new FetchCollection(
                'pets',
                null,
                new Sort([]),
                PageRequest::pageNumber(),
                $include,
                $fields,
                $languages,
                new Values([]),
            ),
        ];
        yield OperationKind::FetchResource->value => [new FetchResource(
            $pet,
            $include,
            $fields,
            $languages,
            new Values([]),
        )];
        yield OperationKind::FetchRelated->value => [
            new FetchRelated(
                $pet,
                'orders',
                null,
                new Sort([]),
                PageRequest::pageNumber(),
                $include,
                $fields,
                $languages,
                new Values([]),
            ),
        ];
        yield OperationKind::FetchRelationship->value => [
            new FetchRelationship(
                $pet,
                'orders',
                null,
                PageRequest::pageNumber(),
                $include,
                $fields,
                $languages,
                new Values([]),
            ),
        ];
        yield OperationKind::CreateResource->value => [
            new CreateResource(
                'pets',
                null,
                new Values([]),
                new Values([]),
                $include,
                $fields,
                $languages,
                new Values([]),
            ),
        ];
        yield OperationKind::UpdateResource->value => [
            new UpdateResource(
                $pet,
                new Values([]),
                new Values([]),
                null,
                $include,
                $fields,
                $languages,
                new Values([]),
            ),
        ];
        yield 'an operation without an include parameter' => [new FetchResource(
            $pet,
            null,
            $fields,
            $languages,
            new Values([]),
        )];
    }

    public function testTheRequestIsInternalAndImmutable(): void
    {
        $request = new ReflectionClass(GraphRequest::class);

        self::assertStringContainsString('@internal', (string) $request->getDocComment());
        self::assertTrue($request->isFinal());
        self::assertTrue($request->isReadOnly());
    }

    private static function include(): IncludePaths
    {
        return new IncludePaths([new IncludePath(['orders'])]);
    }

    private static function fields(): SparseFieldsets
    {
        return new SparseFieldsets(['orders' => ['address']]);
    }

    private static function languages(): LanguagePreferences
    {
        return new LanguagePreferences([new LanguageRange('uk')]);
    }
}
