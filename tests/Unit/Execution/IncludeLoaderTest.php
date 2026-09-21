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

use LogicException;
use OutOfBoundsException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Execution\ExecutionContext;
use Polunich\WpJsonApi\Execution\GraphRequest;
use Polunich\WpJsonApi\Execution\IncludeLoader;
use Polunich\WpJsonApi\Execution\Paginator;
use Polunich\WpJsonApi\Execution\Result\RecordGraph;
use Polunich\WpJsonApi\Execution\Result\RecordLinkage;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
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
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Query\Sort\SortField;
use Polunich\WpJsonApi\Store\RelatedLoader;
use Polunich\WpJsonApi\Store\RelatedLoadRequest;
use Polunich\WpJsonApi\Store\ResourceLoader;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\Query\WrittenInclude;
use Polunich\WpJsonApi\Tests\Fixtures\Store\RecordingStore;
use ReflectionClass;
use UnexpectedValueException;

/**
 * @internal
 */
#[CoversClass(IncludeLoader::class)]
final class IncludeLoaderTest extends TestCase
{
    public function testTheRequiredFieldsNameTheIdentifierOfEveryToOneRelationshipThatDeclaresOne(): void
    {
        self::assertSame(['owner'], IncludeLoader::requiredFields(self::registry()->get('pets'), null));
    }

    public function testTheRequiredFieldsNameTheFirstSegmentOfEverySortFieldAndNeverId(): void
    {
        $sort = new Sort([
            new SortField(new FieldPath(['name'])),
            new SortField(new FieldPath(['owner', 'name'])),
            new SortField(new FieldPath(['id'])),
        ]);

        self::assertSame(['owner', 'name'], IncludeLoader::requiredFields(self::registry()->get('pets'), $sort));
    }

    public function testATypeThatReadsNoIdentifierAndNoSortFieldRequiresNoField(): void
    {
        self::assertSame([], IncludeLoader::requiredFields(self::registry()->get('tags'), null));
    }

    public function testTheFieldHintCarriesTheFieldsetOfTheClientAndTheFieldsTheLibraryReads(): void
    {
        $hint = self::loader(self::store())->fieldHint('pets', new SparseFieldsets(['pets' => ['name']]), null);

        self::assertSame(['name', 'owner'], $hint->fields());
    }

    public function testTheFieldHintOfATypeTheClientNamedNoFieldsetForMeansEveryField(): void
    {
        $hint = self::loader(self::store())->fieldHint('pets', new SparseFieldsets([]), null);

        self::assertTrue($hint->hasEveryField());
    }

    public function testTheIdentifierHintNamesTheFieldsTheLibraryReadsAndNoOther(): void
    {
        $hint = self::loader(self::store())->identifierHint('pets', null);

        self::assertSame(['owner'], $hint->fields());
        self::assertFalse($hint->hasEveryField());
    }

    public function testAToOneWithAnIdentifierIsLinkedFromTheRecordAndReadsNoRelatedRecord(): void
    {
        $store = self::store();

        $graph = self::loader($store)->load(
            self::graph(),
            [self::record('pets', new Pet('1', 'one', '9'))],
            self::context(),
        );

        $owner = self::linkageOf($graph, 'pets', '1', 'owner')->record();

        self::assertInstanceOf(ResourceRecord::class, $owner);
        self::assertSame('customers', $owner->type);
        self::assertSame('9', $owner->id);
        self::assertNull($owner->record);
        self::assertSame([], $store->loads);
        self::assertSame([], $graph->included());
    }

    public function testAnEmptyToOneIsLinkedWithNull(): void
    {
        $graph = self::loader(self::store())->load(
            self::graph(),
            [self::record('pets', new Pet('1', 'one'))],
            self::context(),
        );

        self::assertNull(self::linkageOf($graph, 'pets', '1', 'owner')->record());
    }

