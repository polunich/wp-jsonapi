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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Store\CollectionReader;
use Polunich\WpJsonApi\Store\RelatedLoader;
use Polunich\WpJsonApi\Store\RelationshipAdder;
use Polunich\WpJsonApi\Store\RelationshipRemover;
use Polunich\WpJsonApi\Store\RelationshipReplacer;
use Polunich\WpJsonApi\Store\ResourceCreator;
use Polunich\WpJsonApi\Store\ResourceDeleter;
use Polunich\WpJsonApi\Store\ResourceLoader;
use Polunich\WpJsonApi\Store\ResourceUpdater;
use Polunich\WpJsonApi\Store\TransactionBoundary;
use ReflectionClass;
use ReflectionEnum;

/**
 * @internal
 */
#[CoversClass(Capability::class)]
final class CapabilityTest extends TestCase
{
    public function testTheCasesAreTheNineCapabilities(): void
    {
        self::assertSame(
            [
                'CollectionReader' => 'collection_reader',
                'ResourceLoader' => 'resource_loader',
                'RelatedLoader' => 'related_loader',
                'ResourceCreator' => 'resource_creator',
                'ResourceUpdater' => 'resource_updater',
                'ResourceDeleter' => 'resource_deleter',
                'RelationshipReplacer' => 'relationship_replacer',
                'RelationshipAdder' => 'relationship_adder',
                'RelationshipRemover' => 'relationship_remover',
            ],
            array_combine(
                array_map(static function (Capability $capability): string {
                    return $capability->name;
                }, Capability::cases()),
                array_map(static function (Capability $capability): string {
                    return $capability->value;
                }, Capability::cases()),
            ),
        );
    }

    /**
     * every port interface of `Store` has its case, except `TransactionBoundary`,
     * which the API binds once and no resource binds
     */
    public function testEveryCaseIsOnePortOfStoreAResourceBinds(): void
    {
        $ports = [
            Capability::CollectionReader->value => CollectionReader::class,
            Capability::ResourceLoader->value => ResourceLoader::class,
            Capability::RelatedLoader->value => RelatedLoader::class,
            Capability::ResourceCreator->value => ResourceCreator::class,
            Capability::ResourceUpdater->value => ResourceUpdater::class,
            Capability::ResourceDeleter->value => ResourceDeleter::class,
            Capability::RelationshipReplacer->value => RelationshipReplacer::class,
            Capability::RelationshipAdder->value => RelationshipAdder::class,
            Capability::RelationshipRemover->value => RelationshipRemover::class,
        ];

        self::assertSame(
            array_map(static function (Capability $capability): string {
                return $capability->value;
            }, Capability::cases()),
            array_keys($ports),
        );

        $namespace = (new ReflectionClass(CollectionReader::class))->getNamespaceName();
        $interfaces = [];

        foreach (glob(dirname(__DIR__, 3) . '/src/Store/*.php') ?: [] as $file) {
            $name = $namespace . '\\' . basename($file, '.php');

            if (interface_exists($name)) {
                $interfaces[] = $name;
            }
        }

        $expected = [...array_values($ports), TransactionBoundary::class];
        sort($expected);
        sort($interfaces);

        self::assertSame($expected, $interfaces);
    }

    public function testTheEnumIsInternal(): void
    {
        self::assertStringContainsString(
            '@internal',
            (string) (new ReflectionEnum(Capability::class))->getDocComment(),
        );
    }
}
