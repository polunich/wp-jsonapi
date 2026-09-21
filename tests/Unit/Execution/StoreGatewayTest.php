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
use OutOfBoundsException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Execution\StoreGateway;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Operation\AddToRelationship;
use Polunich\WpJsonApi\Operation\CreateResource;
use Polunich\WpJsonApi\Operation\DeleteResource;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Operation\RemoveFromRelationship;
use Polunich\WpJsonApi\Operation\ReplaceRelationship;
use Polunich\WpJsonApi\Operation\UpdateResource;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\FieldHint;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Query\Window;
use Polunich\WpJsonApi\Store\Accepted;
use Polunich\WpJsonApi\Store\CollectionQuery;
use Polunich\WpJsonApi\Store\CollectionReader;
use Polunich\WpJsonApi\Store\LoadRequest;
use Polunich\WpJsonApi\Store\RelatedLoader;
use Polunich\WpJsonApi\Store\RelatedLoadRequest;
use Polunich\WpJsonApi\Store\RelationshipAdder;
use Polunich\WpJsonApi\Store\RelationshipRemover;
use Polunich\WpJsonApi\Store\RelationshipReplacer;
use Polunich\WpJsonApi\Store\ResourceCreator;
use Polunich\WpJsonApi\Store\ResourceDeleter;
use Polunich\WpJsonApi\Store\ResourceLoader;
use Polunich\WpJsonApi\Store\ResourceUpdater;
use Polunich\WpJsonApi\Store\Slice;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\BoundImplementations;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\Store\RecordingWriter;
use ReflectionClass;
use UnexpectedValueException;

/**
 * @internal
 */
