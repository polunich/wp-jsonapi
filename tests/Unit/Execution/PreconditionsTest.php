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

use OutOfBoundsException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Error\PreconditionFailedException;
use Polunich\WpJsonApi\Error\PreconditionRequiredException;
use Polunich\WpJsonApi\Execution\ExecutionContext;
use Polunich\WpJsonApi\Execution\Preconditions;
use Polunich\WpJsonApi\Http\FieldName;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Http\VersionTag;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\ClientIdPolicy;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\RouteExclusion;
use Polunich\WpJsonApi\Metadata\RouteOwner;
use Polunich\WpJsonApi\Metadata\VersionPolicy;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use stdClass;
use UnexpectedValueException;

/**
 * @internal
 */
#[CoversClass(Preconditions::class)]
final class PreconditionsTest extends TestCase
{
    private const TYPE = 'pets';

    private const UNVERSIONED = 'orders';

    private const VERSION = 'v1';

    private const OTHER_VERSION = 'another-record';

    private const ATOMIC = 'https://jsonapi.org/ext/atomic';

    /** `ATOMIC` as `VersionTag` percent-encodes it into a tag */
    private const ATOMIC_PCT = 'https%3A%2F%2Fjsonapi.org%2Fext%2Fatomic';

    public function testTheTagCoversTheVersionTheContentLanguageAndTheNegotiatedMediaType(): void
    {
        $record = new stdClass();

        $tag = self::preconditions(self::policy($record))->entityTag(
            self::TYPE,
            $record,
            self::context(contentLanguage: 'de', contentType: MediaType::jsonApi([self::ATOMIC])),
        );

        self::assertNotNull($tag);
        self::assertSame(self::VERSION . "'lde'e" . self::ATOMIC_PCT, $tag->value);
        self::assertFalse($tag->weak);
    }

    public function testTheTagIsReadOffTheRecordItIsGiven(): void
    {
        $record = new stdClass();
        $preconditions = self::preconditions(self::policy($record));

        self::assertSame(self::VERSION, $preconditions->entityTag(self::TYPE, $record, self::context())?->value);
        self::assertSame(
            self::OTHER_VERSION,
            $preconditions->entityTag(self::TYPE, new stdClass(), self::context())?->value,
        );
    }

    public function testNoTagIsWrittenInsideAnAtomicRequest(): void
    {
        $record = new stdClass();
        $preconditions = self::preconditions(self::policy($record));

        self::assertNull($preconditions->entityTag(self::TYPE, $record, self::context(position: 0)));
        self::assertNotNull($preconditions->entityTag(self::TYPE, $record, self::context()));
    }

    public function testNoTagIsWrittenForARequestThatSuppliesInclude(): void
    {
        $record = new stdClass();
        $preconditions = self::preconditions(self::policy($record));

        self::assertNull($preconditions->entityTag(self::TYPE, $record, self::context(includeSupplied: true)));
        self::assertNotNull($preconditions->entityTag(self::TYPE, $record, self::context()));
    }

    public function testNoTagIsWrittenWithoutARecord(): void
    {
        self::assertNull(
            self::preconditions(self::policy(new stdClass()))->entityTag(self::TYPE, null, self::context()),
        );
    }

    public function testNoTagIsWrittenForATypeThatDeclaresNoVersionPolicy(): void
    {
        $record = new stdClass();
        $preconditions = self::preconditions(self::policy($record));

        self::assertNull($preconditions->entityTag(self::UNVERSIONED, $record, self::context()));
        self::assertNotNull($preconditions->entityTag(self::TYPE, $record, self::context()));
    }

    public function testTheTagRefusesAVersionAccessorThatReturnsNoString(): void
    {
        $this->expectException(UnexpectedValueException::class);

        self::preconditions(new VersionPolicy(static function (object $record): int {
            return 7;
        }, false))
            ->entityTag(self::TYPE, new stdClass(), self::context());
    }

    public function testTheTagOfATypeThatIsNotDeclaredIsRefused(): void
    {
        $this->expectException(OutOfBoundsException::class);

        self::preconditions(null)->entityTag('nowhere', new stdClass(), self::context());
    }

