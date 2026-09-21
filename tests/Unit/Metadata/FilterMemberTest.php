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

namespace Polunich\WpJsonApi\Tests\Unit\Metadata;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Codec\UuidCodec;
use Polunich\WpJsonApi\Metadata\AttributeMetadata;
use Polunich\WpJsonApi\Metadata\FilterMember;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(FilterMember::class)]
final class FilterMemberTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(FilterMember::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testAConditionOnAnAttributeReadsTheCodecAndTheOperatorsOfTheAttribute(): void
    {
        $name = Samples::attribute('name', new StringCodec(1, 9), operators: ['@eq', '@containsi']);
        $member = new FilterMember('name', Samples::resource('customers'), $name, null);

        self::assertSame($name->codec, $member->codec());
        self::assertSame(['@eq', '@containsi'], $member->operators());
    }

    public function testAConditionOnTheIdReadsTheCodecAndTheOperatorsOfTheIdOfItsType(): void
    {
        $customers = Samples::resource('customers', id: Samples::id(['@eq', '@in'], new UuidCodec()));
        $member = new FilterMember('id', $customers, null, null);

        self::assertSame($customers->id->codec, $member->codec());
        self::assertSame(['@eq', '@in'], $member->operators());
    }

    public function testARelationshipTakesNoCondition(): void
    {
        $member = new FilterMember(
            'owner',
            Samples::resource('pets'),
            null,
            Samples::toOne('owner', 'customers'),
        );

        self::assertSame([], $member->operators());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'filter member "owner" is a relationship, which takes a filter object and no condition',
        );

        $member->codec();
    }

    #[DataProvider('impossibleResolutions')]
    public function testRefusesAResolutionThatIsNotTheMember(
        string $name,
        ?AttributeMetadata $attribute,
        ?RelationshipMetadata $relationship,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new FilterMember($name, Samples::resource('customers'), $attribute, $relationship);
    }

    /**
     * @return iterable<string, array{string, ?AttributeMetadata, ?RelationshipMetadata, string}>
     */
    public static function impossibleResolutions(): iterable
    {
        $owner = Samples::toOne('owner', 'customers');
        $name = Samples::attribute('name', operators: ['@eq']);

        yield 'neither an attribute nor a relationship where the name is not id' => [
            'name',
            null,
            null,
            'filter member "name" is resolved to neither an attribute nor a relationship, and a filter member is an'
            . ' attribute, a relationship or the id',
        ];
        yield 'both an attribute and a relationship' => [
            'name',
            $name,
            $owner,
            'filter member "name" is resolved to both an attribute and a relationship',
        ];
        yield 'an attribute its name does not name' => [
            'owner',
            $name,
            null,
            'filter member "owner" is resolved to the field "name", which it does not name',
        ];
        yield 'an attribute where the name is id' => [
            'id',
            $name,
            null,
            'filter member "id" is resolved to the field "name", which it does not name',
        ];
        yield 'a dotted path' => [
            'owner.id',
            null,
            null,
            'filter member "owner.id" is resolved to neither an attribute nor a relationship',
        ];
    }
}