#[CoversClass(StoreGateway::class)]
final class StoreGatewayTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(StoreGateway::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    /**
     * @param class-string $port
     */
    #[DataProvider('ports')]
    public function testEveryCapabilityIsBoundAsItsPort(Capability $capability, string $port): void
    {
        self::assertSame($port, StoreGateway::portOf($capability));
    }

    /**
     * @return iterable<string, array{Capability, class-string}>
     */
    public static function ports(): iterable
    {
        yield Capability::CollectionReader->value => [Capability::CollectionReader, CollectionReader::class];
        yield Capability::ResourceLoader->value => [Capability::ResourceLoader, ResourceLoader::class];
        yield Capability::RelatedLoader->value => [Capability::RelatedLoader, RelatedLoader::class];
        yield Capability::ResourceCreator->value => [Capability::ResourceCreator, ResourceCreator::class];
        yield Capability::ResourceUpdater->value => [Capability::ResourceUpdater, ResourceUpdater::class];
        yield Capability::ResourceDeleter->value => [Capability::ResourceDeleter, ResourceDeleter::class];
        yield Capability::RelationshipReplacer->value => [
            Capability::RelationshipReplacer,
            RelationshipReplacer::class,
        ];
        yield Capability::RelationshipAdder->value => [Capability::RelationshipAdder, RelationshipAdder::class];
        yield Capability::RelationshipRemover->value => [Capability::RelationshipRemover, RelationshipRemover::class];
    }

    public function testTheTableNamesAPortForEveryCapability(): void
    {
        self::assertCount(count(Capability::cases()), iterator_to_array(self::ports()));
    }

    public function testALoadReturnsTheRecordOfEachIdAtItsPosition(): void
    {
        $one = new Pet('1', 'one');
        $loader = self::loader([$one, null]);
        $request = new LoadRequest(
            'pets',
            ['1', '2'],
            new FieldHint(null),
            new LanguagePreferences([]),
            new Values([]),
        );

        self::assertSame([$one, null], self::gateway([ResourceLoader::class => $loader])->load($request));
        self::assertSame([$request], $loader->asked);
    }

    /**
     * @param array<mixed> $batch
     */
    #[DataProvider('brokenLoads')]
    public function testALoadThatBreaksTheBatchRuleIsRefused(array $batch, string $message): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        self::gateway([ResourceLoader::class => self::loader($batch)])->load(
            new LoadRequest('pets', ['1', '2'], new FieldHint(null), new LanguagePreferences([]), new Values([])),
        );
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function brokenLoads(): iterable
    {
        yield 'the records keyed by their ids in another order' => [
            ['2' => new Pet('2', 'two'), '1' => new Pet('1', 'one')],
            'the resource loader of type "pets" returned an array keyed by other than its positions',
        ];
        yield 'fewer records than ids' => [
            [new Pet('1', 'one')],
            'the resource loader of type "pets" returned 1 entries for a request of 2, and "The Array of values'
            . ' must be the same length as the Array of keys."',
        ];
        yield 'more records than ids' => [
            [new Pet('1', 'one'), null, null],
            'the resource loader of type "pets" returned 3 entries for a request of 2',
        ];
        yield 'a value that is neither a record nor null' => [
            [new Pet('1', 'one'), 'two'],
            'the resource loader of type "pets" returned string at the place of id "2", and a loader returns'
            . ' the record of each id or null for the record it does not give',
        ];
    }

    public function testARelatedLoadReturnsTheRecordsOfEachParentAtItsPosition(): void
    {
        $five = new Pet('5', 'five');
        $related = self::relatedLoader([[$five], []]);
        $request = self::relatedRequest(['1', '2'], 2);

        self::assertSame([[$five], []], self::gateway([RelatedLoader::class => $related])->loadRelated($request));
        self::assertSame([$request], $related->asked);
    }

    /**
     * @param array<mixed> $batch
     */
    #[DataProvider('brokenRelatedLoads')]
    public function testARelatedLoadThatBreaksTheBatchRuleIsRefused(array $batch, string $message): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        self::gateway([RelatedLoader::class => self::relatedLoader($batch)])->loadRelated(self::relatedRequest(
            ['1', '2'],
            2,
        ));
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function brokenRelatedLoads(): iterable
    {
        $port = 'the related loader of relationship "orders" of type "pets"';

        yield 'the lists keyed by their parents in another order' => [
            ['2' => [], '1' => [new Pet('5', 'five')]],
            $port . ' returned an array keyed by other than its positions',
        ];
        yield 'fewer lists than parents' => [[[]], $port . ' returned 1 entries for a request of 2'];
        yield 'a parent that carries no list' => [[[], 'five'], $port . ' returned string for parent "2"'];
        yield 'a list keyed by the ids of its records' => [
            [['5' => new Pet('5', 'five')], []],
            $port . ' returned an array keyed by other than its positions for parent "1"',
        ];
        yield 'more records than the limit' => [
            [[new Pet('5', 'five'), new Pet('6', 'six'), new Pet('7', 'seven')], []],
            $port . ' returned 3 records for parent "1", and the reading asks for 2 at most',
        ];
        yield 'a related record that is no record' => [
            [['five'], []],
            $port . ' returned string among the records of parent "1", and a related record is a record',
        ];
    }

    public function testAReadingOfOneRecordPerParentReturnsItOrNull(): void
    {
        $owner = new Pet('101', 'Ann');

        self::assertSame(
            [$owner, null],
            self::gateway([RelatedLoader::class => self::relatedLoader([[$owner], []])])->loadToOne(
                RelatedLoadRequest::toOne(
                    'pets',
                    ['1', '2'],
                    'owner',
                    new FieldHint(null),
                    new LanguagePreferences([]),
                    new Values([]),
                ),
            ),
        );
    }

    public function testAReadingOfMoreThanOneRecordPerParentIsNoReadingOfOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship "orders" of type "pets" is read with the limit 2');

        self::gateway([RelatedLoader::class => self::relatedLoader([[]])])->loadToOne(self::relatedRequest(['1'], 2));
    }

    public function testAReadingReturnsTheSliceOfTheReader(): void
    {
        $slice = self::slice([new Pet('1', 'one')], 7);

        self::assertSame(
            $slice,
            self::gateway([CollectionReader::class => self::reader($slice)])->read(self::query(2)),
        );
    }

    /**
     * @param array<mixed> $records
     */
    #[DataProvider('brokenSlices')]
    public function testASliceThatBreaksTheRuleOfTheReaderIsRefused(array $records, string $message): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        self::gateway([CollectionReader::class => self::reader(self::slice($records))])->read(self::query(3));
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function brokenSlices(): iterable
    {
        yield 'records keyed by their ids' => [
            ['2' => new Pet('2', 'two'), '1' => new Pet('1', 'one')],
            'the collection reader of type "pets" returned a slice whose records are an array keyed by other'
            . ' than its positions',
        ];
        yield 'more records than the window reads' => [
            [new Pet('1', 'one'), new Pet('2', 'two'), new Pet('3', 'three'), new Pet('4', 'four')],
            'the collection reader of type "pets" returned 4 records for a window of 3, and a reading returns the'
            . ' records of the page and the one probe record past it at most',
        ];
        yield 'a value that is no record in the page' => [
            [new Pet('1', 'one'), 'two'],
            'the collection reader of type "pets" returned string at position 1, and a reading returns records',
        ];
        yield 'a value that is no record as the probe' => [
            [new Pet('1', 'one'), new Pet('2', 'two'), 'three'],
            'the collection reader of type "pets" returned string at position 2',
        ];
    }

    /**
     * the rule of a slice holds for any source, which the message names
     */
    public function testASliceOfAnotherSourceIsHeldToTheSameRule(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('the handler of the endpoint "searchPets" returned 4 records for a window of 3');

        StoreGateway::checkSlice(
            self::slice([new Pet('1', 'one'), new Pet('2', 'two'), new Pet('3', 'three'), new Pet('4', 'four')]),
            self::query(3),
            'the handler of the endpoint "searchPets"',
        );
    }

    public function testEachWriteRunsThroughItsPortAndReturnsWhatItReturned(): void
    {
        $created = new Pet('1', 'one');
        $accepted = new Accepted('jobs', new Pet('201', 'working'));
        $store = new RecordingWriter($created, $accepted);
        $gateway = self::gateway([
            ResourceCreator::class => $store,
            ResourceUpdater::class => $store,
            ResourceDeleter::class => $store,
            RelationshipReplacer::class => $store,
            RelationshipAdder::class => $store,
            RelationshipRemover::class => $store,
        ]);
        $parent = new Identifier('pets', '1');
        $fields = new SparseFieldsets([]);
        $languages = new LanguagePreferences([]);
        $create = new CreateResource(
            'pets',
            null,
            new Values([]),
            new Values([]),
            null,
            $fields,
            $languages,
            new Values([]),
        );
        $update = new UpdateResource(
            $parent,
            new Values([]),
            new Values([]),
            null,
            null,
            $fields,
            $languages,
            new Values([]),
        );
        $delete = new DeleteResource($parent, null, $languages, new Values([]));
        $replace = new ReplaceRelationship(
            $parent,
            'orders',
            Linkage::toMany([]),
            null,
            null,
            $fields,
            $languages,
            new Values([]),
        );
        $add = new AddToRelationship(
            $parent,
            'orders',
            Linkage::toMany([]),
            null,
            null,
            $fields,
            $languages,
            new Values([]),
        );
        $remove = new RemoveFromRelationship(
            $parent,
            'orders',
            Linkage::toMany([]),
            null,
            null,
            $fields,
            $languages,
            new Values([]),
        );

        self::assertSame($created, $gateway->create($create));
        self::assertSame($created, $gateway->update($update));
        self::assertSame($accepted, $gateway->delete($delete));
        self::assertSame($accepted, $gateway->replace($replace));
        self::assertSame($accepted, $gateway->add($add));
        self::assertSame($accepted, $gateway->remove($remove));
        self::assertSame([$create, $update, $delete, $replace, $add, $remove], $store->written);
    }

    public function testACapabilityTheTypeDoesNotBindIsRefused(): void
    {
        $resolver = BoundImplementations::of([]);

        try {
            (new StoreGateway(self::registry(), $resolver))->read(
                new CollectionQuery(
                    'customers',
                    null,
                    Sort::byId(),
                    new Window(0, 2),
                    false,
                    new FieldHint(null),
                    new LanguagePreferences([]),
                    null,
                    new Values([]),
                ),
            );
            self::fail('a type that binds no collection reader was read');
        } catch (LogicException $refusal) {
            self::assertStringStartsWith('type "customers" binds no collection_reader: ', $refusal->getMessage());
            self::assertSame([], $resolver->ports);
        }
    }

    public function testATypeThatIsNotDeclaredIsRefused(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('type "videos" is not declared');

        self::gateway([])->load(new LoadRequest(
            'videos',
            ['1'],
            new FieldHint(null),
            new LanguagePreferences([]),
            new Values([]),
        ));
    }

    /**
     * @param list<string> $parentIds
     */
    private static function relatedRequest(array $parentIds, int $limit): RelatedLoadRequest
    {
        return new RelatedLoadRequest(
            'pets',
            $parentIds,
            'orders',
            Sort::byId(),
            $limit,
            new FieldHint(null),
            new LanguagePreferences([]),
            new Values([]),
        );
    }

    private static function query(int $limit): CollectionQuery
    {
        return new CollectionQuery(
            'pets',
            null,
            Sort::byId(),
            new Window(0, $limit),
            false,
            new FieldHint(null),
            new LanguagePreferences([]),
            null,
            new Values([]),
        );
    }

    /**
     * lets a test hand `Slice` any array: its list of records is a docblock promise only
     *
     * @param array<mixed> $records
     *
     * @return Slice<object>
     */
    private static function slice(array $records, ?int $total = null): Slice
    {
        /** @var list<object> $list */
        $list = $records;

        return new Slice($list, $total);
    }

    /**
     * @param array<class-string, object> $implementations
     */
    private static function gateway(array $implementations): StoreGateway
    {
        return new StoreGateway(self::registry(), BoundImplementations::of($implementations));
    }

    private static function registry(): ResourceRegistry
    {
        return new ResourceRegistry([
            Samples::resource(
                'pets',
                relationships: [
                    Samples::toOne('owner', 'customers', readsRelatedId: false),
                    Samples::toMany(
                        'orders',
                        'pets',
                        updates: [RelationshipUpdate::Replace, RelationshipUpdate::Add, RelationshipUpdate::Remove],
                    ),
                ],
                capabilities: Capability::cases(),
            ),
            Samples::resource('customers'),
        ]);
    }

    /**
     * @param array<mixed> $batch
     *
     * @return ResourceLoader<object>&object{asked: list<LoadRequest>}
     */
    private static function loader(array $batch): object
    {
        return new class ($batch) implements ResourceLoader {
            /**
             * @var list<LoadRequest>
             */
            public array $asked = [];

            /**
             * @param array<mixed> $batch
             */
            public function __construct(private readonly array $batch) {}

            /**
             * @return list<object|null>
             */
            #[Override]
            public function load(LoadRequest $request): array
            {
                $this->asked[] = $request;
                /** @var list<object|null> $batch */
                $batch = $this->batch;

                return $batch;
            }
        };
    }

    /**
     * @param array<mixed> $batch
     *
     * @return RelatedLoader<object>&object{asked: list<RelatedLoadRequest>}
     */
    private static function relatedLoader(array $batch): object
    {
        return new class ($batch) implements RelatedLoader {
            /**
             * @var list<RelatedLoadRequest>
             */
            public array $asked = [];

            /**
             * @param array<mixed> $batch
             */
            public function __construct(private readonly array $batch) {}

            /**
             * @return list<list<object>>
             */
            #[Override]
            public function loadRelated(RelatedLoadRequest $request): array
            {
                $this->asked[] = $request;
                /** @var list<list<object>> $batch */
                $batch = $this->batch;

                return $batch;
            }
        };
    }

    /**
     * @param Slice<object> $slice
     *
     * @return CollectionReader<object>
     */
    private static function reader(Slice $slice): CollectionReader
    {
        return new class ($slice) implements CollectionReader {
            /**
             * @param Slice<object> $slice
             */
            public function __construct(private readonly Slice $slice) {}

            /**
             * @return Slice<object>
             */
            #[Override]
            public function read(CollectionQuery $query): Slice
            {
                return $this->slice;
            }
        };
    }
}
