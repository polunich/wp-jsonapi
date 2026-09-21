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

namespace Polunich\WpJsonApi\Tests\Unit\Execution\Result;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Execution\Result\Page;
use Polunich\WpJsonApi\Execution\Result\RecordLinkage;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(RecordLinkage::class)]
final class RecordLinkageTest extends TestCase
{
    public function testTheClassIsFinalAndReadonly(): void
    {
        $class = new ReflectionClass(RecordLinkage::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
    }

    public function testANonEmptyToOneLinkageNamesOneRecord(): void
    {
        $owner = new ResourceRecord('customers', '9', new Pet('9', 'Ada Lovelace'));

        $linkage = RecordLinkage::toOne($owner);

        self::assertFalse($linkage->isToMany());
        self::assertSame($owner, $linkage->record());
    }

    public function testAnEmptyToOneLinkageNamesNoRecord(): void
    {
        $linkage = RecordLinkage::toOne(null);

        self::assertFalse($linkage->isToMany());
        self::assertNull($linkage->record());
    }

    public function testAToManyLinkageNamesAPageOfRecords(): void
    {
        $page = self::page([new ResourceRecord('orders', '5', null)]);

        $linkage = RecordLinkage::toMany($page);

        self::assertTrue($linkage->isToMany());
        self::assertSame($page, $linkage->page());
    }

    public function testAToManyLinkageNamesTheRecordsOfItsPage(): void
    {
        $tags = [new ResourceRecord('tags', '2', null), new ResourceRecord('tags', '3', null)];

        $linkage = RecordLinkage::toMany(self::page($tags));

        self::assertSame($tags, $linkage->records());
        self::assertTrue($linkage->isPaged());
    }

    public function testAWholeToManyLinkageNamesItsListAndNoPage(): void
    {
        $tags = [new ResourceRecord('tags', '2', null)];

        $linkage = RecordLinkage::toManyWhole($tags);

        self::assertTrue($linkage->isToMany());
        self::assertFalse($linkage->isPaged());
        self::assertSame($tags, $linkage->records());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('this to-many linkage is a whole list without pages; read it with records()');

        $linkage->page();
    }

    public function testRefusesToReadAToOneLinkageAsAList(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('a to-one linkage names one record or none; read it with record()');

        RecordLinkage::toOne(null)->records();
    }

    public function testAnEmptyToManyLinkageNamesAnEmptyPage(): void
    {
        $linkage = RecordLinkage::toMany(self::page([]));

        self::assertTrue($linkage->isToMany());
        self::assertSame([], $linkage->page()->records);
    }

    public function testTheEmptyToOneAndTheEmptyToManyLinkageAreDifferentValues(): void
    {
        $empty = RecordLinkage::toMany(self::page([]));

        self::assertNotEquals(RecordLinkage::toOne(null), $empty);
        self::assertFalse(RecordLinkage::toOne(null)->isToMany());
        self::assertTrue($empty->isToMany());
    }

    public function testAToManyLinkageOfOneRecordIsNoToOneLinkage(): void
    {
        $tag = new ResourceRecord('tags', '2', null);

        self::assertNotEquals(RecordLinkage::toOne($tag), RecordLinkage::toMany(self::page([$tag])));
    }

    #[DataProvider('toManyLinkages')]
    public function testRefusesToReadAToManyLinkageAsOneRecord(RecordLinkage $linkage): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('a to-many linkage names a list of records; read it with records()');

        $linkage->record();
    }

    /**
     * @return iterable<string, array{RecordLinkage}>
     */
    public static function toManyLinkages(): iterable
    {
        yield 'empty' => [RecordLinkage::toMany(self::page([]))];
        yield 'one record' => [RecordLinkage::toMany(self::page([new ResourceRecord('tags', '2', null)]))];
        yield 'a whole list' => [RecordLinkage::toManyWhole([new ResourceRecord('tags', '2', null)])];
    }

    #[DataProvider('toOneLinkages')]
    public function testRefusesToReadAToOneLinkageAsAPage(RecordLinkage $linkage): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('a to-one linkage names one record or none; read it with record()');

        $linkage->page();
    }

    /**
     * @return iterable<string, array{RecordLinkage}>
     */
    public static function toOneLinkages(): iterable
    {
        yield 'empty' => [RecordLinkage::toOne(null)];
        yield 'one record' => [RecordLinkage::toOne(new ResourceRecord('customers', '9', null))];
    }

    /**
     * @param list<ResourceRecord> $records
     */
    private static function page(array $records): Page
    {
        return new Page($records, Strategy::PageNumber, 10, 1, null, false, false, false, null, null, null, null);
    }
}
