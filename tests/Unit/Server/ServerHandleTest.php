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

namespace Polunich\WpJsonApi\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Polunich\WpJsonApi\Access\OperationAttempt;
use Polunich\WpJsonApi\Endpoint\Request;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Http\Content;
use Polunich\WpJsonApi\Metadata\EndpointRoute;
use Polunich\WpJsonApi\Operation\ConditionalWrite;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Parsing\CursorProfile;
use Polunich\WpJsonApi\Parsing\OperationParser;
use Polunich\WpJsonApi\Server\Server;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use Polunich\WpJsonApi\Tests\Fixtures\Server\JournaledServerTestCase;

/**
 * `handle()` on a generated route: the steps of a request in their order,
 * the languages, the loads, the preconditions and the negotiation
 *
 * @internal
 */
#[CoversClass(Server::class)]
final class ServerHandleTest extends JournaledServerTestCase
{
    public function testAMethodTheRouteDoesNotRespondToGetsFiveHundredAndIsLogged(): void
    {
        $journal = self::journal();

        $response = self::server($journal)->handle(
            self::route(OperationKind::CreateResource),
            self::request('GET'),
        );

        self::assertSame(500, $response->status);
        self::assertSame([ErrorCode::InternalError->value], self::codes($response));
        self::assertFalse($response->headers->has('Allow'));
        self::assertLogicExceptionWasLogged($journal);
    }

    /**
     * the Accept field admits no representation, so a negotiation run first would end in 406.
     * the bare media type pins that `handle()` starts without a content type
     */
    public function testAMethodTheRouteDoesNotRespondToGetsFiveHundredBeforeTheNegotiation(): void
    {
        $journal = self::journal();

        $response = self::server($journal)->handle(
            self::route(OperationKind::CreateResource),
            self::request('GET', headers: ['Accept' => 'text/html']),
        );

        self::assertSame(500, $response->status);
        self::assertSame([ErrorCode::InternalError->value], self::codes($response));
        self::assertSame(self::JSON_API, $response->headers->line('Content-Type'));
        self::assertLogicExceptionWasLogged($journal);
    }

    /**
     * the negotiation and the query write no journal entry;
     * the precedence tests below pin their place in the order
     */
    public function testTheStepsOfAReadingRunInOrder(): void
    {
        $journal = self::journal();

        self::server($journal)->handle(
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        );

        self::assertSame(['language', 'load', 'level2', 'process'], self::steps($journal));
    }

    /**
     * a content write has no operation yet when its target is loaded,
     * and still walks the same steps
     */
    public function testTheStepsOfAContentWriteRunInOrder(): void
    {
        $journal = self::journal();

        $response = self::server($journal)->handle(
            self::route(OperationKind::UpdateResource),
            self::request('PATCH', [self::ID_PARAMETER => self::ID], body: self::updateBody(self::TYPE, self::ID)),
        );

        self::assertSame(200, $response->status);
        self::assertSame(['language', 'load', 'level2', 'process'], self::steps($journal));
    }

    public function testTheNegotiationRunsOnceAndItsMediaTypeIsTheResponseMediaType(): void
    {
        $journal = self::journal();

        $response = self::server($journal)->handle(
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        );

        [, $context] = self::processed($journal);

        self::assertSame($context->contentType->toHeaderValue(), $response->headers->line('Content-Type'));
        self::assertSame(self::JSON_API, $response->headers->line('Content-Type'));
    }

