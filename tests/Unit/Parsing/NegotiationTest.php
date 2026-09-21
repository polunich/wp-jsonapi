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
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Parsing\CursorProfile;
use Polunich\WpJsonApi\Parsing\Negotiation;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(Negotiation::class)]
final class NegotiationTest extends TestCase
{
    /**
     * @return iterable<string, array{MediaType, string}>
     */
    public static function mediaTypesThatAreNoPayloadType(): iterable
    {
        yield 'a parameter the specification does not define' => [
            new MediaType('application', 'vnd.api+json', ['charset' => 'utf-8']),
            'carries the parameter "charset"',
        ];
        yield 'a weight' => [
            new MediaType('application', 'vnd.api+json', [], 0.5),
            'carries a weight',
        ];
    }

    public function testTheClassIsFinalAndInternal(): void
    {
        $class = new ReflectionClass(Negotiation::class);

        self::assertTrue($class->isFinal());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testTheUrisAreTheOnesTheLibraryApplies(): void
    {
        self::assertSame('https://jsonapi.org/ext/atomic', MediaType::ATOMIC_EXTENSION);
        self::assertSame(
            'http://jsonapi.org/profiles/ethanresnick/cursor-pagination/',
            CursorProfile::URI,
        );
    }

    public function testTellsWhatTheResponseApplies(): void
    {
        $negotiation = new Negotiation(MediaType::jsonApi(
            [MediaType::ATOMIC_EXTENSION],
            [CursorProfile::URI],
        ));

        self::assertSame([MediaType::ATOMIC_EXTENSION], $negotiation->contentType->ext());
        self::assertSame([CursorProfile::URI], $negotiation->contentType->profile());
        self::assertSame(
            'application/vnd.api+json;ext="https://jsonapi.org/ext/atomic"'
            . ';profile="http://jsonapi.org/profiles/ethanresnick/cursor-pagination/"',
            $negotiation->contentType->toHeaderValue(),
        );
    }

    public function testAResponseThatAppliesNothingNamesNothing(): void
    {
        $negotiation = new Negotiation(MediaType::jsonApi());

        self::assertSame([], $negotiation->contentType->ext());
        self::assertSame([], $negotiation->contentType->profile());
        self::assertSame('application/vnd.api+json', $negotiation->contentType->toHeaderValue());
    }

    /**
     * `$added` stands for a reading paged by cursor, which applies the profile unasked
     */
    public function testRequestsProfileTellsTheAcceptFieldAndTheMediaTypeTheResponse(): void
    {
        $asked = new Negotiation(
            MediaType::jsonApi([], [CursorProfile::URI]),
            [CursorProfile::URI],
        );
        $added = new Negotiation(MediaType::jsonApi([], [CursorProfile::URI]));

        self::assertTrue($asked->requestsProfile(CursorProfile::URI));
        self::assertSame([CursorProfile::URI], $asked->contentType->profile());
        self::assertFalse($added->requestsProfile(CursorProfile::URI));
        self::assertSame([CursorProfile::URI], $added->contentType->profile());
    }

    public function testTheCursorProfileAskedForMakesTheCursorTheDefaultPageStrategy(): void
    {
        $asked = new Negotiation(MediaType::jsonApi([], [CursorProfile::URI]), [CursorProfile::URI]);
        $added = new Negotiation(MediaType::jsonApi([], [CursorProfile::URI]));

        self::assertSame(Strategy::Cursor, $asked->defaultPageStrategy());
        self::assertSame(Strategy::PageNumber, $added->defaultPageStrategy());
        self::assertSame(Strategy::PageNumber, (new Negotiation(MediaType::jsonApi()))->defaultPageStrategy());
    }

    public function testANegotiationWithoutRequestedProfilesRequestsNone(): void
    {
        $negotiation = new Negotiation(MediaType::jsonApi());

        self::assertFalse($negotiation->requestsProfile(CursorProfile::URI));
    }

    public function testARepresentationInAnotherMediaTypeKeepsItsParametersAndItsDocumentsAreJsonApi(): void
    {
        $csv = new Negotiation(new MediaType('text', 'csv', ['header' => 'present']));
        $jsonApi = new Negotiation(MediaType::jsonApi([MediaType::ATOMIC_EXTENSION]));

        self::assertSame('text/csv;header=present', $csv->contentType->toHeaderValue());
        self::assertSame('text/csv;header=present', $csv->representation()->toHeaderValue());
        self::assertSame(MediaType::JSON_API, $csv->documentContentType()->toHeaderValue());
        self::assertSame(MediaType::JSON_API, $jsonApi->representation()->toHeaderValue());
        self::assertSame($jsonApi->contentType, $jsonApi->documentContentType());
    }

    public function testAWeightedRepresentationInAnotherMediaTypeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('carries a weight');

        new Negotiation(new MediaType('text', 'csv', [], 0.5));
    }

    /**
     * a requested profile missing from the `Content-Type` would let a response paginate
     * by a profile it does not name
     */
    public function testARequestedProfileTheContentTypeDoesNotNameIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is requested and not applied');

        new Negotiation(MediaType::jsonApi(), [CursorProfile::URI]);
    }

    #[DataProvider('mediaTypesThatAreNoPayloadType')]
    public function testRefusesAMediaTypeNoPayloadIsWrittenIn(MediaType $mediaType, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($message, '/') . '/');

        new Negotiation($mediaType);
    }
}