    #[DataProvider('conditionalWrites')]
    public function testAConditionalWriteWithoutIfMatchIsRefusedWhereTheTypeRequiresPreconditions(
        OperationKind $kind,
    ): void {
        $record = new stdClass();

        try {
            self::preconditions(self::policy($record, true))
                ->evaluate($kind, self::TYPE, $record, self::context(method: 'PATCH'));
            self::fail('a conditional write of a type that requires preconditions was let through');
        } catch (PreconditionRequiredException $refusal) {
            self::assertDescribes($refusal, 428, ErrorCode::PreconditionRequired, [], FieldName::IF_MATCH);
        }
    }

    #[DataProvider('kindsThatNeedNoPrecondition')]
    public function testAFetchAndACreateAreNeverRefusedForCarryingNoIfMatch(OperationKind $kind, bool $loaded): void
    {
        $record = new stdClass();

        self::assertNull(self::preconditions(self::policy($record, true))->evaluate(
            $kind,
            self::TYPE,
            $loaded ? $record : null,
            self::context(method: $kind->isFetch() ? 'GET' : 'POST'),
        ));
    }

    public function testATypeThatRequiresNoPreconditionTakesAnUnconditionalWrite(): void
    {
        $record = new stdClass();
        $context = self::context(method: 'PATCH');

        self::assertNull(self::preconditions(self::policy($record))
            ->evaluate(OperationKind::UpdateResource, self::TYPE, $record, $context));
        self::assertNull(self::preconditions(self::policy($record, true))
            ->evaluate(OperationKind::UpdateResource, self::UNVERSIONED, $record, $context));
    }

    /**
     * an empty If-Match is a list of no tag: the field was sent, so the response is 412 and not 428
     */
    public function testAnEmptyIfMatchGets412AndNot428(): void
    {
        $record = new stdClass();

        try {
            self::preconditions(self::policy($record, true))->evaluate(
                OperationKind::UpdateResource,
                self::TYPE,
                $record,
                self::context([FieldName::IF_MATCH => ''], 'PATCH'),
            );
            self::fail('an If-Match that names no tag was let through');
        } catch (PreconditionFailedException $refusal) {
            self::assertDescribes(
                $refusal,
                412,
                ErrorCode::PreconditionFailed,
                ['field' => FieldName::IF_MATCH],
                FieldName::IF_MATCH,
            );
        }
    }

    #[DataProvider('malformedIfMatchValues')]
    public function testAMalformedIfMatchIsAnInvalidPrecondition(string $fieldValue): void
    {
        $record = new stdClass();

        try {
            self::preconditions(self::policy($record))->evaluate(
                OperationKind::UpdateResource,
                self::TYPE,
                $record,
                self::context([FieldName::IF_MATCH => $fieldValue], 'PATCH'),
            );
            self::fail('a malformed If-Match was let through');
        } catch (PreconditionFailedException $refusal) {
            self::assertDescribes($refusal, 412, ErrorCode::InvalidPrecondition, [], FieldName::IF_MATCH);
        }
    }

    public function testAListThatNamesTheTagOfTheRepresentationHolds(): void
    {
        $record = new stdClass();

        self::assertNull(self::preconditions(self::policy($record))->evaluate(
            OperationKind::UpdateResource,
            self::TYPE,
            $record,
            self::context([FieldName::IF_MATCH => '"another", ' . self::tag()], 'PATCH'),
        ));
    }

    public function testAnIfMatchSentOnTwoLinesIsOneList(): void
    {
        $record = new stdClass();

        self::assertNull(self::preconditions(self::policy($record))->evaluate(
            OperationKind::UpdateResource,
            self::TYPE,
            $record,
            self::context([FieldName::IF_MATCH => ['"another"', self::tag()]], 'PATCH'),
        ));
    }

