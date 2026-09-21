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

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Execution\ExecutionContext;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\ServerRequest;
use ReflectionClass;
use stdClass;

/**
 * @internal
 */
#[CoversClass(ExecutionContext::class)]
final class ExecutionContextTest extends TestCase
{
    public function testTheContextCarriesEveryValueItWasBuiltWith(): void
    {
        $request = self::request();
        $contentType = self::contentType();
        $record = new stdClass();

        $schemes = [SecurityScheme::restNonce()];

        $context = new ExecutionContext($request, $contentType, 'de', true, true, $schemes, $record, 2);

        self::assertSame($request, $context->request);
        self::assertSame($contentType, $context->contentType);
        self::assertSame('de', $context->contentLanguage);
        self::assertTrue($context->includeSupplied);
        self::assertTrue($context->cursorPagination);
        self::assertSame($schemes, $context->securitySchemes);
        self::assertSame($record, $context->record);
        self::assertSame(2, $context->operationPosition);
    }

    public function testARecordAndAPositionAreLeftOutOfASingleRequest(): void
    {
        $context = new ExecutionContext(self::request(), self::contentType(), null, false, false, []);

        self::assertNull($context->record);
        self::assertNull($context->operationPosition);
        self::assertNull($context->contentLanguage);
        self::assertFalse($context->includeSupplied);
        self::assertFalse($context->cursorPagination);
    }

    #[DataProvider('positionsThatNameNoOperation')]
    public function testAPositionBelowZeroNamesNoOperationOfAnAtomicRequest(int $position): void
    {
        try {
            new ExecutionContext(self::request(), self::contentType(), null, false, false, [], null, $position);
            self::fail('the context took a position that names no operation');
        } catch (InvalidArgumentException $refusal) {
            self::assertStringContainsString((string) $position, $refusal->getMessage());
        }
    }

    #[DataProvider('positionsThatNameNoOperation')]
    public function testAtPositionRefusesAPositionBelowZero(int $position): void
    {
        try {
            self::context()->atPosition($position);
            self::fail('atPosition() took a position that names no operation');
        } catch (InvalidArgumentException $refusal) {
            self::assertStringContainsString((string) $position, $refusal->getMessage());
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function positionsThatNameNoOperation(): iterable
    {
        yield 'the position before the first' => [-1];
        yield 'a position far below zero' => [-17];
        yield 'the smallest integer' => [\PHP_INT_MIN];
    }

    public function testTheFirstOperationOfAnAtomicRequestStandsAtPositionZero(): void
    {
        $context = self::context()->atPosition(0);

        self::assertSame(0, $context->operationPosition);
        self::assertTrue($context->isAtomic());
    }

    public function testASingleRequestIsNoAtomicRequestEvenOnceItsRecordIsLoaded(): void
    {
        $context = self::context();

        self::assertFalse($context->isAtomic());
        self::assertFalse($context->withRecord(new stdClass())->isAtomic());
    }

    public function testWithRecordReturnsACopyThatCarriesTheRecordAndLeavesThisOneUnchanged(): void
    {
        $record = new stdClass();
        $context = self::context();

        $copy = $context->withRecord($record);

        self::assertNotSame($context, $copy);
        self::assertSame($record, $copy->record);
        self::assertNull($context->record);
    }

    public function testWithRecordKeepsEverythingButTheRecord(): void
    {
        $context = self::context()->atPosition(3);

        $copy = $context->withRecord(new stdClass());

        self::assertSame($context->request, $copy->request);
        self::assertSame($context->contentType, $copy->contentType);
        self::assertSame($context->contentLanguage, $copy->contentLanguage);
        self::assertSame($context->includeSupplied, $copy->includeSupplied);
        self::assertSame($context->securitySchemes, $copy->securitySchemes);
        self::assertSame($context->cursorPagination, $copy->cursorPagination);
        self::assertSame(3, $copy->operationPosition);
    }

    public function testWithRecordReplacesARecordTheContextAlreadyCarries(): void
    {
        $reloaded = new stdClass();

        $copy = self::context(new stdClass())->withRecord($reloaded);

        self::assertSame($reloaded, $copy->record);
    }

    public function testAtPositionReturnsACopyAtThatPositionThatCarriesNoRecord(): void
    {
        $context = self::context(new stdClass());

        $copy = $context->atPosition(4);

        self::assertNotSame($context, $copy);
        self::assertSame(4, $copy->operationPosition);
        self::assertNull($copy->record);
        self::assertNull($context->operationPosition);
        self::assertNotNull($context->record);
    }

    public function testAtPositionKeepsTheRequestAndTheNegotiatedResponse(): void
    {
        $context = self::context();

        $copy = $context->atPosition(1);

        self::assertSame($context->request, $copy->request);
        self::assertSame($context->contentType, $copy->contentType);
        self::assertSame($context->contentLanguage, $copy->contentLanguage);
        self::assertSame($context->includeSupplied, $copy->includeSupplied);
        self::assertSame($context->securitySchemes, $copy->securitySchemes);
        self::assertSame($context->cursorPagination, $copy->cursorPagination);
    }

    public function testRecordReturnsTheLoadedTargetOrParent(): void
    {
        $record = new stdClass();

        self::assertSame($record, self::context()->withRecord($record)->record());
    }

    public function testRecordRefusesAContextWithoutALoadedRecord(): void
    {
        $this->expectException(LogicException::class);

        self::context()->record();
    }

    public function testRecordRefusesTheScopeOfAnOperationBeforeItsOwnLoad(): void
    {
        $this->expectException(LogicException::class);

        self::context(new stdClass())->atPosition(0)->record();
    }

    public function testTheContextIsInternalAndImmutable(): void
    {
        $context = new ReflectionClass(ExecutionContext::class);

        self::assertStringContainsString('@internal', (string) $context->getDocComment());
        self::assertTrue($context->isFinal());
        self::assertTrue($context->isReadOnly());
    }

    /**
     * the language, both flags and the schemes differ from their defaults,
     * so a copy that drops one of them fails
     */
    private static function context(?object $record = null): ExecutionContext
    {
        return new ExecutionContext(
            self::request(),
            self::contentType(),
            'de',
            true,
            true,
            [SecurityScheme::applicationPasswords()],
            $record,
        );
    }

    private static function request(): ServerRequest
    {
        return new ServerRequest(
            'POST',
            new Headers(['If-Match' => '"7"']),
            ['include' => 'owner'],
            ['id' => '1'],
            '{}',
        );
    }

    private static function contentType(): MediaType
    {
        return MediaType::jsonApi([MediaType::ATOMIC_EXTENSION]);
    }
}
