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
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Parsing\LocalIdentifier;
use Polunich\WpJsonApi\Parsing\LocalLinkage;
use Polunich\WpJsonApi\Parsing\ResourceBody;

/**
 * @internal
 */
#[CoversClass(ResourceBody::class)]
final class ResourceBodyTest extends TestCase
{
    public function testCarriesTheTypeTheIdentityAndTheFieldsOfTheResourceObject(): void
    {
        $attributes = new Values(['title' => 'Ember Hamster']);
        $relationships = new Values(['photographer' => LocalLinkage::toOne(LocalIdentifier::ofId('customers', '9'))]);

        $body = new ResourceBody('photos', '13', null, $attributes, $relationships);

        self::assertSame('photos', $body->type);
        self::assertSame('13', $body->id);
        self::assertNull($body->lid);
        self::assertSame($attributes, $body->attributes);
        self::assertSame($relationships, $body->relationships);
    }

    public function testCarriesTheLidOfAResourceObjectThatHasNoId(): void
    {
        $body = new ResourceBody('photos', null, 'fresh', new Values([]), new Values([]));

        self::assertNull($body->id);
        self::assertSame('fresh', $body->lid);
    }

    public function testReplacesTheLidOfEveryRelationshipByItsIdInTheLinkageAnOperationCarries(): void
    {
        $body = new ResourceBody('photos', null, null, new Values([]), new Values([
            'photographer' => LocalLinkage::toOne(LocalIdentifier::ofLid('customers', 'owner')),
            'tags' => LocalLinkage::toMany([LocalIdentifier::ofId('tags', '2')]),
        ]));

        $relationships = $body->relationshipsWithIds(static function (string $type, string $lid): string {
            return '9';
        });

        self::assertSame(['photographer', 'tags'], $relationships->names());
        self::assertEquals(Linkage::toOne(new Identifier('customers', '9')), $relationships->get('photographer'));
        self::assertEquals(Linkage::toMany([new Identifier('tags', '2')]), $relationships->get('tags'));
    }

    public function testTheRelationshipsOfASingleRequestNeedNoIdOfALid(): void
    {
        $body = new ResourceBody('photos', null, null, new Values([]), new Values([
            'tags' => LocalLinkage::toMany([LocalIdentifier::ofId('tags', '2')]),
        ]));

        self::assertEquals(
            Linkage::toMany([new Identifier('tags', '2')]),
            $body->relationshipsWithIds()->get('tags'),
        );
    }

    #[DataProvider('impossibleResourceObjects')]
    public function testRefusesAResourceObjectNoRequestCanCarry(
        string $type,
        ?string $id,
        ?string $lid,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ResourceBody($type, $id, $lid, new Values([]), new Values([]));
    }

    /**
     * @return array<string, array{string, ?string, ?string, string}>
     */
    public static function impossibleResourceObjects(): array
    {
        return [
            'no type' => ['', '13', null, 'a type is never empty'],
            'empty id' => ['photos', '', null, 'carries an empty id'],
            'empty lid' => ['photos', null, '', 'carries an empty lid'],
            'an id and a lid' => ['photos', '13', 'fresh', 'a lid stands where an id is omitted'],
        ];
    }
}