    public function testAListThatNamesAnotherTagDoesNotHold(): void
    {
        $record = new stdClass();

        try {
            self::preconditions(self::policy($record))->evaluate(
                OperationKind::UpdateResource,
                self::TYPE,
                $record,
                self::context([FieldName::IF_MATCH => self::tag('v0') . ', ' . self::tag('v2')], 'PATCH'),
            );
            self::fail('an If-Match that names another version was let through');
        } catch (PreconditionFailedException $refusal) {
            self::assertDescribes(
                $refusal,
                412,
                ErrorCode::PreconditionFailed,
                ['field' => FieldName::IF_MATCH],
                FieldName::IF_MATCH,
            );
        }
    }

    public function testTheWildcardHoldsForARepresentationWithATagAndForOneWithout(): void
    {
        $record = new stdClass();
        $preconditions = self::preconditions(self::policy($record));
        $context = self::context([FieldName::IF_MATCH => '*'], 'PATCH');

        self::assertNull($preconditions->evaluate(OperationKind::UpdateResource, self::TYPE, $record, $context));
        self::assertNull($preconditions->evaluate(OperationKind::UpdateResource, self::UNVERSIONED, $record, $context));
    }

    public function testAListNeverHoldsForARepresentationWithoutATag(): void
    {
        $record = new stdClass();

        try {
            self::preconditions(self::policy($record))->evaluate(
                OperationKind::UpdateResource,
                self::UNVERSIONED,
                $record,
                self::context([FieldName::IF_MATCH => self::tag()], 'PATCH'),
            );
            self::fail('a list of entity tags held for a representation without a tag');
        } catch (PreconditionFailedException $refusal) {
            self::assertSame(412, $refusal->status());
        }
    }

    public function testTheRelatedRouteComparesAgainstNoTagAlthoughTheParentWasLoaded(): void
    {
        $record = new stdClass();
        $preconditions = self::preconditions(self::policy($record));
        $listed = self::context([FieldName::IF_MATCH => self::tag()]);

        self::assertNull($preconditions->evaluate(OperationKind::FetchResource, self::TYPE, $record, $listed));
        self::assertNull($preconditions->evaluate(
            OperationKind::FetchRelated,
            self::TYPE,
            $record,
            self::context([FieldName::IF_MATCH => '*']),
        ));

        try {
            $preconditions->evaluate(OperationKind::FetchRelated, self::TYPE, $record, $listed);
            self::fail('the related route compared against the tag of its parent');
        } catch (PreconditionFailedException $refusal) {
            self::assertSame(412, $refusal->status());
        }
    }

    #[DataProvider('methodsThatReadARepresentation')]
    public function testAMatchingIfNoneMatchGets304WithTheTagOfTheRepresentation(string $method): void
    {
        $record = new stdClass();

        $result = self::preconditions(self::policy($record))->evaluate(
            OperationKind::FetchResource,
            self::TYPE,
            $record,
            self::context([FieldName::IF_NONE_MATCH => self::tag()], $method),
        );

        self::assertNotNull($result);
        self::assertSame(304, $result->status());
        self::assertSame(self::VERSION, $result->entityTag()?->value);
    }

    #[DataProvider('methodsThatWrite')]
    public function testAMatchingIfNoneMatchOnAMethodThatIsNoReadIs412(string $method, OperationKind $kind): void
    {
        $record = new stdClass();

        try {
            self::preconditions(self::policy($record))->evaluate(
                $kind,
                self::TYPE,
                $record,
                self::context([FieldName::IF_NONE_MATCH => self::tag()], $method),
            );
            self::fail('an If-None-Match that matched was let through on a write');
        } catch (PreconditionFailedException $refusal) {
            self::assertDescribes(
                $refusal,
                412,
                ErrorCode::PreconditionFailed,
                ['field' => FieldName::IF_NONE_MATCH],
                FieldName::IF_NONE_MATCH,
            );
        }
    }

    public function testAWildcardIfNoneMatchMatchesARepresentationWithoutATag(): void
    {
        $record = new stdClass();

        $result = self::preconditions(self::policy($record))->evaluate(
            OperationKind::FetchResource,
            self::UNVERSIONED,
            $record,
            self::context([FieldName::IF_NONE_MATCH => '*']),
        );

        self::assertNotNull($result);
        self::assertSame(304, $result->status());
        self::assertNull($result->entityTag());
    }

