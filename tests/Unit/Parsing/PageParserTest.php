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

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Error\StatusSelector;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Parsing\PageParser;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Query\Pagination\Cursor;
use Polunich\WpJsonApi\Query\Pagination\CursorText;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(PageParser::class)]
final class PageParserTest extends TestCase
{
    public function testTheClassIsFinalAndInternal(): void
    {
        $class = new ReflectionClass(PageParser::class);

        self::assertTrue($class->isFinal());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testARequestWithoutPageParametersTakesTheDefaultStrategy(): void
    {
        $errors = self::errorList();
        $page = self::parser()->parse(null, self::policy(), $errors);

        self::assertSame(Strategy::PageNumber, $page->strategy);
        self::assertNull($page->number);
        self::assertNull($page->size);
        self::assertTrue($page->totalRequested);
        self::assertSame([], self::problems($errors));
    }

    public function testTheDefaultStrategyOfARequestForTheCursorProfileIsTheCursor(): void
    {
        $errors = self::errorList();
        $page = self::parser()->parse(['size' => '3'], self::policy(), $errors, Strategy::Cursor);

        self::assertSame(Strategy::Cursor, $page->strategy);
        self::assertSame(3, $page->size);
        self::assertFalse($page->totalRequested);
        self::assertSame([], self::problems($errors));
    }

    public function testASelectingParameterWinsOverTheDefaultStrategy(): void
    {
        $errors = self::errorList();
        $page = self::parser()->parse(['number' => '2'], self::policy(), $errors, Strategy::Cursor);

        self::assertSame(Strategy::PageNumber, $page->strategy);
        self::assertSame(2, $page->number);
        self::assertSame([], self::problems($errors));
    }

    /**
     * @param array<string, string> $sent
     */
    #[DataProvider('selections')]
    public function testTheParametersSelectTheStrategy(array $sent, Strategy $strategy): void
    {
        $errors = self::errorList();
        $page = self::parser()->parse($sent, self::policy(), $errors);

        self::assertSame($strategy, $page->strategy);
        self::assertSame([], self::problems($errors));
    }

    /**
     * @return iterable<string, array{array<string, string>, Strategy}>
     */
    public static function selections(): iterable
    {
        yield 'page[number]' => [['number' => '2'], Strategy::PageNumber];

        yield 'page[size] alone' => [['size' => '5'], Strategy::PageNumber];

        yield 'page[offset]' => [['offset' => '10'], Strategy::Offset];

        yield 'page[limit]' => [['limit' => '10'], Strategy::Offset];

        yield 'page[after]' => [['after' => self::cursor()], Strategy::Cursor];

        yield 'page[before]' => [['before' => self::cursor()], Strategy::Cursor];

        yield 'page[with_count] alone' => [['with_count' => 'false'], Strategy::PageNumber];
    }

    public function testReadsThePageNumberStrategy(): void
    {
        $errors = self::errorList();
        $page = self::parser()->parse(
            ['number' => '3', 'size' => '20', 'with_count' => 'false'],
            self::policy(),
            $errors,
        );

        self::assertSame(Strategy::PageNumber, $page->strategy);
        self::assertSame(3, $page->number);
        self::assertSame(20, $page->size);
        self::assertFalse($page->totalRequested);
        self::assertSame([], self::problems($errors));
    }

    public function testReadsTheOffsetStrategy(): void
    {
        $errors = self::errorList();
        $page = self::parser()->parse(['offset' => '0', 'limit' => '25'], self::policy(), $errors);

        self::assertSame(Strategy::Offset, $page->strategy);
        self::assertSame(0, $page->offset);
        self::assertSame(25, $page->limit);
        self::assertTrue($page->totalRequested);
        self::assertSame([], self::problems($errors));
    }

    public function testReadsTheCursorStrategy(): void
    {
        $after = new Cursor(['name' => 'b'], '7');
        $before = new Cursor(['name' => 'y'], '9');
        $errors = self::errorList();
        $page = self::parser()->parse(
            [
                'after' => CursorText::encode($after),
                'before' => CursorText::encode($before),
                'size' => '2',
            ],
            self::policy(),
            $errors,
        );

        self::assertSame(Strategy::Cursor, $page->strategy);
        self::assertEquals($after, $page->after);
        self::assertEquals($before, $page->before);
        self::assertSame(2, $page->size);
        self::assertFalse($page->totalRequested);
        self::assertSame([], self::problems($errors));
    }

    #[DataProvider('withCounts')]
    public function testReadsWithCountAsTheTotalRequested(string $sent, bool $requested): void
    {
        $errors = self::errorList();
        $page = self::parser()->parse(['with_count' => $sent], self::policy(), $errors);

        self::assertSame($requested, $page->totalRequested);
        self::assertSame([], self::problems($errors));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function withCounts(): iterable
    {
        yield 'true' => ['true', true];

        yield 'false' => ['false', false];
    }

    /**
     * @param array<string, string>                               $sent
     * @param list<array{string, ?string, array<string, scalar>}> $problems
     */
    #[DataProvider('mixtures')]
    public function testEachParameterOfAnotherStrategyIsOneError(array $sent, Strategy $strategy, array $problems): void
    {
        $errors = self::errorList();
        $page = self::parser()->parse($sent, self::policy(), $errors);

        self::assertSame($strategy, $page->strategy);
        self::assertSame($problems, self::problems($errors));
    }

    /**
     * @return iterable<string, array{
     *     array<string, string>,
     *     Strategy,
     *     list<array{string, ?string, array<string, scalar>}>,
     * }>
     */
    public static function mixtures(): iterable
    {
        yield 'the number and the offset' => [
            ['number' => '2', 'offset' => '10'],
            Strategy::PageNumber,
            [[ErrorCode::MixedPaginationStrategies->value, 'page[offset]', ['name' => 'page[offset]']]],
        ];

        yield 'the number and both offset parameters' => [
            ['number' => '2', 'offset' => '10', 'limit' => '5'],
            Strategy::PageNumber,
            [
                [ErrorCode::MixedPaginationStrategies->value, 'page[offset]', ['name' => 'page[offset]']],
                [ErrorCode::MixedPaginationStrategies->value, 'page[limit]', ['name' => 'page[limit]']],
            ],
        ];

        yield 'the size of the offset strategy' => [
            ['offset' => '10', 'size' => '5'],
            Strategy::Offset,
            [[ErrorCode::MixedPaginationStrategies->value, 'page[size]', ['name' => 'page[size]']]],
        ];

        yield 'the offset and a cursor' => [
            ['offset' => '10', 'after' => 'anything'],
            Strategy::Offset,
            [[ErrorCode::MixedPaginationStrategies->value, 'page[after]', ['name' => 'page[after]']]],
        ];

        yield 'a cursor and the number' => [
            ['number' => '2', 'before' => 'anything'],
            Strategy::PageNumber,
            [[ErrorCode::MixedPaginationStrategies->value, 'page[before]', ['name' => 'page[before]']]],
        ];
    }

    /**
     * @param array<array-key, string> $sent
     */
    #[DataProvider('strangers')]
    public function testAPageParameterOfNoStrategyIsUnsupported(array $sent, string $parameter): void
    {
        $errors = self::errorList();

        self::parser()->parse($sent, self::policy(), $errors);

        self::assertSame(
            [[ErrorCode::UnsupportedQueryParameter->value, $parameter, ['name' => $parameter]]],
            self::problems($errors),
        );
    }

    /**
     * @return iterable<string, array{array<array-key, string>, string}>
     */
    public static function strangers(): iterable
    {
        yield 'a parameter of another strategy family' => [['cursor' => 'abc'], 'page[cursor]'];

        yield 'a misspelled parameter' => [['Size' => '5'], 'page[Size]'];

        yield 'the empty brackets of page[]' => [[0 => '5'], 'page[0]'];
    }

    /**
     * @param array<string, mixed> $sent
     */
    #[DataProvider('unreadable')]
    public function testAValueTheEndpointCannotReadIsInvalid(array $sent, string $parameter): void
    {
        $errors = self::errorList();

        self::parser()->parse($sent, self::policy(), $errors);

        self::assertSame(
            [[ErrorCode::InvalidQueryParameter->value, $parameter, ['name' => $parameter]]],
            self::problems($errors),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function unreadable(): iterable
    {
        yield 'a size of zero' => [['size' => '0'], 'page[size]'];

        yield 'a negative size' => [['size' => '-1'], 'page[size]'];

        yield 'a size that is no number' => [['size' => 'ten'], 'page[size]'];

        yield 'a fractional size' => [['size' => '1.5'], 'page[size]'];

        yield 'a size with a space' => [['size' => ' 5'], 'page[size]'];

        yield 'an empty size' => [['size' => ''], 'page[size]'];

        yield 'a size that is no text' => [['size' => ['5']], 'page[size]'];

        yield 'a size of zeros alone' => [['size' => '00'], 'page[size]'];

        yield 'a page below the first' => [['number' => '0'], 'page[number]'];

        yield 'a page of zeros alone' => [['number' => '00'], 'page[number]'];

        yield 'a negative offset' => [['offset' => '-1'], 'page[offset]'];

        yield 'a limit of zero' => [['limit' => '0'], 'page[limit]'];

        yield 'a number above the platform integer' => [['number' => '99999999999999999999'], 'page[number]'];

        yield 'a with_count that is no boolean' => [['with_count' => 'yes'], 'page[with_count]'];

        yield 'a with_count of another case' => [['with_count' => 'TRUE'], 'page[with_count]'];

        yield 'a cursor that is no cursor of this API' => [['after' => 'not a cursor'], 'page[after]'];

        yield 'a cursor that is no text' => [['before' => ['abc']], 'page[before]'];
    }

    public function testLeadingZerosAreReadAsTheIntegerTheyWrite(): void
    {
        $errors = self::errorList();
        $page = self::parser()->parse(['number' => '007', 'size' => '010'], self::policy(), $errors);

        self::assertSame(7, $page->number);
        self::assertSame(10, $page->size);
        self::assertSame([], self::problems($errors));
    }

    /**
     * `0100` is exactly the maximum: its longer text must not read as a larger size
     */
    public function testASizeWrittenWithLeadingZerosIsTheSizeItNames(): void
    {
        $errors = self::errorList();
        $page = self::parser()->parse(['size' => '0100'], self::policy(), $errors);

        self::assertSame(100, $page->size);
        self::assertSame([], self::problems($errors));
    }

    public function testAnOffsetOfZerosAloneIsTheFirstOffset(): void
    {
        $errors = self::errorList();
        $page = self::parser()->parse(['offset' => '00'], self::policy(), $errors);

        self::assertSame(0, $page->offset);
        self::assertSame([], self::problems($errors));
    }

    public function testASizeAboveTheMaximumIsTheMaxPageSizeExceededError(): void
    {
        $errors = self::errorList();
        $page = self::parser()->parse(['size' => '101'], self::policy(), $errors);
        $description = self::descriptions($errors)[0];

        self::assertNull($page->size);
        self::assertSame(ErrorCode::MaxPageSizeExceeded, $description->code);
        self::assertSame('page[size]', $description->parameter);
        self::assertSame(['size' => '101', 'maxSize' => 100], $description->parameters);
        self::assertSame(['page' => ['max_size' => 100]], $description->meta);
        self::assertSame(
            'https://jsonapi.org/profiles/ethanresnick/cursor-pagination/max-size-exceeded',
            $description->typeLink,
        );
    }

    public function testTheLimitIsAPageSizeAndTakesTheSameMaximum(): void
    {
        $errors = self::errorList();
        $page = self::parser()->parse(['limit' => '1000'], self::policy(), $errors);

        self::assertNull($page->limit);
        self::assertSame(
            [[ErrorCode::MaxPageSizeExceeded->value, 'page[limit]', ['size' => '1000', 'maxSize' => 100]]],
            self::problems($errors),
        );
    }

    public function testASizeLargerThanThePlatformIntegerStillExceedsTheMaximum(): void
    {
        $errors = self::errorList();

        self::parser()->parse(['size' => '99999999999999999999'], self::policy(), $errors);

        self::assertSame(
            [[
                ErrorCode::MaxPageSizeExceeded->value,
                'page[size]',
                ['size' => '99999999999999999999', 'maxSize' => 100],
            ]],
            self::problems($errors),
        );
    }

    public function testTheMaximumIsThatOfThePolicyGiven(): void
    {
        $errors = self::errorList();

        self::parser()->parse(['size' => '26'], new PaginationPolicy(5, 25), $errors);

        self::assertSame(
            [[ErrorCode::MaxPageSizeExceeded->value, 'page[size]', ['size' => '26', 'maxSize' => 25]]],
            self::problems($errors),
        );
    }

    /**
     * the parser checks the maximum alone and keeps the size as sent,
     * though 25 is above the default of the policy
     */
    public function testASizeUpToTheMaximumIsKeptAsSent(): void
    {
        $errors = self::errorList();
        $page = self::parser()->parse(['size' => '25'], new PaginationPolicy(5, 25), $errors);

        self::assertSame(25, $page->size);
        self::assertSame([], self::problems($errors));
    }

    public function testAPageFamilyInAnotherFormIsLeftToTheGuard(): void
    {
        $errors = self::errorList();
        $page = self::parser()->parse('3', self::policy(), $errors);

        self::assertSame(Strategy::PageNumber, $page->strategy);
        self::assertNull($page->number);
        self::assertSame([], self::problems($errors));
    }

    public function testTheResultLeavesOutAParameterWhoseValueWasRefused(): void
    {
        $errors = self::errorList();
        $page = self::parser()->parse(['number' => '4', 'size' => '0'], self::policy(), $errors);

        self::assertSame(4, $page->number);
        self::assertNull($page->size);
        self::assertCount(1, self::descriptions($errors));
    }

    public function testTheRefusalOfTheCursorTextIsKeptForTheLog(): void
    {
        $errors = self::errorList();

        self::parser()->parse(['after' => 'not a cursor'], self::policy(), $errors);
        $exception = $errors->exception();

        self::assertNotNull($exception);
        self::assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
    }

    public function testEveryProblemOfTheFamilyIsCollected(): void
    {
        $errors = self::errorList();

        self::parser()->parse(
            ['size' => '0', 'offset' => '2', 'cursor' => 'x'],
            self::policy(),
            $errors,
        );

        self::assertSame(
            [
                [ErrorCode::UnsupportedQueryParameter->value, 'page[cursor]', ['name' => 'page[cursor]']],
                [ErrorCode::MixedPaginationStrategies->value, 'page[size]', ['name' => 'page[size]']],
            ],
            self::problems($errors),
        );
    }

    public function testTheLibraryRespondsToTheProblemsOfTheFamilyWith400(): void
    {
        $errors = self::errorList();

        self::parser()->parse(['size' => 'ten'], self::policy(), $errors);
        $exception = $errors->exception();

        self::assertNotNull($exception);
        self::assertSame(400, $exception->status());
    }

    /**
     * @param array<array-key, mixed> $query
     */
    #[DataProvider('selectingQueries')]
    public function testSelectsReturnsTheStrategyTheSelectingParametersOfTheQueryName(
        array $query,
        Strategy $strategy,
    ): void {
        self::assertSame($strategy, PageParser::strategy($query, Strategy::PageNumber));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, Strategy}>
     */
    public static function selectingQueries(): iterable
    {
        yield 'page[number]' => [['page' => ['number' => '2']], Strategy::PageNumber];
        yield 'page[offset]' => [['page' => ['offset' => '20']], Strategy::Offset];
        yield 'page[limit]' => [['page' => ['limit' => '5']], Strategy::Offset];
        yield 'page[after]' => [['page' => ['after' => 'abcde']], Strategy::Cursor];
        yield 'page[before]' => [['page' => ['before' => 'abcde']], Strategy::Cursor];
    }

    /**
     * @param array<array-key, mixed> $query
     */
    #[DataProvider('queriesThatSelectNoStrategy')]
    public function testSelectsReturnsTheDefaultForAQueryThatSelectsNone(array $query): void
    {
        self::assertSame(Strategy::PageNumber, PageParser::strategy($query, Strategy::PageNumber));
        self::assertSame(Strategy::Cursor, PageParser::strategy($query, Strategy::Cursor));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function queriesThatSelectNoStrategy(): iterable
    {
        yield 'no query at all' => [[]];
        yield 'another family' => [['sort' => 'name']];
        yield 'page[size]' => [['page' => ['size' => '3']]];
        yield 'page[with_count]' => [['page' => ['with_count' => 'true']]];
        yield 'a page parameter no strategy carries' => [['page' => ['cursor' => 'x']]];
    }

    /**
     * @param array<array-key, mixed> $query
     */
    #[DataProvider('pageFamiliesThatAreNoMap')]
    public function testSelectsReturnsTheDefaultForAPageFamilyThatIsNoMapOfNames(array $query): void
    {
        self::assertSame(Strategy::PageNumber, PageParser::strategy($query, Strategy::PageNumber));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function pageFamiliesThatAreNoMap(): iterable
    {
        yield 'page=3' => [['page' => '3']];
        yield 'page[]=after' => [['page' => ['after']]];
    }

    public function testSelectsFollowsTheOrderOfTheStrategiesForAQueryThatMixesThem(): void
    {
        self::assertSame(
            Strategy::PageNumber,
            PageParser::strategy(['page' => ['after' => 'abcde', 'number' => '2']], Strategy::PageNumber),
        );
        self::assertSame(
            Strategy::Offset,
            PageParser::strategy(['page' => ['after' => 'abcde', 'offset' => '20']], Strategy::PageNumber),
        );
    }

    /**
     * @param array<array-key, mixed> $query
     */
    #[DataProvider('selectingQueries')]
    public function testSelectsReturnsWhatParseSelectedForTheSameQuery(array $query, Strategy $strategy): void
    {
        $errors = self::errorList();
        $page = self::parser()->parse($query['page'] ?? null, self::policy(), $errors, Strategy::PageNumber);

        self::assertSame($strategy, $page->strategy);
        self::assertSame($strategy, PageParser::strategy($query, Strategy::PageNumber));
    }

    #[DataProvider('spelledCounts')]
    public function testReadsTheCountAsTheNamingPolicySpellsIt(NamingPolicy $naming, string $name): void
    {
        $errors = self::errorList();
        $page = self::parser($naming)->parse([$name => 'false'], self::policy(), $errors);

        self::assertFalse($page->totalRequested);
        self::assertSame([], self::problems($errors));
    }

    /**
     * @return iterable<string, array{NamingPolicy, string}>
     */
    public static function spelledCounts(): iterable
    {
        yield 'snake case' => [NamingPolicy::SnakeCase, 'with_count'];
        yield 'camel case' => [NamingPolicy::CamelCase, 'withCount'];
    }

    #[DataProvider('misspelledCounts')]
    public function testRefusesTheCountSpelledByTheOtherPolicy(NamingPolicy $naming, string $name): void
    {
        $errors = self::errorList();
        $page = self::parser($naming)->parse([$name => 'false'], self::policy(), $errors);
        $description = self::descriptions($errors)[0];

        self::assertTrue($page->totalRequested);
        self::assertSame(ErrorCode::UnsupportedQueryParameter, $description->code);
        self::assertSame('page[' . $name . ']', $description->parameter);
    }

    /**
     * @return iterable<string, array{NamingPolicy, string}>
     */
    public static function misspelledCounts(): iterable
    {
        yield 'camel case under snake case' => [NamingPolicy::SnakeCase, 'withCount'];
        yield 'snake case under camel case' => [NamingPolicy::CamelCase, 'with_count'];
    }

    public function testNamesTheCountAndTheMaximumAsTheCamelCasePolicySpellsThem(): void
    {
        $errors = self::errorList();
        self::parser(NamingPolicy::CamelCase)->parse(['size' => '101', 'withCount' => 'yes'], self::policy(), $errors);
        $descriptions = self::descriptions($errors);

        self::assertSame(['page' => ['maxSize' => 100]], $descriptions[0]->meta);
        self::assertSame('page[withCount]', $descriptions[1]->parameter);
    }

    /**
     * `PageParser::strategy()` reads the selectors without a naming policy
     */
    public function testEverySelectorIsSpelledAlikeUnderEachNamingPolicy(): void
    {
        foreach (Strategy::cases() as $strategy) {
            foreach ($strategy->selectors() as $selector) {
                foreach (NamingPolicy::cases() as $naming) {
                    self::assertSame($selector->value, $selector->nameIn($naming));
                }
            }
        }
    }

    private static function parser(NamingPolicy $naming = NamingPolicy::SnakeCase): PageParser
    {
        return new PageParser($naming);
    }

    /**
     * the library default: a size of 10 and a maximum of 100
     */
    private static function policy(): PaginationPolicy
    {
        return PaginationPolicy::libraryDefault();
    }

    private static function cursor(): string
    {
        return CursorText::encode(new Cursor(['name' => 'a'], '1'));
    }

    private static function errorList(): ErrorList
    {
        return new ErrorList(new StatusSelector());
    }

    /**
     * @return list<array{string, ?string, array<string, scalar>}>
     */
    private static function problems(ErrorList $errors): array
    {
        return array_map(
            static function (ErrorDescription $description): array {
                return [
                    $description->code instanceof ErrorCode ? $description->code->value : $description->code,
                    $description->parameter,
                    $description->parameters,
                ];
            },
            self::descriptions($errors),
        );
    }

    /**
     * @return list<ErrorDescription>
     */
    private static function descriptions(ErrorList $errors): array
    {
        return $errors->exception()?->descriptions() ?? [];
    }
}