    public function testAnIncludePathReadsTheRecordOfAnIdentifierAndIncludesIt(): void
    {
        $store = self::store(['customers' => ['9' => new Pet('9', 'nine')]]);

        $graph = self::loader($store)->load(
            self::graph('owner'),
            [self::record('pets', new Pet('1', 'one', '9'))],
            self::context(includeSupplied: true),
        );

        self::assertSame(['customers/9'], self::pairs($graph));
        self::assertCount(1, $store->loads);
        self::assertSame('customers', $store->loads[0]->type);
        self::assertSame(['9'], $store->loads[0]->ids);
        self::assertNull(self::linkageOf($graph, 'pets', '1', 'owner')->record()?->record);
    }

    public function testTheIdentifiersOfALevelAreReadInOneBatchAndEachIdOnce(): void
    {
        $store = self::store(['customers' => ['9' => new Pet('9', 'nine'), '8' => new Pet('8', 'eight')]]);

        $graph = self::loader($store)->load(
            self::graph('owner'),
            [
                self::record('pets', new Pet('1', 'one', '9')),
                self::record('pets', new Pet('2', 'two', '9')),
                self::record('pets', new Pet('3', 'three', '8')),
            ],
            self::context(includeSupplied: true),
        );

        self::assertCount(1, $store->loads);
        self::assertSame(['9', '8'], $store->loads[0]->ids);
        self::assertSame(['customers/9', 'customers/8'], self::pairs($graph));
    }

    public function testARelatedRecordTheClientMayNotSeeRendersItsIdentifierAndJoinsNoIncluded(): void
    {
        $store = self::store();

        $graph = self::loader($store)->load(
            self::graph('owner'),
            [self::record('pets', new Pet('1', 'one', '9'))],
            self::context(includeSupplied: true),
        );

        self::assertCount(1, $store->loads);
        self::assertSame([], $graph->included());
        self::assertSame('9', self::linkageOf($graph, 'pets', '1', 'owner')->record()?->id);
    }

    public function testOnlyTheRecordsOfTheLinkagePageJoinIncludedAndNeverTheProbe(): void
    {
        $store = self::store(members: ['orders' => ['1' => [
            new Pet('5', 'five', '9'),
            new Pet('6', 'six', '9'),
            new Pet('7', 'seven', '9'),
        ]]]);

        $graph = self::loader($store)->load(
            self::graph('orders'),
            [self::record('pets', new Pet('1', 'one', '9'))],
            self::context(includeSupplied: true),
        );

        $page = self::linkageOf($graph, 'pets', '1', 'orders')->page();

        self::assertSame(['5', '6'], array_map(static function (ResourceRecord $record): string {
            return $record->id;
        }, $page->records));
        self::assertTrue($page->hasNext);
        self::assertSame(['orders/5', 'orders/6'], self::pairs($graph));
    }

    public function testARelationshipThatOnlyCarriesLinkageAddsNothingToIncluded(): void
    {
        $store = self::store(members: ['tags' => ['1' => [new Pet('3', 'three'), new Pet('4', 'four')]]]);

        $graph = self::loader($store)->load(
            self::graph(),
            [self::record('pets', new Pet('1', 'one', '9'))],
            self::context(),
        );

        $page = self::linkageOf($graph, 'pets', '1', 'tags')->page();

        self::assertSame(['3', '4'], array_map(static function (ResourceRecord $record): string {
            return $record->id;
        }, $page->records));
        self::assertSame([], $graph->included());
    }

    public function testARelationshipNeitherAlwaysLinkedNorNamedWritesNoDataMember(): void
    {
        $graph = self::loader(self::store())->load(
            self::graph(),
            [self::record('pets', new Pet('1', 'one', '9'))],
            self::context(),
        );

        self::assertNull($graph->linkage('pets', '1', 'orders'));
        self::assertNull($graph->linkage('pets', '1', 'breeder'));
    }