    public function testAnIfNoneMatchThatNamesAnotherTagLetsTheMethodRun(): void
    {
        $record = new stdClass();

        self::assertNull(self::preconditions(self::policy($record))->evaluate(
            OperationKind::FetchResource,
            self::TYPE,
            $record,
            self::context([FieldName::IF_NONE_MATCH => self::tag('v0')]),
        ));
    }

    #[DataProvider('malformedIfMatchValues')]
    public function testAMalformedIfNoneMatchIsIgnored(string $fieldValue): void
    {
        $record = new stdClass();

        self::assertNull(self::preconditions(self::policy($record))->evaluate(
            OperationKind::FetchResource,
            self::TYPE,
            $record,
            self::context([FieldName::IF_NONE_MATCH => $fieldValue]),
        ));
    }

    public function testIfMatchIsEvaluatedBeforeIfNoneMatch(): void
    {
        $record = new stdClass();
        $preconditions = self::preconditions(self::policy($record));
        $matching = self::tag();

        try {
            $preconditions->evaluate(OperationKind::FetchResource, self::TYPE, $record, self::context([
                FieldName::IF_MATCH => self::tag('v0'),
                FieldName::IF_NONE_MATCH => $matching,
            ]));
            self::fail('the If-None-Match behind an If-Match that does not hold decided the response');
        } catch (PreconditionFailedException $refusal) {
            self::assertDescribes(
                $refusal,
                412,
                ErrorCode::PreconditionFailed,
                ['field' => FieldName::IF_MATCH],
                FieldName::IF_MATCH,
            );
        }

        $result = $preconditions->evaluate(OperationKind::FetchResource, self::TYPE, $record, self::context([
            FieldName::IF_MATCH => $matching,
            FieldName::IF_NONE_MATCH => $matching,
        ]));

        self::assertSame(304, $result?->status());
    }

    public function testTheAtomicRouteTakesARequestWithoutIfMatchAndEvaluatesNoIfNoneMatch(): void
    {
        $this->expectNotToPerformAssertions();

        self::preconditions(null)->evaluateAtomicRoute(
            self::context([FieldName::IF_NONE_MATCH => '*'], 'POST'),
        );
    }

    #[DataProvider('ifMatchValuesTheAtomicRouteRefuses')]
    public function testTheAtomicRouteRefusesEveryIfMatchItCanRead(string $fieldValue): void
    {
        try {
            self::preconditions(null)->evaluateAtomicRoute(
                self::context([FieldName::IF_MATCH => $fieldValue], 'POST'),
            );
            self::fail('an If-Match on the atomic route was let through');
        } catch (PreconditionFailedException $refusal) {
            self::assertDescribes(
                $refusal,
                412,
                ErrorCode::PreconditionFailed,
                ['field' => FieldName::IF_MATCH],
                FieldName::IF_MATCH,
            );
        }
    }

    #[DataProvider('malformedIfMatchValues')]
    public function testTheAtomicRouteRespondsToAMalformedIfMatchWithAnInvalidPrecondition(string $fieldValue): void
    {
        try {
            self::preconditions(null)->evaluateAtomicRoute(
                self::context([FieldName::IF_MATCH => $fieldValue], 'POST'),
            );
            self::fail('a malformed If-Match on the atomic route was let through');
        } catch (PreconditionFailedException $refusal) {
            self::assertDescribes($refusal, 412, ErrorCode::InvalidPrecondition, [], FieldName::IF_MATCH);
        }
    }

    public function testARequestWithoutIfMatchCarriesNoPreconditionIntoTheWrite(): void
    {
        self::assertNull(self::preconditions(null)->precondition(self::context()));
    }

    public function testTheStoreReceivesAnyVersionForTheWildcard(): void
    {
        $precondition = self::preconditions(null)
            ->precondition(self::context([FieldName::IF_MATCH => '*']));

        self::assertNotNull($precondition);
        self::assertNull($precondition->versions);
        self::assertTrue($precondition->admits('whatever the record holds'));
    }

