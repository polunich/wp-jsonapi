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

namespace Polunich\WpJsonApi\Tests\Unit\Execution\Atomic;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Execution\Atomic\LocalIds;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(LocalIds::class)]
final class LocalIdsTest extends TestCase
{
    public function testTheIdOfARecordedPairIsReturned(): void
    {
        $ids = new LocalIds();

        $ids->assign('pets', 'temp-1', '42');

        self::assertSame('42', $ids->idOf('pets', 'temp-1'));
    }

    public function testAPairIsUnknownUntilACreateRecordsIt(): void
    {
        $ids = new LocalIds();

        self::assertFalse($ids->has('pets', 'temp-1'));

        $ids->assign('pets', 'temp-1', '42');

        self::assertTrue($ids->has('pets', 'temp-1'));
    }

    public function testOneLidUnderTwoTypesNamesTwoResources(): void
    {
        $ids = new LocalIds();

        $ids->assign('pets', 'temp', '42');
        $ids->assign('customers', 'temp', '7');

        self::assertSame('42', $ids->idOf('pets', 'temp'));
        self::assertSame('7', $ids->idOf('customers', 'temp'));
    }

    public function testALidRecordedForOneTypeIsUnknownUnderAnother(): void
    {
        $ids = new LocalIds();

        $ids->assign('pets', 'temp', '42');

        self::assertFalse($ids->has('customers', 'temp'));

        $this->expectException(LogicException::class);

        $ids->idOf('customers', 'temp');
    }

    /**
     * PHP turns the array keys `'0'` and `'1'` into ints but keeps `'01'` a string
     */
    public function testALidOfDigitsIsALidLikeAnyOther(): void
    {
        $ids = new LocalIds();

        $ids->assign('0', '1', 'first');
        $ids->assign('0', '01', 'second');

        self::assertSame('first', $ids->idOf('0', '1'));
        self::assertSame('second', $ids->idOf('0', '01'));
        self::assertTrue($ids->has('0', '1'));
        self::assertFalse($ids->has('0', '2'));
    }

    public function testAPairIsRecordedOnce(): void
    {
        $ids = new LocalIds();
        $ids->assign('pets', 'temp', '42');

        try {
            $ids->assign('pets', 'temp', '43');
            self::fail('the map recorded one pair twice');
        } catch (LogicException $refusal) {
            self::assertStringContainsString('temp', $refusal->getMessage());
            self::assertStringContainsString('42', $refusal->getMessage());
        }

        self::assertSame('42', $ids->idOf('pets', 'temp'));
    }

    /**
     * the same id again is refused too: a repeated pair is a library mistake, not a no-op
     */
    public function testASecondAssignmentOfOnePairIsNoClientError(): void
    {
        $ids = new LocalIds();
        $ids->assign('pets', 'temp', '42');

        $this->expectException(LogicException::class);

        $ids->assign('pets', 'temp', '42');
    }

    public function testALidNoOperationRecordedIsRefused(): void
    {
        try {
            (new LocalIds())->idOf('pets', 'temp');
            self::fail('the map returned an id for a lid no operation recorded');
        } catch (LogicException $refusal) {
            self::assertStringContainsString('pets', $refusal->getMessage());
            self::assertStringContainsString('temp', $refusal->getMessage());
        }
    }

    #[DataProvider('pairsThatIdentifyNoResource')]
    public function testAnEmptyTypeLidOrIdIsRefused(string $type, string $lid, string $id): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new LocalIds())->assign($type, $lid, $id);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function pairsThatIdentifyNoResource(): iterable
    {
        yield 'no type' => ['', 'temp', '42'];
        yield 'no lid' => ['pets', '', '42'];
        yield 'no id' => ['pets', 'temp', ''];
    }

    /**
     * a later operation that refers to the pair must be refused, not built with an empty id
     */
    public function testARefusedAssignmentRecordsNothing(): void
    {
        $ids = new LocalIds();

        try {
            $ids->assign('pets', 'temp', '');
        } catch (InvalidArgumentException) {
            // asserted by testAnEmptyTypeLidOrIdIsRefused
        }

        self::assertFalse($ids->has('pets', 'temp'));
    }

    public function testTheResolutionReturnsTheIdOfAPair(): void
    {
        $ids = new LocalIds();
        $ids->assign('pets', 'temp', '42');

        $idOfLid = $ids->idLookup();

        self::assertSame('42', $idOfLid('pets', 'temp'));
    }

    public function testTheResolutionReadsTheIdsRecordedAfterItWasTaken(): void
    {
        $ids = new LocalIds();
        $idOfLid = $ids->idLookup();

        $ids->assign('pets', 'temp', '42');

        self::assertSame('42', $idOfLid('pets', 'temp'));
    }

    public function testTheResolutionRefusesAPairNoOperationRecorded(): void
    {
        $idOfLid = (new LocalIds())->idLookup();

        $this->expectException(LogicException::class);

        $idOfLid('pets', 'temp');
    }

    public function testTheMapIsInternalFinalAndNotReadonly(): void
    {
        $map = new ReflectionClass(LocalIds::class);

        self::assertStringContainsString('@internal', (string) $map->getDocComment());
        self::assertTrue($map->isFinal());
        self::assertFalse($map->isReadOnly());
    }
}
