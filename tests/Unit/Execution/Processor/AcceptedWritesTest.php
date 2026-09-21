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

namespace Polunich\WpJsonApi\Tests\Unit\Execution\Processor;

use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Execution\ExecutionContext;
use Polunich\WpJsonApi\Execution\IncludeLoader;
use Polunich\WpJsonApi\Execution\Paginator;
use Polunich\WpJsonApi\Execution\Processor\AcceptedWrites;
use Polunich\WpJsonApi\Execution\StoreGateway;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Localization\LanguageRange;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\ImplementationReference;
use Polunich\WpJsonApi\Metadata\ImplementationResolver;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Operation\AddToRelationship;
use Polunich\WpJsonApi\Operation\CreateResource;
use Polunich\WpJsonApi\Operation\DeleteResource;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Operation\RemoveFromRelationship;
use Polunich\WpJsonApi\Operation\ReplaceRelationship;
use Polunich\WpJsonApi\Operation\UpdateResource;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Inclusion\IncludePath;
use Polunich\WpJsonApi\Query\Inclusion\IncludePaths;
use Polunich\WpJsonApi\Store\Accepted;
use Polunich\WpJsonApi\Store\RelatedLoader;
use Polunich\WpJsonApi\Store\RelatedLoadRequest;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use ReflectionClass;
use UnexpectedValueException;

/**
 * @internal
 */