    /**
     * `v3` is tagged without the content language `de`,
     * so it belongs to another negotiation and is left out
     */
    public function testTheStoreReceivesTheVersionsOfTheListedTagsOfThisNegotiation(): void
    {
        $contentType = MediaType::jsonApi([self::ATOMIC]);
        $field = implode(', ', [
            self::tag('v1', 'de', $contentType),
            self::tag('v2', 'de', $contentType),
            self::tag('v3', null, $contentType),
        ]);

        $precondition = self::preconditions(null)->precondition(self::context(
            [FieldName::IF_MATCH => $field],
            contentLanguage: 'de',
            contentType: $contentType,
        ));

        self::assertNotNull($precondition);
        self::assertSame(['v1', 'v2'], $precondition->versions);
        self::assertTrue($precondition->admits('v2'));
        self::assertFalse($precondition->admits('v3'));
    }

    public function testAMalformedIfMatchNeverBecomesAPrecondition(): void
    {
        try {
            self::preconditions(null)->precondition(self::context([FieldName::IF_MATCH => 'v1']));
            self::fail('a malformed If-Match became a precondition');
        } catch (UnexpectedValueException $refusal) {
            self::assertStringContainsString('v1', $refusal->getMessage());
        }
    }

    public function testTagsThatNameNoVersionOfThisNegotiationNeverBecomeAPrecondition(): void
    {
        try {
            self::preconditions(null)->precondition(self::context([FieldName::IF_MATCH => 'W/"v1"']));
            self::fail('a field that names no version became a precondition');
        } catch (UnexpectedValueException $refusal) {
            self::assertStringContainsString('W/"v1"', $refusal->getMessage());
        }
    }

    #[DataProvider('conditionalWrites')]
    public function testAnOperationOfATypeThatRequiresPreconditionsIsRefused(OperationKind $kind): void
    {
        try {
            self::preconditions(self::policy(new stdClass(), true))
                ->refuseUnconditionalOperation($kind, self::TYPE);
            self::fail('an operation of a type that requires preconditions was let through');
        } catch (PreconditionRequiredException $refusal) {
            self::assertDescribes($refusal, 428, ErrorCode::PreconditionRequired, [], FieldName::IF_MATCH);
        }
    }

    public function testAnOperationThatNeedsNoPreconditionRuns(): void
    {
        $this->expectNotToPerformAssertions();

        self::preconditions(self::policy(new stdClass(), true))
            ->refuseUnconditionalOperation(OperationKind::CreateResource, self::TYPE);
        self::preconditions(self::policy(new stdClass(), true))
            ->refuseUnconditionalOperation(OperationKind::UpdateResource, self::UNVERSIONED);
        self::preconditions(self::policy(new stdClass()))
            ->refuseUnconditionalOperation(OperationKind::UpdateResource, self::TYPE);
    }

    #[DataProvider('preconditionFields')]
    public function testThe412NamesTheFieldInItsDetailAndAsItsSource(string $field): void
    {
        self::assertDescribes(
            Preconditions::preconditionFailed($field),
            412,
            ErrorCode::PreconditionFailed,
            ['field' => $field],
            $field,
        );
    }

    public function testAPreconditionThisClassRefusedAndOneAStoreReportsAreRespondedToAlike(): void
    {
        $record = new stdClass();

        try {
            self::preconditions(self::policy($record))->evaluate(
                OperationKind::UpdateResource,
                self::TYPE,
                $record,
                self::context([FieldName::IF_MATCH => self::tag('v0')], 'PATCH'),
            );
            self::fail('an If-Match that does not hold was let through');
        } catch (PreconditionFailedException $refusal) {
            self::assertEquals(
                Preconditions::preconditionFailed(FieldName::IF_MATCH)->descriptions(),
                $refusal->descriptions(),
            );
        }
    }

