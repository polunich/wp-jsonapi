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
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Parsing\CursorProfile;
use Polunich\WpJsonApi\Parsing\Negotiation;
use Polunich\WpJsonApi\Parsing\Negotiator;
use Polunich\WpJsonApi\Routing\RouteKind;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(Negotiator::class)]
final class NegotiatorTest extends TestCase
{
    private const JSON_API = 'application/vnd.api+json';

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function acceptFieldsWithoutAPreference(): iterable
    {
        yield 'no field' => [null];
        yield 'an empty field' => [''];
        yield 'commas only' => [',,'];
        yield 'a malformed element' => ['application/'];
        yield 'a weight that is no qvalue' => [self::JSON_API . ';q=1.5'];
        yield 'a parameter without a value' => [self::JSON_API . ';ext'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptFieldsThatAdmitEveryMediaType(): iterable
    {
        yield 'every media type' => ['*/*'];
        yield 'every media type of the type' => ['application/*'];
        yield 'another media type and every one' => ['text/html, */*;q=0.1'];
        yield 'the wildcard behind a media type it does not admit' => ['text/html;q=0.9, application/*'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptFieldsThatAdmitNothing(): iterable
    {
        yield 'another media type' => ['text/html'];
        yield 'another subtype of the type' => ['application/json'];
        yield 'every media type, refused' => ['*/*;q=0'];
        yield 'every media type of the type, refused' => ['application/*;q=0'];
        yield 'the more specific wildcard refused' => ['application/*;q=0, */*'];
        yield 'the JSON:API media type, refused' => [self::JSON_API . ';q=0'];
        yield 'the JSON:API media type refused beside a wildcard' => [self::JSON_API . ';q=0, */*'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function spellingsOfTheCursorProfile(): iterable
    {
        yield 'the spelling of the extensions list' => [
            'https://jsonapi.org/profiles/ethanresnick/cursor-pagination',
        ];
        yield 'the spelling of the profile page' => [
            'https://jsonapi.org/profiles/ethanresnick/cursor-pagination/',
        ];
        yield 'the http spelling of the profile page' => [
            'http://jsonapi.org/profiles/ethanresnick/cursor-pagination/',
        ];
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function contentTypesOfNoJsonApiPayload(): iterable
    {
        yield 'no field at all' => [null];
        yield 'another media type' => ['application/json'];
        yield 'the form a browser posts' => ['application/x-www-form-urlencoded'];
        yield 'a field that names no media type' => ['application/'];
        yield 'a list of media types' => [self::JSON_API . ', ' . self::JSON_API];
        yield 'a media type with a weight' => [self::JSON_API . ';q=0.5'];
        yield 'the subtype alone' => ['vnd.api+json'];
    }

    public function testTheClassIsFinalAndInternal(): void
    {
        $class = new ReflectionClass(Negotiator::class);

        self::assertTrue($class->isFinal());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    #[DataProvider('acceptFieldsWithoutAPreference')]
    public function testAnAcceptFieldWithoutAPreferenceGetsThePlainMediaType(?string $accept): void
    {
        $negotiation = self::negotiate(self::request($accept), RouteKind::Collection);

        self::assertSame(self::JSON_API, $negotiation->contentType->toHeaderValue());
        self::assertSame([], $negotiation->contentType->ext());
        self::assertSame([], $negotiation->contentType->profile());
    }

    #[DataProvider('acceptFieldsThatAdmitEveryMediaType')]
    public function testAWildcardAdmitsTheMediaTypeOfTheLibrary(string $accept): void
    {
        $negotiation = self::negotiate(self::request($accept), RouteKind::Collection);

        self::assertSame(self::JSON_API, $negotiation->contentType->toHeaderValue());
    }

    #[DataProvider('acceptFieldsThatAdmitNothing')]
    public function testAnAcceptFieldThatAdmitsNoRepresentationGets406(string $accept): void
    {
        $exception = self::refusal(self::request($accept), RouteKind::Collection);

        self::assertSame(406, $exception->status());
        self::assertSame([['not_acceptable', 'Accept', []]], self::problems($exception));
    }

    public function testAnAcceptFieldWhoseInstancesAllCarryAForeignParameterGets406(): void
    {
        $exception = self::refusal(
            self::request(self::JSON_API . ';charset=utf-8, ' . self::JSON_API . ';version=1'),
            RouteKind::Collection,
        );

        self::assertSame(406, $exception->status());
        self::assertSame(
            [
                ['unsupported_media_type_parameter', 'Accept', ['name' => 'charset']],
                ['unsupported_media_type_parameter', 'Accept', ['name' => 'version']],
            ],
            self::problems($exception),
        );
    }

    public function testAnInstanceWithAForeignParameterIsIgnoredWhereAnotherIsServed(): void
    {
        $negotiation = self::negotiate(
            self::request(self::JSON_API . ';charset=utf-8, ' . self::JSON_API),
            RouteKind::Collection,
        );

        self::assertSame(self::JSON_API, $negotiation->contentType->toHeaderValue());
    }

    public function testAWildcardDoesNotRescueInstancesThatAreAllIgnored(): void
    {
        $exception = self::refusal(
            self::request(self::JSON_API . ';charset=utf-8, */*'),
            RouteKind::Collection,
        );

        self::assertSame(406, $exception->status());
        self::assertSame(
            [['unsupported_media_type_parameter', 'Accept', ['name' => 'charset']]],
            self::problems($exception),
        );
    }

    public function testAnAcceptFieldThatRequiresAnExtensionTheRouteDoesNotApplyGets406(): void
    {
        $exception = self::refusal(
            self::request(self::JSON_API . ';ext="' . MediaType::ATOMIC_EXTENSION . ' https://example.com/ext"'),
            RouteKind::Collection,
        );

        self::assertSame(406, $exception->status());
        self::assertSame(
            [
                ['unsupported_extension', 'Accept', ['extension' => MediaType::ATOMIC_EXTENSION]],
                ['unsupported_extension', 'Accept', ['extension' => 'https://example.com/ext']],
            ],
            self::problems($exception),
        );
    }

    public function testAnIgnoredInstanceIsIgnoredWholeAndItsExtensionsAreNotRead(): void
    {
        $exception = self::refusal(
            self::request(self::JSON_API . ';version=2;ext="https://example.com/ext"'),
            RouteKind::Collection,
        );

        self::assertSame(406, $exception->status());
        self::assertSame(
            [['unsupported_media_type_parameter', 'Accept', ['name' => 'version']]],
            self::problems($exception),
        );
    }

    public function testAFieldThatMixesTheTwoReasonsNamesBoth(): void
    {
        $exception = self::refusal(
            self::request(self::JSON_API . ';charset=utf-8, ' . self::JSON_API . ';ext="https://example.com/ext"'),
            RouteKind::Collection,
        );

        self::assertSame(
            [
                ['unsupported_media_type_parameter', 'Accept', ['name' => 'charset']],
                ['unsupported_extension', 'Accept', ['extension' => 'https://example.com/ext']],
            ],
            self::problems($exception),
        );
    }

    public function testTheAtomicRouteAppliesTheAtomicExtension(): void
    {
        $negotiation = self::negotiate(
            self::request(self::JSON_API . ';ext="' . MediaType::ATOMIC_EXTENSION . '"'),
            RouteKind::Atomic,
        );

        self::assertSame([MediaType::ATOMIC_EXTENSION], $negotiation->contentType->ext());
        self::assertSame(
            'application/vnd.api+json;ext="https://jsonapi.org/ext/atomic"',
            $negotiation->contentType->toHeaderValue(),
        );
    }

    public function testTheAtomicRouteAppliesTheExtensionWithoutBeingAsked(): void
    {
        $negotiation = self::negotiate(self::request(self::JSON_API), RouteKind::Atomic);

        self::assertSame([MediaType::ATOMIC_EXTENSION], $negotiation->contentType->ext());
    }

    #[DataProvider('spellingsOfTheCursorProfile')]
    public function testARequestedProfileIsAppliedInTheSpellingOfTheResponse(string $spelling): void
    {
        $negotiation = self::negotiate(
            self::request(self::JSON_API . ';profile="' . $spelling . '"'),
            RouteKind::Collection,
        );

        self::assertSame([CursorProfile::URI], $negotiation->contentType->profile());
    }

    public function testAProfileTheServerDoesNotRecognizeIsIgnored(): void
    {
        $negotiation = self::negotiate(
            self::request(self::JSON_API . ';profile="https://example.com/profile ' . CursorProfile::URI . '"'),
            RouteKind::Collection,
        );

        self::assertSame([CursorProfile::URI], $negotiation->contentType->profile());
    }

    public function testAProfileWrittenTwiceIsAppliedOnce(): void
    {
        $negotiation = self::negotiate(
            self::request(
                self::JSON_API . ';profile="' . CursorProfile::URI
                . ' https://jsonapi.org/profiles/ethanresnick/cursor-pagination/"',
            ),
            RouteKind::Collection,
        );

        self::assertSame([CursorProfile::URI], $negotiation->contentType->profile());
    }

    public function testTheInstanceWithTheHighestWeightIsServed(): void
    {
        $negotiation = self::negotiate(
            self::request(self::JSON_API . ';q=0.5, ' . self::JSON_API . ';profile="' . CursorProfile::URI . '"'),
            RouteKind::Collection,
        );

        self::assertSame([CursorProfile::URI], $negotiation->contentType->profile());
    }

    public function testTheFirstOfEquallyPreferableInstancesIsServed(): void
    {
        $negotiation = self::negotiate(
            self::request(self::JSON_API . ', ' . self::JSON_API . ';profile="' . CursorProfile::URI . '"'),
            RouteKind::Collection,
        );

        self::assertSame([], $negotiation->contentType->profile());
    }

    public function testTheContentTypeOfARequestWithoutContentIsNotRead(): void
    {
        $negotiation = self::negotiate(
            self::request(null, 'application/x-www-form-urlencoded'),
            RouteKind::Collection,
        );

        self::assertSame(self::JSON_API, $negotiation->contentType->toHeaderValue());
    }

    #[DataProvider('contentTypesOfNoJsonApiPayload')]
    public function testContentOfAnotherMediaTypeGets415(?string $contentType): void
    {
        $exception = self::refusal(self::request(null, $contentType, '{}'), RouteKind::Collection);

        self::assertSame(415, $exception->status());
        self::assertSame([['unsupported_media_type', 'Content-Type', []]], self::problems($exception));
    }

    public function testContentOnARouteThatReadsNoneGets415(): void
    {
        try {
            (new Negotiator())->negotiate(
                self::request(null, self::JSON_API, '{}'),
                [],
                false,
                [],
                [MediaType::jsonApi()],
            );
            self::fail('content on a route that reads none is refused');
        } catch (JsonApiException $exception) {
            self::assertSame(415, $exception->status());
            self::assertSame([['unsupported_media_type', 'Content-Type', []]], self::problems($exception));
        }
    }

    public function testARouteThatReadsNoContentServesARequestWithout(): void
    {
        $negotiation = (new Negotiator())->negotiate(
            self::request(self::JSON_API, self::JSON_API),
            [],
            false,
            [],
            [MediaType::jsonApi()],
        );

        self::assertSame(self::JSON_API, $negotiation->contentType->toHeaderValue());
    }

    /**
     * @param array<array-key, mixed> $query
     */
    #[DataProvider('queriesThatPageByCursor')]
    public function testARouteThatPagesNothingAppliesNoProfileUnasked(array $query): void
    {
        $negotiation = (new Negotiator())->negotiate(
            self::request(null, null, '', $query),
            [],
            false,
            [],
            [MediaType::jsonApi()],
        );

        self::assertSame([], $negotiation->contentType->profile());
    }

    public function testTheReadsOfTheCollectionAndOfBothRoutesOfARelationshipPage(): void
    {
        self::assertTrue(Negotiator::pagesOn(RouteKind::Collection));
        self::assertTrue(Negotiator::pagesOn(RouteKind::Related));
        self::assertTrue(Negotiator::pagesOn(RouteKind::Relationship));
        self::assertFalse(Negotiator::pagesOn(RouteKind::Resource));
        self::assertFalse(Negotiator::pagesOn(RouteKind::Atomic));
    }

    public function testContentWithAForeignMediaTypeParameterGets415(): void
    {
        $exception = self::refusal(
            self::request(null, self::JSON_API . ';charset=utf-8;version=1', '{}'),
            RouteKind::Collection,
        );

        self::assertSame(415, $exception->status());
        self::assertSame(
            [
                ['unsupported_media_type_parameter', 'Content-Type', ['name' => 'charset']],
                ['unsupported_media_type_parameter', 'Content-Type', ['name' => 'version']],
            ],
            self::problems($exception),
        );
    }

    public function testContentThatNamesAnExtensionTheRouteDoesNotApplyGets415(): void
    {
        $exception = self::refusal(
            self::request(null, self::JSON_API . ';ext="' . MediaType::ATOMIC_EXTENSION . '"', '{}'),
            RouteKind::Collection,
        );

        self::assertSame(415, $exception->status());
        self::assertSame(
            [['unsupported_extension', 'Content-Type', ['extension' => MediaType::ATOMIC_EXTENSION]]],
            self::problems($exception),
        );
    }

    public function testContentOfTheAtomicRouteWithoutTheExtensionGets415(): void
    {
        $exception = self::refusal(
            self::request(null, self::JSON_API, '{"atomic:operations":[]}'),
            RouteKind::Atomic,
        );

        self::assertSame(415, $exception->status());
        self::assertSame(
            [['extension_required', 'Content-Type', ['extension' => MediaType::ATOMIC_EXTENSION]]],
            self::problems($exception),
        );
    }

    public function testContentOfTheAtomicRouteWithAForeignParameterNamesThatParameterAlone(): void
    {
        $exception = self::refusal(
            self::request(null, self::JSON_API . ';charset=utf-8', '{"atomic:operations":[]}'),
            RouteKind::Atomic,
        );

        self::assertSame(415, $exception->status());
        self::assertSame(
            [['unsupported_media_type_parameter', 'Content-Type', ['name' => 'charset']]],
            self::problems($exception),
        );
    }

    public function testTheAtomicRouteAppliesTheExtensionToARequestThatNamesItNowhere(): void
    {
        $negotiation = self::negotiate(
            self::request(self::JSON_API, null, ''),
            RouteKind::Atomic,
        );

        self::assertSame([MediaType::ATOMIC_EXTENSION], $negotiation->contentType->ext());
    }

    public function testTheContentOfAnAtomicRequestThatNamesTheExtensionIsRead(): void
    {
        $negotiation = self::negotiate(
            self::request(
                null,
                self::JSON_API . ';ext="' . MediaType::ATOMIC_EXTENSION . '"',
                '{"atomic:operations":[]}',
            ),
            RouteKind::Atomic,
        );

        self::assertSame([MediaType::ATOMIC_EXTENSION], $negotiation->contentType->ext());
    }

    public function testContentThatNamesProfilesIsRead(): void
    {
        $negotiation = self::negotiate(
            self::request(
                null,
                self::JSON_API . ';profile="https://example.com/profile '
                . 'http://jsonapi.org/profiles/ethanresnick/cursor-pagination/"',
                '{}',
            ),
            RouteKind::Collection,
        );

        self::assertSame(self::JSON_API, $negotiation->contentType->toHeaderValue());
    }

    public function testAContentTypeWrittenTwiceIsNoMediaType(): void
    {
        $request = new ServerRequest(
            'POST',
            new Headers(['Content-Type' => [self::JSON_API, self::JSON_API]]),
            [],
            [],
            '{}',
        );

        self::assertSame(415, self::refusal($request, RouteKind::Collection)->status());
    }

    public function testTheAcceptFieldIsReadBeforeTheContentType(): void
    {
        $exception = self::refusal(
            self::request('text/html', 'application/json', '{}'),
            RouteKind::Collection,
        );

        self::assertSame(406, $exception->status());
    }

    public function testTwoRequestsThatNegotiateAlikeGetTheSameMediaType(): void
    {
        $accept = self::JSON_API . ';profile="' . CursorProfile::URI . '"';

        self::assertEquals(
            self::negotiate(self::request($accept), RouteKind::Collection)->contentType,
            self::negotiate(self::request($accept), RouteKind::Related)->contentType,
        );
    }

    /**
     * @param array<array-key, mixed> $query
     */
    #[DataProvider('queriesThatPageByCursor')]
    public function testAReadingWhosePageFamilySelectsTheCursorStrategyAppliesTheProfile(array $query): void
    {
        $negotiation = self::negotiate(
            self::request(null, null, '', $query),
            RouteKind::Collection,
        );

        self::assertSame([CursorProfile::URI], $negotiation->contentType->profile());
        self::assertSame(
            self::JSON_API . ';profile="' . CursorProfile::URI . '"',
            $negotiation->contentType->toHeaderValue(),
        );
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function queriesThatPageByCursor(): iterable
    {
        yield 'page[after]' => [['page' => ['after' => 'abcde']]];
        yield 'page[before]' => [['page' => ['before' => 'abcde']]];
        yield 'a range' => [['page' => ['after' => 'abcde', 'before' => 'fghij']]];
    }

    /**
     * @param array<array-key, mixed> $query
     */
    #[DataProvider('queriesThatPageByAnotherStrategy')]
    public function testAReadingWhosePageFamilySelectsTheNumberOrTheOffsetStrategyAppliesNoProfile(array $query): void
    {
        $negotiation = self::negotiate(
            self::request(null, null, '', $query),
            RouteKind::Collection,
        );

        self::assertSame([], $negotiation->contentType->profile());
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function queriesThatPageByAnotherStrategy(): iterable
    {
        yield 'page[number]' => [['page' => ['number' => '3']]];
        yield 'page[offset]' => [['page' => ['offset' => '20']]];
        yield 'page[limit]' => [['page' => ['limit' => '5']]];
        yield 'a page family that selects nothing' => [['page' => ['size' => '3']]];
        yield 'a page family that is no map of names' => [['page' => '3']];
    }

    /**
     * @param array<array-key, mixed> $query
     */
    #[DataProvider('queriesThatSelectAnotherStrategy')]
    public function testAReadingWhosePageFamilySelectsAnotherStrategyDropsTheRequestedProfile(array $query): void
    {
        $negotiation = self::negotiate(
            self::request(self::JSON_API . ';profile="' . CursorProfile::URI . '"', null, '', $query),
            RouteKind::Collection,
        );

        self::assertSame([], $negotiation->contentType->profile());
        self::assertSame(self::JSON_API, $negotiation->contentType->toHeaderValue());
        self::assertFalse($negotiation->requestsProfile(CursorProfile::URI));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function queriesThatSelectAnotherStrategy(): iterable
    {
        yield 'page[number]' => [['page' => ['number' => '3']]];
        yield 'page[offset]' => [['page' => ['offset' => '20']]];
        yield 'page[limit]' => [['page' => ['limit' => '5']]];
    }

    /**
     * @param array<array-key, mixed> $query
     */
    #[DataProvider('queriesThatSelectNoStrategy')]
    public function testAPageFamilyThatSelectsNoStrategyKeepsTheRequestedProfile(array $query): void
    {
        $negotiation = self::negotiate(
            self::request(self::JSON_API . ';profile="' . CursorProfile::URI . '"', null, '', $query),
            RouteKind::Collection,
        );

        self::assertSame([CursorProfile::URI], $negotiation->contentType->profile());
        self::assertTrue($negotiation->requestsProfile(CursorProfile::URI));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function queriesThatSelectNoStrategy(): iterable
    {
        yield 'no page family at all' => [[]];
        yield 'a page family that selects nothing' => [['page' => ['size' => '3']]];
        yield 'a page family that is no map of names' => [['page' => '3']];
    }

    /**
     * @param array<array-key, mixed> $query
     */
    #[DataProvider('readingsThatPaginateNoPrimaryData')]
    public function testARouteThatPaginatesNoPrimaryDataKeepsTheRequestedProfile(
        RouteKind $route,
        string $method,
        array $query,
    ): void {
        $negotiation = self::negotiate(
            self::request(
                self::JSON_API . ';profile="' . CursorProfile::URI . '"',
                null,
                '',
                $query,
                $method,
            ),
            $route,
        );

        self::assertSame([CursorProfile::URI], $negotiation->contentType->profile());
        self::assertTrue($negotiation->requestsProfile(CursorProfile::URI));
    }

    /**
     * @return iterable<string, array{RouteKind, string, array<array-key, mixed>}>
     */
    public static function readingsThatPaginateNoPrimaryData(): iterable
    {
        yield 'the resource route' => [RouteKind::Resource, 'GET', ['page' => ['number' => '3']]];
        yield 'a write of a collection' => [RouteKind::Collection, 'DELETE', ['page' => ['number' => '3']]];
        yield 'a write of a relationship' => [RouteKind::Relationship, 'DELETE', ['page' => ['offset' => '3']]];
    }

    public function testAReadingWithNoPageFamilyAppliesNoProfileWhereTheAcceptFieldAsksForNone(): void
    {
        $negotiation = self::negotiate(self::request(self::JSON_API), RouteKind::Collection);

        self::assertSame([], $negotiation->contentType->profile());
    }

    /**
     * @param array<array-key, mixed> $query
     */
    #[DataProvider('routesThatPaginateTheirPrimaryData')]
    public function testTheRelatedAndTheRelationshipRouteApplyItByTheSameRule(
        RouteKind $route,
        array $query,
        bool $applied,
    ): void {
        $negotiation = self::negotiate(self::request(null, null, '', $query), $route);

        self::assertSame($applied ? [CursorProfile::URI] : [], $negotiation->contentType->profile());
    }

    /**
     * @return iterable<string, array{RouteKind, array<array-key, mixed>, bool}>
     */
    public static function routesThatPaginateTheirPrimaryData(): iterable
    {
        yield 'the related route, by cursor' => [RouteKind::Related, ['page' => ['after' => 'abcde']], true];
        yield 'the related route, by number' => [RouteKind::Related, ['page' => ['number' => '2']], false];
        yield 'the relationship route, by cursor' => [
            RouteKind::Relationship,
            ['page' => ['before' => 'abcde']],
            true,
        ];
        yield 'the relationship route, by number' => [RouteKind::Relationship, [], false];
    }

    public function testAResourceRouteAppliesItOnlyWhereTheAcceptFieldAsksForIt(): void
    {
        $cursorPage = ['page' => ['after' => 'abcde']];

        self::assertSame(
            [],
            self::negotiate(self::request(null, null, '', $cursorPage), RouteKind::Resource)->contentType->profile(),
        );
        self::assertSame(
            [CursorProfile::URI],
            self::negotiate(
                self::request(self::JSON_API . ';profile="' . CursorProfile::URI . '"'),
                RouteKind::Resource,
            )->contentType->profile(),
        );
    }

    public function testAWriteAppliesItOnlyWhereTheAcceptFieldAsksForIt(): void
    {
        $cursorPage = ['page' => ['after' => 'abcde']];

        self::assertSame(
            [],
            self::negotiate(
                self::request(null, self::JSON_API, '{}', $cursorPage),
                RouteKind::Collection,
            )->contentType->profile(),
        );
        self::assertSame(
            [CursorProfile::URI],
            self::negotiate(
                self::request(
                    self::JSON_API . ';profile="' . CursorProfile::URI . '"',
                    self::JSON_API,
                    '{}',
                    $cursorPage,
                ),
                RouteKind::Collection,
            )->contentType->profile(),
        );
    }

    public function testTheAtomicRouteAppliesTheExtensionAndNoProfileOfItsOwn(): void
    {
        $negotiation = self::negotiate(
            self::request(
                null,
                self::JSON_API . ';ext="' . MediaType::ATOMIC_EXTENSION . '"',
                '{"atomic:operations":[]}',
                ['page' => ['after' => 'abcde']],
            ),
            RouteKind::Atomic,
        );

        self::assertSame([MediaType::ATOMIC_EXTENSION], $negotiation->contentType->ext());
        self::assertSame([], $negotiation->contentType->profile());
    }

    public function testAHeadIsNegotiatedAsItsGet(): void
    {
        $cursorPage = ['page' => ['after' => 'abcde']];

        self::assertEquals(
            self::negotiate(self::request(null, null, '', $cursorPage, 'GET'), RouteKind::Collection)->contentType,
            self::negotiate(self::request(null, null, '', $cursorPage, 'HEAD'), RouteKind::Collection)->contentType,
        );
        self::assertSame(
            [CursorProfile::URI],
            self::negotiate(
                self::request(null, null, '', $cursorPage, 'HEAD'),
                RouteKind::Collection,
            )->contentType->profile(),
        );
    }

    public function testAProfileTheClientAskedForIsBothRequestedAndApplied(): void
    {
        $negotiation = self::negotiate(
            self::request(self::JSON_API . ';profile="' . CursorProfile::URI . '"'),
            RouteKind::Collection,
        );

        self::assertTrue($negotiation->requestsProfile(CursorProfile::URI));
        self::assertSame([CursorProfile::URI], $negotiation->contentType->profile());
    }

    public function testAProfileTheCursorPageAddsIsNotRequested(): void
    {
        $negotiation = self::negotiate(
            self::request(null, null, '', ['page' => ['after' => 'abcde']]),
            RouteKind::Collection,
        );

        self::assertFalse($negotiation->requestsProfile(CursorProfile::URI));
        self::assertSame([CursorProfile::URI], $negotiation->contentType->profile());
    }

    public function testAProfileTheLibraryDoesNotRecognizeIsNeitherRequestedNorApplied(): void
    {
        $unknown = 'https://example.com/profiles/whatever';

        $negotiation = self::negotiate(
            self::request(self::JSON_API . ';profile="' . $unknown . '"'),
            RouteKind::Collection,
        );

        self::assertFalse($negotiation->requestsProfile($unknown));
        self::assertSame([], $negotiation->contentType->profile());
    }

    /**
     * with one recognized profile the order cannot show;
     * what shows is that asking for the profile and paging by cursor applies it once
     */
    public function testTheRequestedProfilesStandBeforeTheOneTheCursorPageAdds(): void
    {
        $negotiation = self::negotiate(
            self::request(
                self::JSON_API . ';profile="' . CursorProfile::URI . '"',
                null,
                '',
                ['page' => ['after' => 'abcde']],
            ),
            RouteKind::Collection,
        );

        self::assertSame([CursorProfile::URI], $negotiation->contentType->profile());
        self::assertTrue($negotiation->requestsProfile(CursorProfile::URI));
    }

    public function testAProfileTheContentNamesIsNotAppliedToTheResponse(): void
    {
        $negotiation = self::negotiate(
            self::request(
                null,
                self::JSON_API . ';profile="' . CursorProfile::URI . '"',
                '{}',
                ['page' => ['after' => 'abcde']],
            ),
            RouteKind::Collection,
        );

        self::assertSame([], $negotiation->contentType->profile());
    }

    #[DataProvider('picks')]
    public function testAcceptPicksTheRepresentationOfHighestWeight(?string $accept, string $expected): void
    {
        self::assertSame($expected, self::export(self::request($accept))->contentType->toHeaderValue());
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function picks(): iterable
    {
        $sheet = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        yield 'no field' => [null, 'text/csv'];
        yield 'a malformed field' => ['text/', 'text/csv'];
        yield 'the full wildcard' => ['*/*', 'text/csv'];
        yield 'the type asked for' => ['text/csv', 'text/csv'];
        yield 'JSON:API asked for' => [self::JSON_API, self::JSON_API];
        yield 'the spreadsheet asked for' => [$sheet, $sheet];
        yield 'a weight' => ['text/*;q=0.5, ' . $sheet, $sheet];
        yield 'a more specific range overrides a wildcard' => [
            'text/*;q=0.2, text/csv;q=0.9, application/*;q=0.5',
            'text/csv',
        ];
        yield 'a type ruled out by 0' => ['text/csv;q=0, */*', self::JSON_API];
        yield 'equal weights pick the first declared' => [self::JSON_API . ', text/csv', 'text/csv'];
        yield 'a JSON:API instance of 0 leaves the others' => [self::JSON_API . ';q=0, application/*', $sheet];
        yield 'a range with a parameter the type lacks' => ['text/csv;header=absent, ' . $sheet . ';q=0.1', $sheet];
        yield 'the first written of equally specific ranges' => [
            'text/csv;q=0.2, text/csv;q=0.9, application/*;q=0.5',
            self::JSON_API,
        ];
    }

    public function testARangeWithTheParametersOfTheDeclaredTypeAdmitsIt(): void
    {
        $negotiation = (new Negotiator())->negotiate(
            self::request('text/csv;header=present'),
            [],
            false,
            [],
            [self::mediaType('text/csv; header=present'), MediaType::jsonApi()],
        );

        self::assertSame('text/csv;header=present', $negotiation->contentType->toHeaderValue());
    }

    public function testAnotherRepresentationIsWrittenAsDeclaredAndItsErrorsAsJsonApi(): void
    {
        $negotiation = self::export(self::request('text/csv'));

        self::assertSame('text/csv', $negotiation->representation()->toHeaderValue());
        self::assertSame(self::JSON_API, $negotiation->documentContentType()->toHeaderValue());
        self::assertFalse($negotiation->requestsProfile(CursorProfile::URI));
    }

    public function testARequestWithNoAcceptableRepresentationGets406(): void
    {
        try {
            self::export(self::request('image/png'));
            self::fail('the request is refused by the negotiation');
        } catch (JsonApiException $exception) {
            self::assertSame(406, $exception->status());
            self::assertSame([['not_acceptable', 'Accept', []]], self::problems($exception));
        }
    }

    public function testInstancesOfJsonApiThatAreAllIgnoredGet406Anywhere(): void
    {
        try {
            self::export(self::request(self::JSON_API . ';charset=utf-8, text/csv'));
            self::fail('the request is refused by the negotiation');
        } catch (JsonApiException $exception) {
            self::assertSame(406, $exception->status());
            self::assertSame(
                [['unsupported_media_type_parameter', 'Accept', ['name' => 'charset']]],
                self::problems($exception),
            );
        }
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('readableContents')]
    public function testContentIsReadWhereADeclaredMediaTypeAdmitsIt(array $headers, string $body): void
    {
        $negotiation = (new Negotiator())->negotiate(
            new ServerRequest('POST', new Headers($headers), [], [], $body),
            [],
            false,
            self::readable(),
            [MediaType::jsonApi()],
        );

        self::assertSame(self::JSON_API, $negotiation->contentType->toHeaderValue());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function readableContents(): iterable
    {
        yield 'a declared type' => [['Content-Type' => 'application/json'], '{}'];
        yield 'a declared type with a parameter' => [['Content-Type' => 'Application/JSON; charset=utf-8'], '{}'];
        yield 'a multipart body PHP took apart' => [
            ['Content-Type' => 'multipart/form-data; boundary=x', 'Content-Length' => '4812'],
            '',
        ];
        yield 'a length of 0 carries nothing to refuse' => [
            ['Content-Type' => 'text/plain', 'Content-Length' => '0'],
            '',
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('unreadableContents')]
    public function testContentNoDeclaredMediaTypeAdmitsGets415(array $headers, string $body): void
    {
        try {
            (new Negotiator())->negotiate(
                new ServerRequest('POST', new Headers($headers), [], [], $body),
                [],
                false,
                self::readable(),
                [MediaType::jsonApi()],
            );
            self::fail('the request is refused by the negotiation');
        } catch (JsonApiException $exception) {
            self::assertSame(415, $exception->status());
            self::assertSame([['unsupported_media_type', 'Content-Type', []]], self::problems($exception));
        }
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function unreadableContents(): iterable
    {
        yield 'another type' => [['Content-Type' => 'text/plain'], 'Rex'];
        yield 'JSON:API where none is read' => [['Content-Type' => self::JSON_API], '{}'];
        yield 'no Content-Type' => [['Content-Length' => '3'], ''];
    }

    public function testAMultipartBodyOnARouteThatReadsNoneGets415(): void
    {
        $request = new ServerRequest(
            'POST',
            new Headers(['Content-Type' => 'multipart/form-data; boundary=x', 'Content-Length' => '4812']),
            [],
            [],
            '',
        );

        try {
            (new Negotiator())->negotiate($request, [], false, [], [MediaType::jsonApi()]);
            self::fail('the request is refused by the negotiation');
        } catch (JsonApiException $exception) {
            self::assertSame(415, $exception->status());
        }
    }

    /**
     * @return list<MediaType>
     */
    private static function readable(): array
    {
        return [self::mediaType('application/json'), self::mediaType('multipart/form-data')];
    }

    private static function export(ServerRequest $request): Negotiation
    {
        return (new Negotiator())->negotiate(
            $request,
            [],
            false,
            [],
            [
                self::mediaType('text/csv'),
                MediaType::jsonApi(),
                self::mediaType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            ],
        );
    }

    private static function mediaType(string $text): MediaType
    {
        return MediaType::parse($text) ?? throw new InvalidArgumentException($text);
    }

    private static function negotiate(ServerRequest $request, RouteKind $route): Negotiation
    {
        return (new Negotiator())->negotiateRoute($request, $route);
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private static function request(
        ?string $accept = null,
        ?string $contentType = null,
        string $body = '',
        array $query = [],
        ?string $method = null,
    ): ServerRequest {
        $fields = [];

        if ($accept !== null) {
            $fields['Accept'] = $accept;
        }

        if ($contentType !== null) {
            $fields['Content-Type'] = $contentType;
        }

        return new ServerRequest(
            $method ?? ($body === '' ? 'GET' : 'POST'),
            new Headers($fields),
            $query,
            [],
            $body,
        );
    }

    private static function refusal(ServerRequest $request, RouteKind $route): JsonApiException
    {
        try {
            self::negotiate($request, $route);
        } catch (JsonApiException $exception) {
            return $exception;
        }

        self::fail('the request is refused by the negotiation');
    }

    /**
     * @return list<array{string, ?string, array<string, scalar>}>
     */
    private static function problems(JsonApiException $exception): array
    {
        return array_map(
            static function (ErrorDescription $description): array {
                return [
                    $description->code instanceof ErrorCode ? $description->code->value : $description->code,
                    $description->header,
                    $description->parameters,
                ];
            },
            $exception->descriptions(),
        );
    }
}