#[CoversClass(AcceptedWrites::class)]
final class AcceptedWritesTest extends TestCase
{
    private const MONITOR = 'jobs';

    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(AcceptedWrites::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    #[DataProvider('writes')]
    public function testEveryAcceptedWriteRespondsWith202WithTheMonitorResource(
        CreateResource
        |UpdateResource
        |DeleteResource
        |ReplaceRelationship
        |AddToRelationship
        |RemoveFromRelationship $operation,
    ): void {
        $record = new Pet('201', 'working');
        $fields = new SparseFieldsets([self::MONITOR => ['title']]);

        $result = self::accepted()->result($operation, new Accepted(self::MONITOR, $record), $fields, self::context());

        self::assertSame(202, $result->status());
        self::assertNull($result->entityTag());
        self::assertSame(self::MONITOR, $result->monitor->type);
        self::assertSame('201', $result->monitor->id);
        self::assertSame($record, $result->monitor->record);
        self::assertSame($fields, $result->fields);
        self::assertSame([], $result->graph->included());
    }

    /**
     * @return iterable<string, array{
     *     CreateResource|UpdateResource|DeleteResource
     *     |ReplaceRelationship|AddToRelationship|RemoveFromRelationship,
     * }>
     */
    public static function writes(): iterable
    {
        $parent = new Identifier('pets', '1');
        $fields = new SparseFieldsets([]);
        $languages = new LanguagePreferences([]);

        yield OperationKind::CreateResource->value => [new CreateResource(
            'pets',
            null,
            new Values([]),
            new Values([]),
            null,
            $fields,
            $languages,
            new Values([]),
        )];
        yield OperationKind::UpdateResource->value => [new UpdateResource(
            $parent,
            new Values([]),
            new Values([]),
            null,
            null,
            $fields,
            $languages,
            new Values([]),
        )];
        yield OperationKind::DeleteResource->value => [new DeleteResource($parent, null, $languages, new Values([]))];
        yield OperationKind::ReplaceRelationship->value => [new ReplaceRelationship(
            $parent,
            'orders',
            Linkage::toMany([]),
            null,
            null,
            $fields,
            $languages,
            new Values([]),
        )];
        yield OperationKind::AddToRelationship->value => [new AddToRelationship(
            $parent,
            'orders',
            Linkage::toMany([]),
            null,
            null,
            $fields,
            $languages,
            new Values([]),
        )];
        yield OperationKind::RemoveFromRelationship->value => [new RemoveFromRelationship(
            $parent,
            'orders',
            Linkage::toMany([]),
            null,
            null,
            $fields,
            $languages,
            new Values([]),
        )];
    }

    public function testTheIncludePathsOfTheWriteAreNotFollowedFromTheMonitor(): void
    {
        $related = self::relatedLoader();

        $result = self::accepted(self::monitored(), $related)->result(
            new CreateResource(
                'pets',
                null,
                new Values([]),
                new Values([]),
                new IncludePaths([new IncludePath(['orders'])]),
                new SparseFieldsets([]),
                new LanguagePreferences([]),
                new Values([]),
            ),
            new Accepted(self::MONITOR, new Pet('201', 'working')),
            new SparseFieldsets([]),
            self::context(),
        );

        self::assertSame([], $result->graph->included());
        self::assertNull($result->graph->linkage(self::MONITOR, '201', 'orders'));
        self::assertSame(['steps'], array_map(static function (RelatedLoadRequest $request): string {
            return $request->relationship;
        }, $related->asked));
    }

    public function testTheMonitorIsReadInTheLanguagesOfTheWrite(): void
    {
        $related = self::relatedLoader();
        $languages = new LanguagePreferences([new LanguageRange('uk')]);

        self::accepted(self::monitored(), $related)->result(
            new CreateResource(
                'pets',
                null,
                new Values([]),
                new Values([]),
                null,
                new SparseFieldsets([]),
                $languages,
                new Values([]),
            ),
            new Accepted(self::MONITOR, new Pet('201', 'working')),
            new SparseFieldsets([]),
            self::context(),
        );

        self::assertCount(1, $related->asked);
        self::assertSame($languages, $related->asked[0]->languages);
    }

    #[DataProvider('undeclaredMonitors')]
    public function testAMonitorTheDeclarationDoesNotNameIsRefused(
        string $returned,
        ?string $declared,
        string $message,
    ): void {
        $registry = self::registry($declared === null ? [] : [OperationKind::CreateResource->value => $declared]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        self::accepted($registry)->result(
            new CreateResource(
                'pets',
                null,
                new Values([]),
                new Values([]),
                null,
                new SparseFieldsets([]),
                new LanguagePreferences([]),
                new Values([]),
            ),
            new Accepted($returned, new Pet('201', 'working')),
            new SparseFieldsets([]),
            self::context(),
        );
    }

    /**
     * @return iterable<string, array{string, ?string, string}>
     */
    public static function undeclaredMonitors(): iterable
    {
        yield 'the declaration names no monitor type for the kind' => [
            self::MONITOR,
            null,
            'the store accepted the create_resource of type "pets" with a monitor of type "jobs", and the'
            . ' declaration names no monitor type for that kind',
        ];
        yield 'the declaration names another monitor type' => [
            'pets',
            self::MONITOR,
            'the store accepted the create_resource of type "pets" with a monitor of type "pets", and the'
            . ' declaration names the monitor type "jobs" for that kind',
        ];
    }

    /**
     * `jobs` of the default registry declares no relationship,
     * so the walk of the monitor's graph never reaches the store
     *
     * @param ?RelatedLoader<object> $related
     */
    private static function accepted(?ResourceRegistry $registry = null, ?RelatedLoader $related = null): AcceptedWrites
    {
        $registry ??= self::registry();
        $store = new StoreGateway($registry, new class ($related) implements ImplementationResolver {
            /**
             * @param ?RelatedLoader<object> $related
             */
            public function __construct(private readonly ?RelatedLoader $related) {}

            /**
             * @template T of object
             *
             * @param class-string<T> $port
             *
             * @return T
             */
            #[Override]
            public function resolve(ImplementationReference $reference, string $port): object
            {
                if ($this->related instanceof $port) {
                    return $this->related;
                }

                throw new LogicException('the monitor of a 202 reads no store but through the related loader');
            }
        });

        return new AcceptedWrites($registry, new IncludeLoader($registry, $store, new Paginator($registry, $store)));
    }

    /**
     * @param array<value-of<OperationKind>, string>|null $monitorTypes
     *        `null` declares `jobs` for every write
     */
    private static function registry(?array $monitorTypes = null): ResourceRegistry
    {
        $writes = [
            OperationKind::CreateResource,
            OperationKind::UpdateResource,
            OperationKind::DeleteResource,
            OperationKind::ReplaceRelationship,
            OperationKind::AddToRelationship,
            OperationKind::RemoveFromRelationship,
        ];
        $declared = [];

        foreach ($writes as $kind) {
            $declared[$kind->value] = self::MONITOR;
        }

        return new ResourceRegistry([
            Samples::resource(
                'pets',
                relationships: [
                    Samples::toMany(
                        'orders',
                        'orders',
                        includable: false,
                        updates: [RelationshipUpdate::Replace, RelationshipUpdate::Add, RelationshipUpdate::Remove],
                    ),
                ],
                capabilities: [
                    Capability::ResourceCreator,
                    Capability::ResourceUpdater,
                    Capability::ResourceDeleter,
                    Capability::RelationshipReplacer,
                    Capability::RelationshipAdder,
                    Capability::RelationshipRemover,
                ],
                monitorTypes: $monitorTypes ?? $declared,
            ),
            Samples::resource('orders', capabilities: [Capability::CollectionReader]),
            Samples::resource(self::MONITOR, attributes: [Samples::attribute('title')]),
        ]);
    }

    /**
     * `jobs` declares `orders` as `pets` does, so the include path of the write fits it too;
     * its `steps` always carry linkage, so the walk of the monitor still asks the related loader
     */
    private static function monitored(): ResourceRegistry
    {
        $policy = new PaginationPolicy(2, 100);

        return new ResourceRegistry([
            Samples::resource(
                'pets',
                relationships: [Samples::toMany('orders', 'orders', paginationPolicy: $policy)],
                capabilities: [Capability::ResourceCreator, Capability::RelatedLoader],
                monitorTypes: [OperationKind::CreateResource->value => self::MONITOR],
            ),
            Samples::resource('orders', capabilities: [Capability::CollectionReader]),
            Samples::resource(
                self::MONITOR,
                relationships: [
                    Samples::toMany('orders', 'orders', paginationPolicy: $policy),
                    Samples::toMany('steps', 'orders', linkageAlwaysPresent: true, paginationPolicy: $policy),
                ],
                capabilities: [Capability::RelatedLoader],
            ),
        ]);
    }

    /**
     * @return RelatedLoader<object>&object{asked: list<RelatedLoadRequest>}
     */
    private static function relatedLoader(): object
    {
        return new class implements RelatedLoader {
            /**
             * @var list<RelatedLoadRequest>
             */
            public array $asked = [];

            /**
             * @return list<list<object>>
             */
            #[Override]
            public function loadRelated(RelatedLoadRequest $request): array
            {
                $this->asked[] = $request;

                return array_fill(0, count($request->parentIds), []);
            }
        };
    }

    private static function context(): ExecutionContext
    {
        return new ExecutionContext(
            new ServerRequest('POST', new Headers([]), [], [], ''),
            MediaType::jsonApi(),
            null,
            false,
            false,
            [],
        );
    }
}