    /**
     * @return iterable<string, array{OperationKind}>
     */
    public static function conditionalWrites(): iterable
    {
        yield 'update resource' => [OperationKind::UpdateResource];
        yield 'delete resource' => [OperationKind::DeleteResource];
        yield 'replace relationship' => [OperationKind::ReplaceRelationship];
        yield 'add to relationship' => [OperationKind::AddToRelationship];
        yield 'remove from relationship' => [OperationKind::RemoveFromRelationship];
    }

    /**
     * each kind with whether a target record is loaded for it
     *
     * @return iterable<string, array{OperationKind, bool}>
     */
    public static function kindsThatNeedNoPrecondition(): iterable
    {
        yield 'fetch collection' => [OperationKind::FetchCollection, false];
        yield 'fetch resource' => [OperationKind::FetchResource, true];
        yield 'fetch related' => [OperationKind::FetchRelated, true];
        yield 'fetch relationship' => [OperationKind::FetchRelationship, true];
        yield 'create resource' => [OperationKind::CreateResource, false];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedIfMatchValues(): iterable
    {
        yield 'a version without its quotes' => ['v1'];
        yield 'a tag that is never closed' => ['"v1'];
        yield 'the wildcard inside a list' => ['*, "v1"'];
        yield 'two tags without their comma' => ['"v1" "v2"'];
        yield 'a weak wildcard' => ['W/*'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ifMatchValuesTheAtomicRouteRefuses(): iterable
    {
        yield 'the wildcard' => ['*'];
        yield 'a list of one tag' => ['"v1"'];
        yield 'a list of two tags' => ['"v1", W/"v2"'];
        yield 'a list of no tag' => [''];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function methodsThatReadARepresentation(): iterable
    {
        yield 'get' => ['GET'];
        yield 'head' => ['HEAD'];
    }

    /**
     * @return iterable<string, array{string, OperationKind}>
     */
    public static function methodsThatWrite(): iterable
    {
        yield 'patch' => ['PATCH', OperationKind::UpdateResource];
        yield 'post' => ['POST', OperationKind::AddToRelationship];
        yield 'delete' => ['DELETE', OperationKind::DeleteResource];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function preconditionFields(): iterable
    {
        yield 'if-match' => [FieldName::IF_MATCH];
        yield 'if-none-match' => [FieldName::IF_NONE_MATCH];
    }

    public function testAnEndpointWithoutAPreconditionRecordHasNoTag(): void
    {
        $preconditions = self::preconditions(self::policy(new stdClass()));
        $endpoint = Samples::endpoint(
            'stats',
            'GET',
            '/stats',
            [],
            responses: [204 => new ResponseMetadata(ResponseShapeKind::NoContent, null, null, null, null, null)],
        );

        self::assertNull($preconditions->evaluateEndpoint(
            $endpoint,
            null,
            null,
            self::context([FieldName::IF_MATCH => '*']),
        ));
        self::assertNull($preconditions->evaluateEndpoint(
            $endpoint,
            null,
            null,
            self::context([FieldName::IF_NONE_MATCH => self::tag()]),
        ));

        try {
            $preconditions->evaluateEndpoint(
                $endpoint,
                null,
                null,
                self::context([FieldName::IF_MATCH => self::tag()]),
            );
            self::fail('a listed If-Match held where no representation carries a tag');
        } catch (PreconditionFailedException $failure) {
            self::assertSame(412, $failure->status());
        }
    }

    public function testAGetEndpointRespondsWithNotModifiedForTheTagOfItsRecord(): void
    {
        $record = new stdClass();
        $preconditions = self::preconditions(self::policy($record));
        $endpoint = Samples::endpoint('petCard', 'GET', '/pets/{id}/card', preconditionParameter: 'id');

        $notModified = $preconditions->evaluateEndpoint(
            $endpoint,
            self::TYPE,
            $record,
            self::context([FieldName::IF_NONE_MATCH => self::tag()]),
        );

        self::assertNotNull($notModified);
        self::assertSame(304, $notModified->status());
        self::assertSame(self::tag(), $notModified->entityTag()?->toHeaderValue());
    }

    public function testAWriteOnARecordOfARequiringTypeIsConditionalUnlessItLeavesTheRecordUnchanged(): void
    {
        $record = new stdClass();
        $preconditions = self::preconditions(self::policy($record, true));
        $write = Samples::endpoint(preconditionParameter: 'id');
        $unchanged = Samples::endpoint(preconditionParameter: 'id', leavesUnchanged: true);

        self::assertNull($preconditions->evaluateEndpoint($unchanged, self::TYPE, $record, self::context([], 'POST')));
        self::assertNull($preconditions->evaluateEndpoint(
            $write,
            self::TYPE,
            $record,
            self::context([FieldName::IF_MATCH => self::tag()], 'POST'),
        ));

        try {
            $preconditions->evaluateEndpoint($write, self::TYPE, $record, self::context([], 'POST'));
            self::fail('a write without If-Match passed on a type that requires one');
        } catch (PreconditionRequiredException $failure) {
            self::assertSame(428, $failure->status());
        }
    }

    public function testAWriteWhoseIfMatchNamesAnotherVersionFails(): void
    {
        $record = new stdClass();

        $this->expectException(PreconditionFailedException::class);

        self::preconditions(self::policy($record))->evaluateEndpoint(
            Samples::endpoint(preconditionParameter: 'id'),
            self::TYPE,
            $record,
            self::context([FieldName::IF_MATCH => self::tag(self::OTHER_VERSION)], 'POST'),
        );
    }

    /**
     * @param array<string, scalar> $parameters
     */
    private static function assertDescribes(
        JsonApiException $failure,
        int $status,
        ErrorCode $code,
        array $parameters,
        string $header,
    ): void {
        self::assertSame($status, $failure->status());
        self::assertCount(1, $failure->descriptions());

        $description = $failure->descriptions()[0];

        self::assertSame($code, $description->code);
        self::assertSame($parameters, $description->parameters);
        self::assertSame($header, $description->header);
        self::assertNull($description->pointer);
        self::assertNull($description->parameter);
    }

    private static function preconditions(?VersionPolicy $policy): Preconditions
    {
        return new Preconditions(new ResourceRegistry([
            self::resource(self::TYPE, $policy),
            self::resource(self::UNVERSIONED, null),
        ]));
    }

    /**
     * returns `VERSION` for `$record` alone, so a tag read off any other record differs
     */
    private static function policy(object $record, bool $required = false): VersionPolicy
    {
        return new VersionPolicy(
            static function (object $given) use ($record): string {
                return $given === $record ? self::VERSION : self::OTHER_VERSION;
            },
            $required,
        );
    }

    private static function resource(string $type, ?VersionPolicy $policy): ResourceMetadata
    {
        return new ResourceMetadata(
            $type,
            Samples::id(),
            'id',
            [],
            [],
            [Capability::ResourceLoader->value => Samples::implementation()],
            [OperationKind::FetchResource->value => Samples::implementation()],
            [],
            RouteExclusion::none(RouteOwner::Resource),
            ClientIdPolicy::Forbidden,
            $policy,
            null,
            PaginationPolicy::libraryDefault(),
            1,
            null,
        );
    }

    /**
     * @param array<string, list<string>|string> $headers a list stands for several field lines
     */
    private static function context(
        array $headers = [],
        string $method = 'GET',
        ?string $contentLanguage = null,
        bool $includeSupplied = false,
        ?int $position = null,
        ?MediaType $contentType = null,
    ): ExecutionContext {
        return new ExecutionContext(
            new ServerRequest($method, new Headers($headers), [], [], ''),
            $contentType ?? MediaType::jsonApi(),
            $contentLanguage,
            $includeSupplied,
            false,
            [],
            null,
            $position,
        );
    }

    private static function tag(
        string $version = self::VERSION,
        ?string $contentLanguage = null,
        ?MediaType $contentType = null,
    ): string {
        return VersionTag::encode($version, $contentLanguage, $contentType ?? MediaType::jsonApi())->toHeaderValue();
    }
}