    public function testTheContentLanguageIsResolvedOnceWithThePreferencesOfTheRequest(): void
    {
        $journal = self::journal();

        $response = self::server($journal, language: 'uk')->handle(
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID], headers: ['Accept-Language' => 'uk']),
        );

        self::assertSame(1, self::occurrences($journal, 'language'));
        self::assertSame('uk', self::preferences($journal)->select(['en', 'uk']));
        self::assertSame('uk', $response->headers->line('Content-Language'));
    }

    /**
     * the port, the store and the response follow the one source that decided
     */
    public function testTheLanguageParameterDecidesOverTheField(): void
    {
        $journal = self::journal();

        $response = self::server($journal, language: 'de', languageSources: self::uiLocaleThenField())->handle(
            self::route(OperationKind::UpdateResource, type: self::UUIDS),
            self::request(
                'PATCH',
                [self::ID_PARAMETER => self::WRITTEN_UUID],
                ['uiLocale' => 'de'],
                self::updateBody(self::UUIDS, self::WRITTEN_UUID),
                ['Accept-Language' => 'uk'],
            ),
        );

        self::assertSame(1, self::occurrences($journal, 'language'));
        self::assertSame('de', self::preferences($journal)->select(['uk', 'de']));
        self::assertSame('de', self::loaded($journal)->languages->select(['uk', 'de']));
        self::assertSame('Accept', $response->headers->line('Vary'));
    }

    public function testABadValueOfTheDecidingParameterIsA400BeforeAnyLanguageIsApplied(): void
    {
        $journal = self::journal();

        $response = self::server($journal, languageSources: self::uiLocaleThenField())->handle(
            self::route(OperationKind::FetchResource),
            self::request(
                'GET',
                [self::ID_PARAMETER => self::ID],
                ['uiLocale' => 'en_GB'],
                headers: ['Accept-Language' => 'uk'],
            ),
        );

        self::assertSame(400, $response->status);
        self::assertSame([ErrorCode::InvalidQueryParameter->value], self::codes($response));
        self::assertSame([['parameter' => 'uiLocale']], self::sources($response));
        self::assertSame('Accept', $response->headers->line('Vary'));
        self::assertNotContains('language', self::steps($journal));
    }

    /**
     * the language parameter needs no declaration on an endpoint:
     * its handler reads it as its languages, never among its query parameters
     */
    public function testEveryRouteTakesTheLanguageParameter(): void
    {
        $atomic = self::journal();
        $operations = self::server($atomic, languageSources: self::uiLocaleThenField())->handle(
            self::route(null),
            self::atomicRequest(self::removeDocument(), ['uiLocale' => 'de']),
        );

        self::assertSame(204, $operations->status);
        self::assertSame('de', self::preferences($atomic)->select(['uk', 'de']));
        self::assertSame('Accept', $operations->headers->line('Vary'));

        $journal = self::journal();
        $endpoint = self::photoEndpoint();
        $response = self::server($journal, endpoints: [$endpoint], languageSources: self::uiLocaleThenField())->handle(
            new EndpointRoute($endpoint),
            self::request(
                'GET',
                [self::ID_PARAMETER => self::ID],
                ['uiLocale' => 'de'],
                headers: ['Accept' => self::JSON_API],
            ),
        );
        $handled = self::payloads($journal, 'handle')[0] ?? null;

        self::assertSame(200, $response->status);
        self::assertInstanceOf(Request::class, $handled);
        self::assertSame('de', $handled->languages->select(['uk', 'de']));
        self::assertSame([], $handled->queryParameters->names());
    }

    public function testARefusalAndAConvertedErrorVaryAsASuccessWould(): void
    {
        $server = self::server(self::journal(), permits: static function (): bool {
            return false;
        }, languageSources: self::uiLocaleThenField());

        $refusal = $server->authorize(
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID], ['uiLocale' => 'de']),
        );
        $converted = $server->convertForeignError(
            500,
            '',
            '',
            self::request('GET', [], [], headers: ['Accept-Language' => 'uk']),
        );

        self::assertNotNull($refusal);
        self::assertSame('Accept', $refusal->headers->line('Vary'));
        self::assertSame('Accept, Accept-Language', $converted->headers->line('Vary'));
    }

    public function testTheContentLanguageIsResolvedForADeleteAsWell(): void
    {
        $journal = self::journal();

        $response = self::server($journal, language: 'uk')->handle(
            self::route(OperationKind::DeleteResource),
            self::request('DELETE', [self::ID_PARAMETER => self::ID]),
        );

        self::assertSame(204, $response->status);
        self::assertSame(1, self::occurrences($journal, 'language'));
    }

    public function testAServerWithoutAContentLanguagePortWritesNone(): void
    {
        $journal = self::journal();

        $response = self::server($journal, resolvesLanguage: false)->handle(
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID], headers: ['Accept-Language' => 'uk']),
        );

        self::assertFalse($response->headers->has('Content-Language'));
        self::assertNotContains('language', self::steps($journal));
    }

    public function testTheTargetIsLoadedOnceAndReachesTheProcessorInTheContext(): void
    {
        $journal = self::journal();
        $record = new Pet(self::ID, 'a name');

        self::server($journal, records: [self::ID => $record])->handle(
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        );

        [, $context] = self::processed($journal);

        self::assertSame(1, self::occurrences($journal, 'load'));
        self::assertSame($record, $context->record);
    }

    public function testACollectionReadingAndACreateLoadNoTarget(): void
    {
        $reading = self::journal();
        $create = self::journal();

        self::server($reading)->handle(self::route(OperationKind::FetchCollection), self::request('GET'));
        self::server($create)->handle(
            self::route(OperationKind::CreateResource),
            self::request('POST', body: self::createBody(self::TYPE)),
        );

        self::assertSame(['language', 'process'], self::steps($reading));
        self::assertSame(['language', 'process'], self::steps($create));
    }

    public function testTheLoadRequestOfAContentWriteNamesTheDecodedRouteIdAndTheLanguagesOfTheRequest(): void
    {
        $journal = self::journal();

        self::server($journal)->handle(
            self::route(OperationKind::UpdateResource, type: self::UUIDS),
            self::request(
                'PATCH',
                [self::ID_PARAMETER => self::WRITTEN_UUID],
                body: self::updateBody(self::UUIDS, self::WRITTEN_UUID),
                headers: ['Accept-Language' => 'uk'],
            ),
        );

        $load = self::loaded($journal);

        self::assertSame(self::UUIDS, $load->type);
        self::assertSame([self::UUID], $load->ids);
        self::assertSame('uk', $load->languages->select(['en', 'uk']));
    }

    public function testTheLoadRequestOfAContentWriteHintsEveryField(): void
    {
        $journal = self::journal();

        self::server($journal)->handle(
            self::route(OperationKind::UpdateResource),
            self::request('PATCH', [self::ID_PARAMETER => self::ID], body: self::updateBody(self::TYPE, self::ID)),
        );

        self::assertTrue(self::loaded($journal)->fields->hasEveryField());
    }

    /**
     * the gate takes the kind from the route, the executor derives it from the method
     * through `OperationParser::kindOf()`: the two derivations must agree
     */
    #[DataProvider('kindsOfARoute')]
    public function testTheKindTheGateCheckedIsTheKindTheExecutorRuns(
        OperationKind $kind,
        string $method,
        ?string $relationship,
        string $body,
    ): void {
        $journal = self::journal();
        $server = self::server($journal);
        $route = self::route($kind, $relationship);
        $request = self::request($method, [self::ID_PARAMETER => self::ID], body: $body);

        $server->authorize($route, $request);
        $server->handle($route, $request);

        [$operation] = self::processed($journal);

        self::assertSame($kind, self::attempted($journal, 'level1')->kind);
        self::assertSame($kind, $operation->kind());
    }

    /**
     * @return iterable<string, array{OperationKind, string, ?string, string}>
     */
    public static function kindsOfARoute(): iterable
    {
        yield 'a resource reading' => [OperationKind::FetchResource, 'GET', null, ''];
        yield 'a head of a resource' => [OperationKind::FetchResource, 'HEAD', null, ''];
        yield 'an update' => [OperationKind::UpdateResource, 'PATCH', null, self::updateBody(self::TYPE, self::ID)];
        yield 'a delete' => [OperationKind::DeleteResource, 'DELETE', null, ''];
        yield 'a related reading' => [OperationKind::FetchRelated, 'GET', self::RELATED, ''];
        yield 'a relationship reading' => [OperationKind::FetchRelationship, 'GET', self::RELATED, ''];
        yield 'a relationship replacement' => [
            OperationKind::ReplaceRelationship,
            'PATCH',
            self::RELATED,
            '{"data": []}',
        ];
        yield 'an addition to a relationship' => [
            OperationKind::AddToRelationship,
            'POST',
            self::RELATED,
            '{"data": [{"type": "pets", "id": "2"}]}',
        ];
        yield 'a removal from a relationship' => [
            OperationKind::RemoveFromRelationship,
            'DELETE',
            self::RELATED,
            '{"data": [{"type": "pets", "id": "2"}]}',
        ];
    }

    /**
     * a create loads no record, and a list of entity tags never holds without one
     */
    public function testThePreconditionsAreEvaluatedForEveryKindIncludingACreate(): void
    {
        $journal = self::journal();

        $response = self::server($journal)->handle(
            self::route(OperationKind::CreateResource),
            self::request('POST', body: self::createBody(self::TYPE), headers: ['If-Match' => '"1"']),
        );

        self::assertSame(412, $response->status);
        self::assertNotContains('process', self::steps($journal));
    }

    /**
     * the tag comes from a first reading of the same record
     */
    public function testANotModifiedResultIsRenderedAndNoProcessorRuns(): void
    {
        $server = self::server(self::journal(), versions: self::versions(false));
        $route = self::route(OperationKind::FetchResource);
        $tag = $server->handle($route, self::request('GET', [self::ID_PARAMETER => self::ID]))
            ->headers
            ->line('ETag');

        self::assertNotNull($tag);

        $journal = self::journal();
        $response = self::server($journal, versions: self::versions(false))->handle(
            $route,
            self::request('GET', [self::ID_PARAMETER => self::ID], headers: ['If-None-Match' => $tag]),
        );

        self::assertSame(304, $response->status);
        self::assertNull($response->document);
        self::assertNotContains('process', self::steps($journal));
    }

    public function testAPreconditionIsAttachedToAConditionalWriteAfterTheEvaluationHeld(): void
    {
        $server = self::server(self::journal(), versions: self::versions(false));
        $tag = $server->handle(
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        )->headers->line('ETag');

        self::assertNotNull($tag);

        $journal = self::journal();

        self::server($journal, versions: self::versions(false))->handle(
            self::route(OperationKind::UpdateResource),
            self::request(
                'PATCH',
                [self::ID_PARAMETER => self::ID],
                body: self::updateBody(self::TYPE, self::ID),
                headers: ['If-Match' => $tag],
            ),
        );

        [$operation] = self::processed($journal);

        self::assertInstanceOf(ConditionalWrite::class, $operation);
        self::assertNotNull($operation->precondition());
    }

    public function testACreateCarriesNoPrecondition(): void
    {
        $journal = self::journal();

        self::server($journal)->handle(
            self::route(OperationKind::CreateResource),
            self::request('POST', body: self::createBody(self::TYPE), headers: ['If-Match' => '*']),
        );

        [$operation] = self::processed($journal);

        self::assertSame(OperationKind::CreateResource, $operation->kind());
        self::assertNotInstanceOf(ConditionalWrite::class, $operation);
    }

    /**
     * the body is no JSON, so reading it would end in 400 instead of 412
     */
    public function testTheContentOfAWriteIsReadOnlyAfterThePreconditionsHeld(): void
    {
        $response = self::server(self::journal())->handle(
            self::route(OperationKind::UpdateResource),
            self::request(
                'PATCH',
                [self::ID_PARAMETER => self::ID],
                body: '{',
                headers: ['If-Match' => '"no such version"'],
            ),
        );

        self::assertSame(412, $response->status);
    }

    public function testTheResultIsRenderedWithTheTargetTheMediaTypeAndTheLanguage(): void
    {
        $response = self::server(self::journal(), language: 'uk')->handle(
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        );

        self::assertSame(200, $response->status);
        self::assertSame(self::JSON_API, $response->headers->line('Content-Type'));
        self::assertSame('uk', $response->headers->line('Content-Language'));
        self::assertSame('Accept, Accept-Language', $response->headers->line('Vary'));
        self::assertSame('/pets/1', self::selfLink($response));
    }

    public function testTheIncludeFlagIsWhetherTheQueryNamesTheFamily(): void
    {
        $supplied = self::journal();
        $absent = self::journal();

        self::server($supplied)->handle(
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID], ['include' => self::OWNER]),
        );
        self::server($absent)->handle(
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        );

        [, $with] = self::processed($supplied);
        [, $without] = self::processed($absent);

        self::assertTrue($with->includeSupplied);
        self::assertFalse($without->includeSupplied);
    }

    /**
     * the flag follows the requested profiles, not the applied ones:
     * `page[after]` applies the profile unasked,
     * and the applied list would page every nested linkage by cursor
     * for a client that asked for none
     */
    public function testTheCursorFlagIsWhetherTheAcceptFieldAsksForTheProfile(): void
    {
        $asked = self::journal();
        $paged = self::journal();

        self::server($asked)->handle(
            self::route(OperationKind::FetchCollection),
            self::request('GET', headers: ['Accept' => self::CURSOR_ACCEPT]),
        );
        self::server($paged)->handle(
            self::route(OperationKind::FetchCollection),
            self::request('GET', query: ['page' => ['after' => self::cursor()]]),
        );

        [, $requested] = self::processed($asked);
        [, $applied] = self::processed($paged);

        self::assertTrue($requested->cursorPagination);
        self::assertFalse($applied->cursorPagination);
    }

    public function testTheContextCarriesTheMediaTypeTheNegotiationReturned(): void
    {
        $journal = self::journal();

        self::server($journal)->handle(
            self::route(OperationKind::FetchCollection),
            self::request('GET', headers: ['Accept' => self::CURSOR_ACCEPT]),
        );

        [, $context] = self::processed($journal);

        self::assertSame([CursorProfile::URI], $context->contentType->profile());
    }

    public function testAReadingThatPagesByCursorNamesTheCursorProfileInItsContentType(): void
    {
        $response = self::server(self::journal())->handle(
            self::route(OperationKind::FetchCollection),
            self::request('GET', query: ['page' => ['after' => self::cursor()]]),
        );

        self::assertSame(200, $response->status);
        self::assertStringContainsString(
            CursorProfile::URI,
            (string) $response->headers->line('Content-Type'),
        );
    }

    public function testAPageNumberReadingNamesNoProfileWhereTheAcceptFieldAsksForNone(): void
    {
        $response = self::server(self::journal())->handle(
            self::route(OperationKind::FetchCollection),
            self::request('GET', query: ['page' => ['number' => '1']]),
        );

        self::assertSame(200, $response->status);
        self::assertSame(self::JSON_API, $response->headers->line('Content-Type'));
    }

    public function testAPageNumberReadingNamesNoProfileThoughTheAcceptFieldAsksForIt(): void
    {
        $response = self::server(self::journal())->handle(
            self::route(OperationKind::FetchCollection),
            self::request('GET', query: ['page' => ['number' => '1']], headers: ['Accept' => self::CURSOR_ACCEPT]),
        );

        self::assertSame(200, $response->status);
        self::assertSame(self::JSON_API, $response->headers->line('Content-Type'));
    }

    public function testAPageNumberReadingCarriesNoCursorFlagThoughTheAcceptFieldAsksForTheProfile(): void
    {
        $journal = self::journal();

        self::server($journal)->handle(
            self::route(OperationKind::FetchCollection),
            self::request('GET', query: ['page' => ['number' => '1']], headers: ['Accept' => self::CURSOR_ACCEPT]),
        );

        [, $context] = self::processed($journal);

        self::assertFalse($context->cursorPagination);
    }

    public function testTheEntityTagOfAResponseMixesTheMediaTypeTheNegotiationReturned(): void
    {
        $server = self::server(self::journal(), versions: self::versions(false));
        $route = self::route(OperationKind::FetchResource);

        $bare = $server->handle($route, self::request('GET', [self::ID_PARAMETER => self::ID]))
            ->headers
            ->line('ETag');
        $profiled = $server->handle(
            $route,
            self::request('GET', [self::ID_PARAMETER => self::ID], headers: ['Accept' => self::CURSOR_ACCEPT]),
        )->headers->line('ETag');

        self::assertNotNull($bare);
        self::assertNotNull($profiled);
        self::assertNotSame($bare, $profiled);
    }

    /**
     * the query, the content type and the missing target would each fail the request too
     */
    public function testTheLibraryRespondsToALevelOneDenialBeforeEveryFailureOfTheRequest(): void
    {
        $journal = self::journal();
        $response = self::refusedAuthorization(
            self::server($journal, permits: static function (): bool {
                return false;
            }, records: []),
            self::route(OperationKind::UpdateResource),
            self::request(
                'PATCH',
                [self::ID_PARAMETER => self::ID],
                ['nonsense' => '1'],
                '{',
                ['Content-Type' => 'text/plain'],
            ),
        );

        self::assertSame(403, $response->status);
        self::assertSame(['level1'], self::steps($journal));
    }

    public function testTheLibraryRespondsToAnAcceptFieldThatAdmitsNoRepresentationBeforeTheContentType(): void
    {
        $response = self::server(self::journal())->handle(
            self::route(OperationKind::CreateResource),
            self::request(
                'POST',
                body: self::createBody(self::TYPE),
                headers: ['Accept' => 'application/xml', 'Content-Type' => 'text/plain'],
            ),
        );

        self::assertSame(406, $response->status);
    }

    public function testTheLibraryRespondsToContentInAnotherMediaTypeBeforeThePreconditions(): void
    {
        $response = self::server(self::journal())->handle(
            self::route(OperationKind::UpdateResource),
            self::request(
                'PATCH',
                [self::ID_PARAMETER => self::ID],
                body: self::updateBody(self::TYPE, self::ID),
                headers: ['Content-Type' => 'application/json', 'If-Match' => '"no such version"'],
            ),
        );

        self::assertSame(415, $response->status);
    }

    /**
     * the route id is decoded before the query is read,
     * so the 404 stands alone and never merges with the 400 of the query
     */
    public function testARouteIdTheCodecRefusesIsNotFoundBeforeTheQueryIsRead(): void
    {
        $journal = self::journal();

        $response = self::server($journal)->handle(
            self::route(OperationKind::FetchResource, type: self::UUIDS),
            self::request('GET', [self::ID_PARAMETER => 'no such uuid'], ['nonsense' => '1']),
        );

        self::assertSame(404, $response->status);
        self::assertSame([ErrorCode::NotFound->value], self::codes($response));
        self::assertNotContains('load', self::steps($journal));
    }

    public function testTheLibraryRespondsToAQueryErrorBeforeTheTargetIsLoaded(): void
    {
        $journal = self::journal();

        $response = self::server($journal)->handle(
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID], ['nonsense' => '1']),
        );

        self::assertSame(400, $response->status);
        self::assertNotContains('load', self::steps($journal));
    }

    public function testATargetTheLoaderDoesNotReturnIsNotFoundBeforeTheRecordLevelDenial(): void
    {
        $journal = self::journal();

        $response = self::server($journal, records: [], permits: static function (): bool {
            return false;
        })->handle(
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        );

        self::assertSame(404, $response->status);
        self::assertSame([ErrorCode::NotFound->value], self::codes($response));
        self::assertNotContains('level2', self::steps($journal));
    }

    public function testTheLibraryRespondsToALevelTwoDenialBeforeThePreconditions(): void
    {
        $response = self::server(
            self::journal(),
            permits: static function (OperationAttempt $attempt): bool {
                return $attempt->record === null;
            },
        )->handle(
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID], headers: ['If-Match' => 'nonsense']),
        );

        self::assertSame(403, $response->status);
    }

    /**
     * the competing field is If-None-Match: a failing If-Match cannot meet the 428,
     * which is raised only where If-Match is absent
     */
    public function testTheLibraryRespondsToAMissingIfMatchBeforeAFailingIfNoneMatch(): void
    {
        $response = self::server(self::journal(), versions: self::versions(true))->handle(
            self::route(OperationKind::UpdateResource),
            self::request(
                'PATCH',
                [self::ID_PARAMETER => self::ID],
                body: self::updateBody(self::TYPE, self::ID),
                headers: ['If-None-Match' => '*'],
            ),
        );

        self::assertSame(428, $response->status);
        self::assertSame([ErrorCode::PreconditionRequired->value], self::codes($response));
    }

    /**
     * RFC 9110, section 13.2.2 evaluates If-Match before If-None-Match
     */
    public function testTheLibraryRespondsToAFailingIfMatchBeforeAFailingIfNoneMatch(): void
    {
        $response = self::server(self::journal())->handle(
            self::route(OperationKind::FetchResource),
            self::request(
                'GET',
                [self::ID_PARAMETER => self::ID],
                headers: ['If-Match' => '"no such version"', 'If-None-Match' => '*'],
            ),
        );

        self::assertSame(412, $response->status);
        self::assertSame(['If-Match'], self::headersOf($response));
    }

    public function testTheLibraryRespondsToAFailingPreconditionBeforeTheContentErrors(): void
    {
        $response = self::server(self::journal())->handle(
            self::route(OperationKind::UpdateResource),
            self::request(
                'PATCH',
                [self::ID_PARAMETER => self::ID],
                body: '{"data": {"type": "pets", "id": "1", "attributes": {"nowhere": "x"}}}',
                headers: ['If-Match' => '"no such version"'],
            ),
        );

        self::assertSame(412, $response->status);
    }

    public function testTwoProblemsOfOneStepAreOneResponse(): void
    {
        $response = self::server(self::journal())->handle(
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID], ['nonsense' => '1', 'nowhere' => '2']),
        );

        self::assertSame(400, $response->status);
        self::assertCount(2, self::codes($response));
    }

    public function testContentOnAGeneratedRouteOfAnotherMediaTypeIs415ListingJsonApi(): void
    {
        $response = self::server(self::journal())->handle(
            self::route(OperationKind::CreateResource),
            self::request('POST', headers: ['Content-Type' => 'text/plain', 'Content-Length' => '3']),
        );

        self::assertSame(415, $response->status);
        self::assertSame(self::JSON_API, $response->headers->line('Accept'));
    }
}
