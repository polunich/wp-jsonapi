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

namespace Polunich\WpJsonApi\Tests\Unit\Parsing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Error\StatusSelector;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Parsing\FieldsetParser;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\Parsing\CollectedProblems;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(FieldsetParser::class)]
final class FieldsetParserTest extends TestCase
{
    public function testTheClassIsFinalAndInternal(): void
    {
        $class = new ReflectionClass(FieldsetParser::class);

        self::assertTrue($class->isFinal());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testARequestWithoutTheFamilyNamesNoFieldset(): void
    {
        $errors = self::errorList();
        $fieldsets = self::parser()->parse(null, $errors);

        self::assertTrue($fieldsets->isEmpty());
        self::assertNull($fieldsets->fields('pets'));
        self::assertSame([], CollectedProblems::of($errors));
    }

    public function testReadsTheFieldsOfEachTypeTheClientNames(): void
    {
        $errors = self::errorList();
        $fieldsets = self::parser()->parse(['pets' => 'name,orders', 'customers' => 'name'], $errors);

        self::assertSame(['name', 'orders'], $fieldsets->fields('pets'));
        self::assertSame(['name'], $fieldsets->fields('customers'));
        self::assertSame(['pets', 'customers'], $fieldsets->types());
        self::assertSame([], CollectedProblems::of($errors));
    }

    public function testTheEmptyValueIsTheEmptyFieldset(): void
    {
        $errors = self::errorList();
        $fieldsets = self::parser()->parse(['pets' => ''], $errors);

        self::assertTrue($fieldsets->has('pets'));
        self::assertSame([], $fieldsets->fields('pets'));
        self::assertSame([], CollectedProblems::of($errors));
    }

    public function testAFieldNamedTwiceIsOneField(): void
    {
        $errors = self::errorList();
        $fieldsets = self::parser()->parse(['pets' => 'name,name'], $errors);

        self::assertSame(['name'], $fieldsets->fields('pets'));
        self::assertSame([], CollectedProblems::of($errors));
    }

    public function testAFieldsetOfADeclaredTypeIsReadWhateverTheResponseCarries(): void
    {
        $errors = self::errorList();
        $fieldsets = self::parser()->parse(['customers' => 'name'], $errors);

        self::assertSame(['name'], $fieldsets->fields('customers'));
        self::assertSame([], CollectedProblems::of($errors));
    }

    /**
     * @param array<array-key, mixed> $sent
     */
    #[DataProvider('strangers')]
    public function testAFieldsetOfATypeThisApiDoesNotDeclareIsUnsupported(array $sent, string $parameter): void
    {
        $errors = self::errorList();
        $fieldsets = self::parser()->parse($sent, $errors);

        self::assertTrue($fieldsets->isEmpty());
        self::assertSame(
            [[ErrorCode::UnsupportedQueryParameter->value, $parameter, ['name' => $parameter]]],
            CollectedProblems::of($errors),
        );
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function strangers(): iterable
    {
        yield 'a type no resource carries' => [['invoices' => 'name'], 'fields[invoices]'];

        yield 'a key that is no member name' => [['inv oices ' => 'name'], 'fields[inv oices ]'];

        yield 'the empty brackets of fields[]' => [[0 => 'name'], 'fields[0]'];
    }

    public function testAFieldTheTypeDoesNotDeclareIsUnknown(): void
    {
        $errors = self::errorList();
        $fieldsets = self::parser()->parse(['pets' => 'name,breed'], $errors);

        self::assertSame(['name'], $fieldsets->fields('pets'));
        self::assertSame(
            [[ErrorCode::UnknownField->value, 'fields[pets]', ['type' => 'pets', 'field' => 'breed']]],
            CollectedProblems::of($errors),
        );
    }

    public function testASegmentThatIsNoFieldNameIsUnknownAsWell(): void
    {
        $errors = self::errorList();

        self::parser()->parse(['pets' => 'name,'], $errors);

        self::assertSame(
            [[ErrorCode::UnknownField->value, 'fields[pets]', ['type' => 'pets', 'field' => '']]],
            CollectedProblems::of($errors),
        );
    }

    public function testAValueThatIsNoListOfFieldNamesIsInvalid(): void
    {
        $errors = self::errorList();
        $fieldsets = self::parser()->parse(['pets' => ['name']], $errors);

        self::assertTrue($fieldsets->isEmpty());
        self::assertSame(
            [[ErrorCode::InvalidQueryParameter->value, 'fields[pets]', ['name' => 'fields[pets]']]],
            CollectedProblems::of($errors),
        );
    }

    public function testATypeOfDigitsIsReadUnderTheNameItIsDeclaredWith(): void
    {
        $errors = self::errorList();
        $fieldsets = self::parser()->parse([2026 => 'title'], $errors);

        self::assertSame(['title'], $fieldsets->fields('2026'));
        self::assertSame([], CollectedProblems::of($errors));
    }

    public function testAFieldsFamilyInAnotherFormIsLeftToTheGuard(): void
    {
        $errors = self::errorList();
        $fieldsets = self::parser()->parse('name', $errors);

        self::assertTrue($fieldsets->isEmpty());
        self::assertSame([], CollectedProblems::of($errors));
    }

    public function testEveryProblemOfTheFamilyIsCollected(): void
    {
        $errors = self::errorList();
        $fieldsets = self::parser()->parse(
            ['pets' => 'breed,name', 'invoices' => 'name', 'customers' => 'name'],
            $errors,
        );

        self::assertSame(['name'], $fieldsets->fields('pets'));
        self::assertSame(['name'], $fieldsets->fields('customers'));
        self::assertSame(
            [
                [ErrorCode::UnknownField->value, 'fields[pets]', ['type' => 'pets', 'field' => 'breed']],
                [ErrorCode::UnsupportedQueryParameter->value, 'fields[invoices]', ['name' => 'fields[invoices]']],
            ],
            CollectedProblems::of($errors),
        );
    }

    public function testTheLibraryRespondsToTheProblemsOfTheFamilyWith400(): void
    {
        $errors = self::errorList();

        self::parser()->parse(['pets' => 'breed'], $errors);
        $exception = $errors->exception();

        self::assertNotNull($exception);
        self::assertSame(400, $exception->status());
    }

    private static function parser(): FieldsetParser
    {
        return new FieldsetParser(self::registry());
    }

    private static function registry(): ResourceRegistry
    {
        return new ResourceRegistry([
            Samples::resource(
                'pets',
                attributes: [Samples::attribute('name'), Samples::attribute('created')],
                relationships: [Samples::toMany('orders', 'orders'), Samples::toOne('owner', 'customers')],
                capabilities: [Capability::CollectionReader, Capability::RelatedLoader],
            ),
            Samples::resource('orders', capabilities: [Capability::CollectionReader]),
            Samples::resource('customers', attributes: [Samples::attribute('name')]),
            Samples::resource('2026', attributes: [Samples::attribute('title')]),
        ]);
    }

    private static function errorList(): ErrorList
    {
        return new ErrorList(new StatusSelector());
    }
}