    public function testAPathOfTwoSegmentsIsWalkedLevelByLevelAndIncludesWhatItRunsThrough(): void
    {
        $store = self::store(
            ['customers' => ['9' => new Pet('9', 'nine')]],
            ['orders' => ['1' => [new Pet('5', 'five', '9')]]],
        );

        $graph = self::loader($store)->load(
            self::graph('orders.seller'),
            [self::record('pets', new Pet('1', 'one', '9'))],
            self::context(includeSupplied: true),
        );

        self::assertSame(['orders/5', 'customers/9'], self::pairs($graph));
        self::assertSame('9', self::linkageOf($graph, 'orders', '5', 'seller')->record()?->id);
        self::assertCount(1, $store->loads);
        self::assertSame(['9'], $store->loads[0]->ids);
    }

    /**
     * @param list<string> $included
     */
    #[DataProvider('pathOrders')]
    public function testTheIncludedResourcesFollowTheOrderOfTheIncludePaths(string $include, array $included): void
    {
        $store = self::store(
            ['customers' => ['9' => new Pet('9', 'nine')]],
            ['orders' => ['1' => [new Pet('5', 'five', '9')]]],
        );

        $graph = self::loader($store)->load(
            self::graph($include),
            [self::record('pets', new Pet('1', 'one', '9'))],
            self::context(includeSupplied: true),
        );

        self::assertSame($included, self::pairs($graph));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function pathOrders(): iterable
    {
        yield 'the relationship declared last written first' => ['orders,owner', ['orders/5', 'customers/9']];
        yield 'the relationship declared first written first' => ['owner,orders', ['customers/9', 'orders/5']];
    }

    /**
     * a relationship takes the earliest place a path names it at,
     * over every parent of the level that reaches it
     *
     * @param list<string> $included
     */
    #[DataProvider('includeOrders')]
    public function testTheIncludedResourcesFollowTheFirstPlaceEveryRelationshipIsNamedAt(
        string $include,
        array $included,
    ): void {
        $store = self::store(
            ['customers' => ['9' => new Pet('9', 'nine')]],
            [
                'orders' => ['1' => [new Pet('5', 'five', '9')]],
                'breeder' => ['1' => [new Pet('8', 'eight')]],
                'pets' => ['9' => [new Pet('2', 'two', '9')], '8' => [new Pet('3', 'three', '9')]],
                'tagged' => ['9' => [new Pet('301', 'first tag')]],
            ],
        );

        $graph = self::loader($store)->load(
            self::graph($include),
            [self::record('pets', new Pet('1', 'one', '9'))],
            self::context(includeSupplied: true),
        );

        self::assertSame($included, self::pairs($graph));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function includeOrders(): iterable
    {
        yield 'a relationship two paths name, at the first of the two places' => [
            'owner.pets,orders,owner',
            ['customers/9', 'orders/5', 'pets/2'],
        ];
        yield 'a relationship two parents reach, at the first of their places' => [
            'owner.pets,owner.tagged,breeder.pets',
            ['customers/9', 'customers/8', 'pets/2', 'pets/3', 'tags/301'],
        ];
    }

    public function testARecordOfThePrimaryDataIsNeverAddedToTheIncludedResources(): void
    {
        $store = self::store(
            ['customers' => ['9' => new Pet('9', 'nine')]],
            ['pets' => ['9' => [new Pet('1', 'one', '9'), new Pet('2', 'two', '9')]]],
        );

        $graph = self::loader($store)->load(
            self::graph('owner.pets'),
            [self::record('pets', new Pet('1', 'one', '9'))],
            self::context(includeSupplied: true),
        );

        self::assertSame(['customers/9', 'pets/2'], self::pairs($graph));
    }

    /**
     * `owner` and `breeder` both reach `customers/9`, so its tails merge:
     * `owner.pets` is walked in either path order,
     * and `included` and the linkage of `breeder` share one record
     */
    #[DataProvider('pathsOfOneRecord')]
    public function testARecordTwoRelationshipsOfALevelReachKeepsTheTailsOfEveryParent(string $include): void
    {
        $store = self::store(
            ['customers' => ['9' => new Pet('9', 'nine')]],
            [
                'breeder' => ['1' => [new Pet('9', 'nine as the related loader returns it')]],
                'pets' => ['9' => [new Pet('1', 'one', '9'), new Pet('2', 'two', '9')]],
            ],
        );

        $graph = self::loader($store)->load(
            self::graph($include),
            [self::record('pets', new Pet('1', 'one', '9'))],
            self::context(includeSupplied: true),
        );

        self::assertSame(['customers/9', 'pets/2'], self::pairs($graph));
        self::assertSame($graph->included()[0], self::linkageOf($graph, 'pets', '1', 'breeder')->record());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsOfOneRecord(): iterable
    {
        yield 'the deeper path named first' => ['owner.pets,breeder'];
        yield 'the deeper path named last' => ['breeder,owner.pets'];
    }

    /**
     * `vet` loads its related record for the linkage, and the record still stays out of `included`
     */
    public function testAToOneThatIsAlwaysLinkedWithoutAnAccessorAddsNothingToIncluded(): void
    {
        $graph = self::loader(self::store(members: ['vet' => ['1' => [new Pet('9', 'nine')]]]))->load(
            self::graph(''),
            [self::record('pets', new Pet('1', 'one', '9'))],
            self::context(includeSupplied: true),
        );

        self::assertSame('9', self::linkageOf($graph, 'pets', '1', 'vet')->record()?->id);
        self::assertSame([], $graph->included());
    }

    /**
     * `customers` declares an `owner` of its own, pointing at itself:
     * a walk past the last segment of the path would load `customers/2`
     */
    public function testTheWalkStopsAtTheDeepestSegmentOfAPath(): void
    {
        $store = self::store(['customers' => ['9' => new Pet('9', 'nine', '2'), '2' => new Pet('2', 'two')]]);

        $graph = self::loader($store)->load(
            self::graph('owner'),
            [self::record('pets', new Pet('1', 'one', '9'))],
            self::context(includeSupplied: true),
        );

        self::assertSame(['customers/9'], self::pairs($graph));
        self::assertCount(1, $store->loads);
        self::assertSame('2', self::linkageOf($graph, 'customers', '9', 'owner')->record()?->id);
    }

    /**
     * the walk runs whether or not the request supplied include; only `isCompound()` follows it
     */
    #[DataProvider('compoundDocuments')]
    public function testTheDocumentIsCompoundExactlyWhenTheRequestSuppliedInclude(
        bool $supplied,
        ?string $include,
        int $included,
    ): void {
        $store = self::store(['customers' => ['9' => new Pet('9', 'nine')]]);

        $graph = self::loader($store)->load(
            self::graph($include),
            [self::record('pets', new Pet('1', 'one', '9'))],
            self::context(includeSupplied: $supplied),
        );

        self::assertSame($supplied, $graph->isCompound());
        self::assertCount($included, $graph->included());
    }

    /**
     * @return iterable<string, array{bool, ?string, int}>
     */
    public static function compoundDocuments(): iterable
    {
        yield 'a request that supplies include and names no path' => [true, '', 0];
        yield 'a request that supplies include and no path is read' => [true, null, 0];
        yield 'a path that no include parameter of the request carries' => [false, 'owner', 1];
    }

    /**
     * `orders` is named, so the client's fieldset gains every to-one whose related id is read;
     * `tags` is only linked, so its hint is `name` alone, which its default sort reads
     */
    public function testTheHintOfAReadingFollowsWhetherAnIncludePathNamesTheRelationship(): void
    {
        $store = self::store();

        self::loader($store)->load(
            self::graph('orders', new SparseFieldsets(['orders' => ['address']])),
            [self::record('pets', new Pet('1', 'one', '9'))],
            self::context(includeSupplied: true),
        );

        self::assertSame(['address', 'seller', 'pet'], self::relatedLoadRequest($store, 'orders')->fields->fields());
        self::assertSame(['name'], self::relatedLoadRequest($store, 'tags')->fields->fields());
        self::assertFalse(self::relatedLoadRequest($store, 'tags')->fields->hasEveryField());
    }

    public function testTheReadingOfALinkageAsksForOneRecordPastTheDeclaredPageSize(): void
    {
        $store = self::store();

        self::loader($store)->load(
            self::graph('orders'),
            [self::record('pets', new Pet('1', 'one', '9'))],
            self::context(includeSupplied: true),
        );

        $request = self::relatedLoadRequest($store, 'orders');

        self::assertSame(3, $request->limit);
        self::assertSame('pets', $request->type);
        self::assertSame(['1'], $request->parentIds);
    }

    public function testTheReadingOfALinkageOrdersByTheDefaultSortOfTheRelationshipWithIdAppended(): void
    {
        $store = self::store();

        self::loader($store)->load(
            self::graph('orders'),
            [self::record('pets', new Pet('1', 'one', '9'))],
            self::context(includeSupplied: true),
        );

        self::assertSame('id', self::relatedLoadRequest($store, 'orders')->sort->toString());
        self::assertSame('-name,id', self::relatedLoadRequest($store, 'tags')->sort->toString());
    }

    /**
     * `owner` is read through the resource loader,
     * `orders`, `tags` and `vet` through the related loader
     */
    public function testEveryReadingOfTheWalkRunsInTheLanguagesOfTheRequest(): void
    {
        $store = self::store(['customers' => ['9' => new Pet('9', 'nine')]]);
        $languages = self::languages();

        self::loader($store)->load(
            self::graph('owner,orders', languages: $languages),
            [self::record('pets', new Pet('1', 'one', '9'))],
            self::context(includeSupplied: true),
        );

        self::assertCount(1, $store->loads);
        self::assertSame($languages, $store->loads[0]->languages);
        self::assertSame($languages, self::relatedLoadRequest($store, 'orders')->languages);
        self::assertSame($languages, self::relatedLoadRequest($store, 'tags')->languages);
        self::assertSame($languages, self::relatedLoadRequest($store, 'vet')->languages);
    }

    /**
     * @param array<mixed> $batch
     */
    #[DataProvider('brokenLoadedResults')]
    public function testAResourceLoaderThatBreaksTheBatchRuleIsRefused(array $batch, string $message): void
    {
        $loader = self::createStub(ResourceLoader::class);
        $loader->method('load')->willReturn($batch);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        self::loaderWith([ResourceLoader::class => $loader, RelatedLoader::class => self::store()])->load(
            self::graph('owner'),
            [self::record('pets', new Pet('1', 'one', '9'))],
            self::context(includeSupplied: true),
        );
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function brokenLoadedResults(): iterable
    {
        yield 'no record for the one id' => [[], 'returned 0 entries for a request of 1'];
        yield 'two records for the one id' => [
            [new Pet('9', 'nine'), new Pet('8', 'eight')],
            'returned 2 entries for a request of 1',
        ];
        yield 'the record keyed by its id' => [
            ['9' => new Pet('9', 'nine')],
            'returned an array keyed by other than its positions',
        ];
        yield 'a value that is no record' => [['nine'], 'returned string at the place of id "9"'];
    }

    /**
     * @param array<mixed> $batch
     */
    #[DataProvider('brokenRelatedResults')]
    public function testARelatedLoaderThatBreaksTheBatchRuleIsRefused(array $batch, string $message): void
    {
        $related = self::createStub(RelatedLoader::class);
        $related->method('loadRelated')->willReturn($batch);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        self::loaderWith([ResourceLoader::class => self::store(), RelatedLoader::class => $related])->load(
            self::graph('orders'),
            [self::record('pets', new Pet('1', 'one', '9'))],
            self::context(includeSupplied: true),
        );
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function brokenRelatedResults(): iterable
    {
        yield 'no list for the one parent' => [[], 'returned 0 entries for a request of 1'];
        yield 'the list keyed by its parent' => [
            ['1' => [new Pet('5', 'five')]],
            'returned an array keyed by other than its positions',
        ];
        yield 'a parent that carries no list' => [['five'], 'returned string for parent "1"'];
        yield 'a parent whose records are keyed by their ids' => [
            [['5' => new Pet('5', 'five')]],
            'returned an array keyed by other than its positions for parent "1"',
        ];
        yield 'more records than the reading asked for' => [
            [[new Pet('5', 'five'), new Pet('6', 'six'), new Pet('7', 'seven'), new Pet('8', 'eight')]],
            'returned 4 records for parent "1", and the reading asks for 3 at most',
        ];
        yield 'a value that is no record' => [[['five']], 'returned string among the records of parent "1"'];
    }

    public function testTheParentOfTheRelationshipRouteIsNotRenderedAndCarriesNoLinkage(): void
    {
        $store = self::store();

        $graph = self::loader($store)->loadFromRelationship(
            self::graph('orders'),
            'pets',
            'orders',
            [self::record('orders', new Pet('5', 'five', '9'))],
            self::context(includeSupplied: true, record: new Pet('1', 'one', '9')),
        );

        self::assertNull($graph->linkage('pets', '1', 'orders'));
        self::assertNull($graph->linkage('pets', '1', 'tags'));
        self::assertNull($graph->linkage('pets', '1', 'owner'));
        self::assertSame([], $store->relations);
    }

    public function testTheMembersOfTheLinkageJoinIncludedAndCarryTheirOwnLinkage(): void
    {
        $graph = self::loader(self::store())->loadFromRelationship(
            self::graph('orders'),
            'pets',
            'orders',
            [self::record('orders', new Pet('5', 'five', '9'))],
            self::context(includeSupplied: true, record: new Pet('1', 'one', '9')),
        );

        self::assertSame(['orders/5'], self::pairs($graph));
        self::assertSame('9', self::linkageOf($graph, 'orders', '5', 'seller')->record()?->id);
    }

    public function testAnIncludeThatNamesNoPathLeavesTheRelatedResourcesOut(): void
    {
        $store = self::store();

        $graph = self::loader($store)->loadFromRelationship(
            self::graph(''),
            'pets',
            'orders',
            [self::record('orders', new Pet('5', 'five', '9'))],
            self::context(includeSupplied: true, record: new Pet('1', 'one', '9')),
        );

        self::assertTrue($graph->isCompound());
        self::assertSame([], $graph->included());
        self::assertNull($graph->linkage('orders', '5', 'seller'));
        self::assertSame([], $store->loads);
    }

    public function testAPathBeyondTheRelationshipOfTheRouteIsWalkedFromItsMembers(): void
    {
        $store = self::store(['customers' => ['9' => new Pet('9', 'nine')]]);

        $graph = self::loader($store)->loadFromRelationship(
            self::graph('orders.seller'),
            'pets',
            'orders',
            [self::record('orders', new Pet('5', 'five', '9'))],
            self::context(includeSupplied: true, record: new Pet('1', 'one', '9')),
        );

        self::assertSame(['orders/5', 'customers/9'], self::pairs($graph));
        self::assertSame('9', self::linkageOf($graph, 'orders', '5', 'seller')->record()?->id);
    }

    public function testTheParentIsNeverWrittenIntoIncludedByARelationshipThatPointsBackAtIt(): void
    {
        $graph = self::loader(self::store())->loadFromRelationship(
            self::graph('orders'),
            'pets',
            'orders',
            [self::record('orders', new Pet('5', 'five', '1'))],
            self::context(includeSupplied: true, record: new Pet('1', 'one', '9')),
        );

        self::assertSame(['orders/5'], self::pairs($graph));
        self::assertSame('1', self::linkageOf($graph, 'orders', '5', 'pet')->record()?->id);
    }

    public function testALinkageThatNamesOneIdentifierTwiceJoinsIncludedOnce(): void
    {
        $member = self::record('orders', new Pet('5', 'five', '9'));

        $graph = self::loader(self::store())->loadFromRelationship(
            self::graph('orders'),
            'pets',
            'orders',
            [$member, $member],
            self::context(includeSupplied: true, record: new Pet('1', 'one', '9')),
        );

        self::assertSame(['orders/5'], self::pairs($graph));
    }

    public function testTheWalkOfTheRelationshipRouteRunsInTheLanguagesOfTheRequest(): void
    {
        $store = self::store(['customers' => ['9' => new Pet('9', 'nine')]]);
        $languages = self::languages();

        self::loader($store)->loadFromRelationship(
            self::graph('orders.seller', languages: $languages),
            'pets',
            'orders',
            [self::record('orders', new Pet('5', 'five', '9'))],
            self::context(includeSupplied: true, record: new Pet('1', 'one', '9')),
        );

        self::assertCount(1, $store->loads);
        self::assertSame('customers', $store->loads[0]->type);
        self::assertSame($languages, $store->loads[0]->languages);
    }

    public function testTheWalkOfTheRelationshipRouteNeedsTheLoadedParent(): void
    {
        $this->expectException(LogicException::class);

        self::loader(self::store())->loadFromRelationship(
            self::graph('orders'),
            'pets',
            'orders',
            [],
            self::context(includeSupplied: true),
        );
    }

    public function testARelationshipTheParentDoesNotDeclareIsRefused(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('type "pets" declares no relationship "breeders"');

        self::loader(self::store())->loadFromRelationship(
            self::graph(null),
            'pets',
            'breeders',
            [],
            self::context(record: new Pet('1', 'one', '9')),
        );
    }

    public function testTheMonitorCarriesTheLinkageOfItsRelationshipsAndNoIncludedResource(): void
    {
        $graph = self::loader(self::store())->loadMonitor(
            new LanguagePreferences([]),
            self::record('pets', new Pet('7', 'seven', '9')),
            self::context(includeSupplied: true),
        );

        self::assertSame('9', self::linkageOf($graph, 'pets', '7', 'owner')->record()?->id);
        self::assertSame([], $graph->included());
        self::assertTrue($graph->isCompound());
    }

    /**
     * `tags` and `vet` are always linked, so the monitor's walk reads both
     */
    public function testTheWalkOfTheMonitorReadsInTheLanguagesItIsGiven(): void
    {
        $store = self::store();
        $languages = self::languages();

        self::loader($store)->loadMonitor(
            $languages,
            self::record('pets', new Pet('7', 'seven', '9')),
            self::context(),
        );

        self::assertSame($languages, self::relatedLoadRequest($store, 'tags')->languages);
        self::assertSame($languages, self::relatedLoadRequest($store, 'vet')->languages);
    }

    public function testTheLoaderIsInternalAndImmutable(): void
    {
        $loader = new ReflectionClass(IncludeLoader::class);

        self::assertStringContainsString('@internal', (string) $loader->getDocComment());
        self::assertTrue($loader->isFinal());
        self::assertTrue($loader->isReadOnly());
    }

    private static function linkageOf(RecordGraph $graph, string $type, string $id, string $name): RecordLinkage
    {
        $linkage = $graph->linkage($type, $id, $name);

        self::assertInstanceOf(RecordLinkage::class, $linkage);

        return $linkage;
    }

    /**
     * @return list<string>
     */
    private static function pairs(RecordGraph $graph): array
    {
        return array_map(
            static function (ResourceRecord $resource): string {
                return $resource->type . '/' . $resource->id;
            },
            $graph->included(),
        );
    }

    private static function relatedLoadRequest(RecordingStore $store, string $relationship): RelatedLoadRequest
    {
        $found = [];

        foreach ($store->relations as $request) {
            if ($request->relationship === $relationship) {
                $found[] = $request;
            }
        }

        self::assertCount(1, $found);

        return $found[0];
    }

    private static function record(string $type, Pet $pet): ResourceRecord
    {
        return ResourceRecord::of(self::registry()->get($type), $pet);
    }

    /**
     * the include paths start at `pets`; on the relationship route they start with `orders`
     */
    private static function graph(
        ?string $include = null,
        ?SparseFieldsets $fields = null,
        ?LanguagePreferences $languages = null,
    ): GraphRequest {
        return new GraphRequest(
            WrittenInclude::paths($include),
            $fields ?? new SparseFieldsets([]),
            $languages ?? new LanguagePreferences([]),
        );
    }

    private static function languages(): LanguagePreferences
    {
        return new LanguagePreferences([new LanguageRange('uk')]);
    }

    private static function loader(RecordingStore $store): IncludeLoader
    {
        return self::loaderWith([ResourceLoader::class => $store, RelatedLoader::class => $store]);
    }

    /**
     * @param array<class-string, object> $implementations
     */
    private static function loaderWith(array $implementations): IncludeLoader
    {
        $registry = self::registry();
        $resolver = self::resolver($implementations);

        $store = new StoreGateway($registry, $resolver);

        return new IncludeLoader($registry, $store, new Paginator($registry, $store));
    }

    /**
     * @param array<array-key, array<array-key, Pet>>       $records
     * @param array<array-key, array<array-key, list<Pet>>> $members
     */
    private static function store(array $records = [], array $members = []): RecordingStore
    {
        return new RecordingStore(records: $records, members: $members);
    }

    /**
     * @param array<class-string, object> $implementations
     */
    private static function resolver(array $implementations): ImplementationResolver
    {
        return new class ($implementations) implements ImplementationResolver {
            /**
             * @param array<class-string, object> $implementations
             */
            public function __construct(private readonly array $implementations) {}

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
                $implementation = $this->implementations[$port]
                    ?? throw new LogicException('no implementation is bound for the port');

                if (!$implementation instanceof $port) {
                    throw new UnexpectedValueException('the implementation is no instance of the port');
                }

                return $implementation;
            }
        };
    }

    private static function context(
        bool $includeSupplied = false,
        ?Pet $record = null,
    ): ExecutionContext {
        return new ExecutionContext(
            new ServerRequest('GET', new Headers([]), [], [], ''),
            MediaType::jsonApi(),
            null,
            $includeSupplied,
            false,
            [],
            $record,
        );
    }

    /**
     * every record is a `Pet`,
     * and its `ownerId` serves every to-one relationship that reads its related id off the record;
     * `breeder` and `vet` read through the related loader instead.
     * `orders.pet` points back at the parent of the relationship route,
     * `customers.pets` leads back to the primary data,
     * and `customers.owner` points at its own type
     */
    private static function registry(): ResourceRegistry
    {
        $pets = Samples::resource(
            'pets',
            attributes: [Samples::attribute('name', sortable: true)],
            relationships: [
                Samples::toOne('owner', 'customers'),
                Samples::toMany('orders', 'orders', paginationPolicy: self::policy()),
                Samples::toMany(
                    'tags',
                    'tags',
                    linkageAlwaysPresent: true,
                    defaultSort: Sort::fromString('-name'),
                    paginationPolicy: self::policy(),
                ),
                Samples::toOne('breeder', 'customers', readsRelatedId: false),
                Samples::toOne('vet', 'customers', readsRelatedId: false, linkageAlwaysPresent: true),
            ],
            capabilities: [Capability::RelatedLoader, Capability::CollectionReader],
            maxIncludeDepth: 2,
        );
        $orders = Samples::resource(
            'orders',
            relationships: [Samples::toOne('seller', 'customers'), Samples::toOne('pet', 'pets')],
            capabilities: [Capability::CollectionReader],
        );
        $customers = Samples::resource(
            'customers',
            relationships: [
                Samples::toMany('pets', 'pets', paginationPolicy: self::policy()),
                Samples::toMany('tagged', 'tags', paginationPolicy: self::policy()),
                Samples::toOne('owner', 'customers'),
            ],
            capabilities: [Capability::RelatedLoader],
        );
        $tags = Samples::resource(
            'tags',
            attributes: [Samples::attribute('name', sortable: true)],
            capabilities: [Capability::CollectionReader],
        );

        return new ResourceRegistry([$pets, $orders, $customers, $tags]);
    }

    private static function policy(): PaginationPolicy
    {
        return new PaginationPolicy(2, 100);
    }
}
