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

namespace Polunich\WpJsonApi\Tests\Unit\OpenApi;

use InvalidArgumentException;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Codec\ConstCodec;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\ListCodec;
use Polunich\WpJsonApi\Codec\ObjectCodec;
use Polunich\WpJsonApi\Codec\OneOfCodec;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Codec\UuidCodec;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Error\StatusSelector;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\AttributeMetadata;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\Cardinality;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\LanguageSourceMetadata;
use Polunich\WpJsonApi\Metadata\LanguageSources;
use Polunich\WpJsonApi\Metadata\OperatorMetadata;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\WriteRule;
use Polunich\WpJsonApi\OpenApi\DocumentSchemaBuilder;
use Polunich\WpJsonApi\OpenApi\ParameterBuilder;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Parsing\SortParser;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use ReflectionClass;
use stdClass;

/**
 * @internal
 */
#[CoversClass(ParameterBuilder::class)]
final class ParameterBuilderTest extends TestCase
{
    private const PAGE = [
        'page[number]',
        'page[size]',
        'page[offset]',
        'page[limit]',
        'page[after]',
        'page[before]',
        'page[with_count]',
    ];

    public function testTheClassIsFinalAndInternal(): void
    {
        $class = new ReflectionClass(ParameterBuilder::class);

        self::assertTrue($class->isFinal());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('routes')]
    public function testEachKindReadsTheParametersOfItsRoute(
        OperationKind $kind,
        ?string $relationship,
        array $expected,
    ): void {
        $builder = new ParameterBuilder(self::petstore());

        self::assertSame($expected, self::names($builder->parameters($kind, 'pets', $relationship)));
    }

    /**
     * @return iterable<string, array{OperationKind, ?string, list<string>}>
     */
    public static function routes(): iterable
    {
        $fields = ['fields[pets]', 'fields[customers]', 'fields[orders]'];

        yield 'the collection route reads all five' => [
            OperationKind::FetchCollection,
            null,
            array_merge(['filter', 'sort'], self::PAGE, ['include'], $fields),
        ];

        yield 'the resource route reads include and fields' => [
            OperationKind::FetchResource,
            null,
            array_merge(['include'], $fields),
        ];

        yield 'a create reads include and fields' => [
            OperationKind::CreateResource,
            null,
            array_merge(['include'], $fields),
        ];

        yield 'an update reads include and fields' => [
            OperationKind::UpdateResource,
            null,
            array_merge(['include'], $fields),
        ];

        yield 'a delete reads none' => [OperationKind::DeleteResource, null, []];

        yield 'the related route of a to-many relationship reads all five' => [
            OperationKind::FetchRelated,
            'orders',
            array_merge(['filter', 'sort'], self::PAGE, ['include', 'fields[orders]', 'fields[groomers]']),
        ];

        yield 'the related route of a to-one relationship reads include and fields' => [
            OperationKind::FetchRelated,
            'owner',
            ['include', 'fields[customers]'],
        ];

        yield 'the relationship route of a to-many relationship reads page, include and fields' => [
            OperationKind::FetchRelationship,
            'orders',
            array_merge(self::PAGE, ['include', 'fields[orders]']),
        ];

        yield 'the relationship route of a to-one relationship reads include and fields' => [
            OperationKind::FetchRelationship,
            'owner',
            ['include', 'fields[customers]'],
        ];

        yield 'a relationship write reads include and fields and no page' => [
            OperationKind::ReplaceRelationship,
            'owner',
            ['include', 'fields[customers]'],
        ];

        yield 'an addition reads include and fields' => [
            OperationKind::AddToRelationship,
            'orders',
            ['include', 'fields[orders]'],
        ];

        yield 'a removal reads include and fields' => [
            OperationKind::RemoveFromRelationship,
            'orders',
            ['include', 'fields[orders]'],
        ];
    }

    public function testAKindAndARelationshipThatDoNotBelongTogetherAreRefused(): void
    {
        $builder = new ParameterBuilder(self::petstore());

        try {
            $builder->parameters(OperationKind::FetchRelationship, 'pets');
            self::fail('a relationship route without a relationship is refused');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('names its relationship', $exception->getMessage());
        }

        try {
            $builder->parameters(OperationKind::FetchCollection, 'pets', 'orders');
            self::fail('a resource route with a relationship is refused');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('names no relationship', $exception->getMessage());
        }

        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('type "pets" declares no relationship "buyers"');

        $builder->parameters(OperationKind::FetchRelated, 'pets', 'buyers');
    }

    /**
     * `orders` is to-many, so no sort path runs through it
     */
    public function testTheSortEnumHoldsEverySortablePathAscendingAndDescending(): void
    {
        $parameters = (new ParameterBuilder(self::petstore()))->parameters(OperationKind::FetchCollection, 'pets');
        $sort = self::parameter($parameters, 'sort');

        self::assertSame('form', $sort['style']);
        self::assertFalse($sort['explode']);
        self::assertSame(
            ['id', '-id', 'name', '-name', 'views', '-views', 'owner.id', '-owner.id', 'owner.name', '-owner.name'],
            self::at($sort['schema'], 'items', 'enum'),
        );
    }

    public function testTheSortParameterCarriesTheDeclaredDefaultSort(): void
    {
        $builder = new ParameterBuilder(self::petstore());
        $collection = self::parameter($builder->parameters(OperationKind::FetchCollection, 'pets'), 'sort');
        $related = self::parameter($builder->parameters(OperationKind::FetchRelated, 'pets', 'orders'), 'sort');

        self::assertSame(['-name'], self::at($collection['schema'], 'default'));
        self::assertSame(['-address'], self::at($related['schema'], 'default'));
    }

    public function testEveryPathOfTheDeclaredDefaultSortIsAValueOfTheSortEnum(): void
    {
        $builder = new ParameterBuilder(self::petstore());
        $collection = self::parameter($builder->parameters(OperationKind::FetchCollection, 'pets'), 'sort');
        $related = self::parameter($builder->parameters(OperationKind::FetchRelated, 'pets', 'orders'), 'sort');

        foreach ([$collection, $related] as $sort) {
            $enum = self::at($sort['schema'], 'items', 'enum');
            $default = self::at($sort['schema'], 'default');

            self::assertIsArray($enum);
            self::assertIsArray($default);
            self::assertNotSame([], $default);

            foreach ($default as $path) {
                self::assertContains($path, $enum);
            }
        }
    }

    /**
     * an include depth of 2 does not lengthen a sort path:
     * the enum lacks `owner.trainer.name`, and the parser refuses it.
     * `bio` is not sortable, so both leave out `owner.bio` as well
     */
    public function testTheSortEnumNamesTheSetTheParserSortsBy(): void
    {
        $pets = Samples::resource(
            'pets',
            [Samples::attribute('name', null, true)],
            [Samples::toOne('owner', 'customers', true, false)],
            [Capability::CollectionReader],
            maxIncludeDepth: 2,
        );
        $customers = Samples::resource(
            'customers',
            [Samples::attribute('name', null, true), Samples::attribute('bio')],
            [Samples::toOne('trainer', 'customers', true, false)],
        );
        $api = self::api([$pets, $customers]);
        $sort = self::parameter(
            (new ParameterBuilder($api))->parameters(OperationKind::FetchCollection, 'pets'),
            'sort',
        );
        $enum = self::at($sort['schema'], 'items', 'enum');
        $parser = new SortParser($api->resources);

        self::assertSame(['id', '-id', 'name', '-name', 'owner.id', '-owner.id', 'owner.name', '-owner.name'], $enum);
        self::assertStringContainsString('the enum lists every path the route sorts by', self::text($sort));

        foreach ($enum as $field) {
            $errors = new ErrorList(new StatusSelector());

            self::assertSame($field, $parser->parse($field, 'pets', Strategy::PageNumber, $errors)->toString());
            self::assertNull($errors->exception());
        }

        $errors = new ErrorList(new StatusSelector());

        self::assertTrue($parser->parse('owner.trainer.name', 'pets', Strategy::PageNumber, $errors)->isEmpty());
        self::assertSame(400, $errors->exception()?->status());

        $notSortable = new ErrorList(new StatusSelector());

        self::assertTrue($parser->parse('owner.bio', 'pets', Strategy::PageNumber, $notSortable)->isEmpty());
        self::assertSame(400, $notSortable->exception()?->status());
    }

    /**
     * the related and the relationship route of `orders` read the page policy of the relationship
     */
    public function testThePageParametersCarryTheLimitsTheRouteDeclares(): void
    {
        $builder = new ParameterBuilder(self::petstore());
        $collection = self::parameter($builder->parameters(OperationKind::FetchCollection, 'pets'), 'page[size]');
        $relationship = self::parameter(
            $builder->parameters(OperationKind::FetchRelationship, 'pets', 'orders'),
            'page[limit]',
        );

        $related = self::parameter(
            $builder->parameters(OperationKind::FetchRelated, 'pets', 'orders'),
            'page[size]',
        );

        self::assertSame(1, self::at($collection['schema'], 'minimum'));
        self::assertSame(50, self::at($collection['schema'], 'maximum'));
        self::assertSame(20, self::at($collection['schema'], 'default'));
        self::assertStringContainsString('at most 50, and 20 where it is not sent', self::text($collection));
        self::assertSame(25, self::at($relationship['schema'], 'maximum'));
        self::assertSame(5, self::at($relationship['schema'], 'default'));
        self::assertSame(25, self::at($related['schema'], 'maximum'));
        self::assertSame(5, self::at($related['schema'], 'default'));
    }

    public function testEachPageParameterCarriesTheShapeOfItsStrategy(): void
    {
        $parameters = (new ParameterBuilder(self::petstore()))->parameters(OperationKind::FetchCollection, 'pets');

        self::assertSame(1, self::at(self::parameter($parameters, 'page[number]')['schema'], 'minimum'));
        self::assertSame(0, self::at(self::parameter($parameters, 'page[offset]')['schema'], 'minimum'));
        self::assertSame('string', self::at(self::parameter($parameters, 'page[after]')['schema'], 'type'));
        self::assertSame('string', self::at(self::parameter($parameters, 'page[before]')['schema'], 'type'));
        self::assertSame('boolean', self::at(self::parameter($parameters, 'page[with_count]')['schema'], 'type'));
    }

    public function testTheCamelCasePolicySpellsTheCountParameter(): void
    {
        $parameters = (new ParameterBuilder(self::petstore(naming: NamingPolicy::CamelCase)))->parameters(
            OperationKind::FetchCollection,
            'pets',
        );

        self::assertSame('boolean', self::at(self::parameter($parameters, 'page[withCount]')['schema'], 'type'));
        self::assertNotContains('page[with_count]', array_column($parameters, 'name'));
    }

    public function testTheIncludeEnumHoldsThePathsWithinTheMaxIncludeDepth(): void
    {
        $shallow = (new ParameterBuilder(self::petstore()))->parameters(OperationKind::FetchCollection, 'pets');
        $deep = (new ParameterBuilder(self::petstore(2)))->parameters(OperationKind::FetchCollection, 'pets');

        self::assertSame(
            ['owner', 'orders'],
            self::at(self::parameter($shallow, 'include')['schema'], 'items', 'enum'),
        );
        self::assertSame(
            ['owner', 'orders', 'orders.groomer'],
            self::at(self::parameter($deep, 'include')['schema'], 'items', 'enum'),
        );
    }

    /**
     * the primary data are identifiers, so only the included types get `fields[TYPE]`
     */
    public function testARelationshipRouteWritesItsIncludePathsFromTheParentType(): void
    {
        $shallow = (new ParameterBuilder(self::petstore()))->parameters(
            OperationKind::FetchRelationship,
            'pets',
            'orders',
        );
        $deep = (new ParameterBuilder(self::petstore(2)))->parameters(
            OperationKind::FetchRelationship,
            'pets',
            'orders',
        );

        self::assertSame(['orders'], self::at(self::parameter($shallow, 'include')['schema'], 'items', 'enum'));
        self::assertSame(['fields[orders]'], array_values(array_filter(
            self::names($shallow),
            static function (string $name): bool {
                return str_starts_with($name, 'fields[');
            },
        )));
        self::assertSame(
            ['orders', 'orders.groomer'],
            self::at(self::parameter($deep, 'include')['schema'], 'items', 'enum'),
        );
        self::assertSame(['fields[orders]', 'fields[groomers]'], array_values(array_filter(
            self::names($deep),
            static function (string $name): bool {
                return str_starts_with($name, 'fields[');
            },
        )));
    }

    public function testAFieldsParameterNamesTheFieldsOfItsType(): void
    {
        $parameters = (new ParameterBuilder(self::petstore()))->parameters(OperationKind::FetchCollection, 'pets');
        $fields = self::parameter($parameters, 'fields[pets]');

        self::assertSame('form', $fields['style']);
        self::assertFalse($fields['explode']);
        self::assertSame(
            ['name', 'views', 'draft', 'owner', 'orders'],
            self::at($fields['schema'], 'items', 'enum'),
        );
    }

    public function testAWriteAlsoReadsTheFieldsOfItsMonitorType(): void
    {
        $pets = Samples::resource(
            'pets',
            [Samples::attribute('name')],
            capabilities: [Capability::ResourceCreator],
            monitorTypes: [OperationKind::CreateResource->value => 'jobs'],
        );
        $builder = new ParameterBuilder(self::api([$pets, Samples::resource('jobs', [Samples::attribute('state')])]));

        self::assertSame(
            ['include', 'fields[pets]', 'fields[jobs]'],
            self::names($builder->parameters(OperationKind::CreateResource, 'pets')),
        );
        self::assertSame(
            ['include', 'fields[pets]'],
            self::names($builder->parameters(OperationKind::FetchResource, 'pets')),
        );
    }

    public function testARelationshipWriteAlsoReadsTheFieldsOfItsMonitorType(): void
    {
        $pets = Samples::resource(
            'pets',
            [Samples::attribute('name')],
            [Samples::toOne('owner', 'customers', true, false, [RelationshipUpdate::Replace])],
            [Capability::RelationshipReplacer],
            monitorTypes: [OperationKind::ReplaceRelationship->value => 'jobs'],
        );
        $builder = new ParameterBuilder(self::api([
            $pets,
            Samples::resource('customers', [Samples::attribute('name')]),
            Samples::resource('jobs', [Samples::attribute('state')]),
        ]));

        self::assertSame(
            ['include', 'fields[customers]', 'fields[jobs]'],
            self::names($builder->parameters(OperationKind::ReplaceRelationship, 'pets', 'owner')),
        );
        self::assertSame(
            ['include', 'fields[customers]'],
            self::names($builder->parameters(OperationKind::FetchRelationship, 'pets', 'owner')),
        );
    }

    public function testARouteThatIncludesNothingStillReadsTheEmptyIncludeValue(): void
    {
        $builder = new ParameterBuilder(self::petstore());
        $collection = self::parameter($builder->parameters(OperationKind::FetchCollection, 'groomers'), 'include');
        $related = self::parameter($builder->parameters(OperationKind::FetchRelated, 'pets', 'owner'), 'include');

        foreach ([$collection, $related] as $include) {
            $schema = $include['schema'];

            self::assertInstanceOf(stdClass::class, $schema);
            self::assertSame('array', self::at($schema, 'type'));
            self::assertSame(0, self::at($schema, 'maxItems'));
            self::assertObjectNotHasProperty('items', $schema);
            self::assertStringContainsString('the empty value is the one the route accepts', self::text($include));
        }
    }

    public function testARelationshipRouteThatForbidsInclusionReadsNoFields(): void
    {
        $api = self::api([
            Samples::resource(
                'pets',
                [Samples::attribute('name')],
                [Samples::toMany('watchers', 'customers', false, false)],
                [Capability::RelatedLoader],
            ),
            Samples::resource('customers', [Samples::attribute('name')], [], [Capability::CollectionReader]),
        ]);
        $parameters = (new ParameterBuilder($api))->parameters(OperationKind::FetchRelationship, 'pets', 'watchers');

        self::assertSame(array_merge(self::PAGE, ['include']), self::names($parameters));
        self::assertSame(0, self::at(self::parameter($parameters, 'include')['schema'], 'maxItems'));
    }

    public function testTheFilterParameterIsADeepObjectThatReferencesTheFilterSchema(): void
    {
        $builder = new ParameterBuilder(self::petstore());
        $filter = self::parameter($builder->parameters(OperationKind::FetchCollection, 'pets'), 'filter');

        self::assertSame('deepObject', $filter['style']);
        self::assertTrue($filter['explode']);
        self::assertSame('#/components/schemas/pets.filter', self::at($filter['schema'], '$ref'));
        self::assertStringContainsString('filter[@or][0][city][@eq]=Kyiv', self::text($filter));
        self::assertStringContainsString('reaches at most 1 relationship deep', self::text($filter));
        self::assertStringContainsString(
            'reaches at most 2 relationships deep',
            self::text(self::parameter(
                (new ParameterBuilder(self::petstore(2)))->parameters(OperationKind::FetchCollection, 'pets'),
                'filter',
            )),
        );
    }

    public function testTheFilterSchemaHoldsTheLogicalOperatorsTheFieldsAndTheRelationships(): void
    {
        $schemas = (new ParameterBuilder(self::petstore()))->schemas();

        self::assertSame(
            [
                'pets.filter',
                'pets.name.condition',
                'pets.views.condition',
                'customers.filter',
                'customers.name.condition',
                'orders.filter',
                'orders.address.condition',
                'groomers.filter',
            ],
            array_keys($schemas),
        );
        self::assertArrayHasKey('pets.filter', $schemas);
        self::assertSame(
            ['@and', '@or', '@not', 'name', 'views', 'owner', 'orders'],
            self::members($schemas['pets.filter'], 'properties'),
        );
        self::assertFalse(self::at($schemas['pets.filter'], 'additionalProperties'));
        self::assertSame(
            '#/components/schemas/customers.filter',
            self::at($schemas['pets.filter'], 'properties', 'owner', '$ref'),
        );
    }

    /**
     * the member of a relationship references the filter object of the related type,
     * which holds the relationships of that type in turn; no key of the schema holds a dot
     */
    public function testAConditionThroughARelationshipIsWrittenNestedWhateverTheIncludeDepth(): void
    {
        $schemas = (new ParameterBuilder(self::petstore(2)))->schemas();

        self::assertSame(
            ['@and', '@or', '@not', 'name', 'views', 'owner', 'orders'],
            self::members($schemas['pets.filter'], 'properties'),
        );
        self::assertSame(
            '#/components/schemas/orders.filter',
            self::at($schemas['pets.filter'], 'properties', 'orders', '$ref'),
        );
        self::assertSame(
            '#/components/schemas/groomers.filter',
            self::at($schemas['orders.filter'], 'properties', 'groomer', '$ref'),
        );
        self::assertSame(
            '#/components/schemas/customers.name.condition',
            self::at($schemas['customers.filter'], 'properties', 'name', '$ref'),
        );
    }

    /**
     * the id is a member of the filter object of each type that declares operators for it,
     * so a relationship that points at the type reaches it through the nested object
     */
    public function testTheFilterSchemaHoldsTheIdOfATypeThatDeclaresOperatorsForIt(): void
    {
        $pets = Samples::resource(
            'pets',
            [Samples::attribute('name', null, true, ['@eq'])],
            [Samples::toOne('owner', 'customers'), Samples::toMany('orders', 'orders')],
            [Capability::CollectionReader, Capability::RelatedLoader],
            id: Samples::id(['@eq', '@in']),
        );
        $customers = Samples::resource('customers', id: Samples::id(['@eq'], new UuidCodec()));
        $orders = Samples::resource(
            'orders',
            [Samples::attribute('address', null, true, ['@eq'])],
            capabilities: [Capability::CollectionReader],
        );
        $schemas = (new ParameterBuilder(self::api([$pets, $customers, $orders])))->schemas();

        self::assertSame(
            [
                'pets.filter',
                'pets.id.condition',
                'pets.name.condition',
                'customers.filter',
                'customers.id.condition',
                'orders.filter',
                'orders.address.condition',
            ],
            array_keys($schemas),
        );
        self::assertSame(
            ['@and', '@or', '@not', 'id', 'name', 'owner', 'orders'],
            self::members($schemas['pets.filter'], 'properties'),
        );
        self::assertSame(
            '#/components/schemas/pets.id.condition',
            self::at($schemas['pets.filter'], 'properties', 'id', '$ref'),
        );
        self::assertSame(
            '#/components/schemas/customers.id.condition',
            self::at($schemas['customers.filter'], 'properties', 'id', '$ref'),
        );
        self::assertSame(['@not', '@eq', '@in'], self::members($schemas['pets.id.condition'], 'properties'));
        self::assertEquals(
            (new DigitsCodec())->schema()->toObject(),
            self::at($schemas['pets.id.condition'], 'properties', '@eq'),
        );
        self::assertEquals(
            (new UuidCodec())->schema()->toObject(),
            self::at($schemas['customers.id.condition'], 'properties', '@eq'),
        );
        self::assertSame(
            'the conditions on the id of type "pets"; an operator it does not declare gets 400',
            self::at($schemas['pets.id.condition'], 'description'),
        );
    }

    /**
     * a built-in operator as the policy spells it, a custom one as declared
     */
    public function testTheCamelCasePolicySpellsTheBuiltInOperators(): void
    {
        $views = (new ParameterBuilder(self::petstore(
            naming: NamingPolicy::CamelCase,
        )))->schemas()['pets.views.condition'];

        self::assertSame(
            ['@not', '@between', '@in', '@notIn', '@null', '@near', '@nearBy'],
            self::members($views, 'properties'),
        );
    }

    public function testTheConditionsOfAFieldAreItsDeclaredOperatorsWithTheirValueCodecs(): void
    {
        $schemas = (new ParameterBuilder(self::petstore()))->schemas();
        $name = $schemas['pets.name.condition'];
        $views = $schemas['pets.views.condition'];

        self::assertSame(['@not', '@eq', '@contains'], self::members($name, 'properties'));
        self::assertSame(
            '#/components/schemas/pets.name.condition',
            self::at($name, 'properties', '@not', '$ref'),
        );
        self::assertSame('string', self::at($name, 'properties', '@eq', 'type'));
        self::assertSame('string', self::at($name, 'properties', '@contains', 'type'));
        self::assertSame(
            ['@not', '@between', '@in', '@not_in', '@null', '@near', '@nearBy'],
            self::members($views, 'properties'),
        );
        self::assertSame('array', self::at($views, 'properties', '@between', 'type'));
        self::assertSame('integer', self::at($views, 'properties', '@between', 'items', 'type'));
        self::assertSame(2, self::at($views, 'properties', '@between', 'minItems'));
        self::assertSame(2, self::at($views, 'properties', '@between', 'maxItems'));
        self::assertSame('array', self::at($views, 'properties', '@in', 'type'));
        self::assertSame('boolean', self::at($views, 'properties', '@null', 'type'));
        self::assertSame('string', self::at($views, 'properties', '@near', 'type'));
    }

    /**
     * `groomers` declares no operator and no relationship
     */
    public function testARouteThatFiltersNothingCarriesNoFilterParameter(): void
    {
        $builder = new ParameterBuilder(self::petstore());
        $names = self::names($builder->parameters(OperationKind::FetchCollection, 'groomers'));

        self::assertNotContains('filter', $names);
        self::assertSame(array_merge(['sort'], self::PAGE, ['include', 'fields[groomers]']), $names);
        self::assertSame(
            ['id', '-id'],
            self::at(
                self::parameter($builder->parameters(OperationKind::FetchCollection, 'groomers'), 'sort')['schema'],
                'items',
                'enum',
            ),
        );
    }

    public function testTheAtomicRouteReadsTheFieldsOfEveryType(): void
    {
        $builder = new ParameterBuilder(self::petstore());

        self::assertSame(
            ['fields[pets]', 'fields[customers]', 'fields[orders]', 'fields[groomers]'],
            self::names($builder->atomicParameters()),
        );
    }

    public function testEveryReferenceNamesASchemaOfTheContract(): void
    {
        $api = self::petstore();
        $builder = new ParameterBuilder($api);
        $schemas = array_merge((new DocumentSchemaBuilder($api))->schemas(), $builder->schemas());
        $references = self::references($builder->schemas());

        foreach ([
            OperationKind::FetchCollection,
            OperationKind::FetchResource,
            OperationKind::CreateResource,
        ] as $kind) {
            $references = array_merge($references, self::references($builder->parameters($kind, 'pets')));
        }

        self::assertNotSame([], $references);

        foreach ($references as $reference) {
            self::assertStringStartsWith('#/components/schemas/', $reference);
            self::assertArrayHasKey(substr($reference, strlen('#/components/schemas/')), $schemas);
        }
    }

    public function testTheParametersComeOutTheSameOnEveryBuild(): void
    {
        $first = (new ParameterBuilder(self::petstore()))->parameters(OperationKind::FetchCollection, 'pets');
        $second = (new ParameterBuilder(self::petstore()))->parameters(OperationKind::FetchCollection, 'pets');

        self::assertSame(
            json_encode($first, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            json_encode($second, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * the declared parameters in their order,
     * then `include` and `fields` of the types the response reaches
     */
    public function testAnEndpointReadsItsDeclaredParametersThenIncludeAndFields(): void
    {
        $endpoint = self::endpoint([
            new QueryParameterMetadata('listedAfter', new StringCodec(), true),
            new QueryParameterMetadata(
                'priceRange',
                new ObjectCodec(['min' => new IntegerCodec(), 'max' => new IntegerCodec()]),
                false,
            ),
        ]);

        self::assertSame(
            ['listedAfter', 'priceRange', 'include', 'fields[pets]', 'fields[customers]', 'fields[orders]'],
            self::names((new ParameterBuilder(self::petstore()))->endpointParameters($endpoint)),
        );
    }

    /**
     * a page reads the families of the collection route of its type, in the same order
     */
    public function testAPageOfAnEndpointReadsFilterSortAndPageBeforeIncludeAndFields(): void
    {
        self::assertSame(
            ['filter', 'sort', ...self::PAGE, 'include', 'fields[pets]', 'fields[customers]', 'fields[orders]'],
            self::names((new ParameterBuilder(self::petstore()))->endpointParameters(Samples::pageEndpoint())),
        );
    }

    public function testAPageOfAnEndpointTakesItsOwnDefaultSortAndPageSizes(): void
    {
        $parameters = (new ParameterBuilder(self::petstore()))->endpointParameters(Samples::pageEndpoint(
            defaultSort: Sort::fromString('views'),
            paginationPolicy: new PaginationPolicy(3, 30),
        ));
        $sort = self::parameter($parameters, 'sort');
        $size = self::parameter($parameters, 'page[size]');

        self::assertSame(['views'], self::at($sort['schema'], 'default'));
        self::assertIsString($sort['description']);
        self::assertStringContainsString('the endpoint sorts by its default sort', $sort['description']);
        self::assertSame(30, self::at($size['schema'], 'maximum'));
        self::assertSame(3, self::at($size['schema'], 'default'));
    }

    public function testAPageOfAnEndpointFallsBackToTheDefaultSortAndPageSizesOfItsType(): void
    {
        $parameters = (new ParameterBuilder(self::petstore()))->endpointParameters(Samples::pageEndpoint());

        self::assertSame(['-name'], self::at(self::parameter($parameters, 'sort')['schema'], 'default'));
        self::assertSame(50, self::at(self::parameter($parameters, 'page[size]')['schema'], 'maximum'));
    }

    /**
     * a generated route lists what its type or its relationship declares for its kind first,
     * as an endpoint lists its own
     */
    public function testARouteReadsItsDeclaredParametersThenTheFamiliesItProcesses(): void
    {
        $api = self::api([
            Samples::resource(
                'pets',
                relationships: [Samples::toMany('orders', 'orders', queryParameters: [
                    OperationKind::FetchRelated->value => [
                        new QueryParameterMetadata('placedSince', new StringCodec(), false),
                    ],
                ])],
                capabilities: [Capability::RelatedLoader, Capability::CollectionReader],
                queryParameters: [
                    OperationKind::FetchResource->value => [
                        new QueryParameterMetadata('previewMode', new StringCodec(), false),
                    ],
                    OperationKind::FetchCollection->value => [
                        new QueryParameterMetadata('nearBy', new StringCodec(), true),
                    ],
                ],
            ),
            Samples::resource('orders', capabilities: [Capability::CollectionReader]),
        ]);
        $builder = new ParameterBuilder($api);

        $resource = $builder->parameters(OperationKind::FetchResource, 'pets');
        $collection = $builder->parameters(OperationKind::FetchCollection, 'pets');

        self::assertSame(['previewMode', 'include', 'fields[pets]', 'fields[orders]'], self::names($resource));
        self::assertEquals(
            [
                'name' => 'previewMode',
                'in' => 'query',
                'description' => 'the query parameter "previewMode" of the fetch_resource route of type "pets"',
                'schema' => (object) ['type' => 'string'],
            ],
            $resource[0],
        );
        self::assertEquals(
            [
                'name' => 'nearBy',
                'in' => 'query',
                'required' => true,
                'description' => 'the query parameter "nearBy" of the fetch_collection route of type "pets", which'
                    . ' every request carries',
                'schema' => (object) ['type' => 'string'],
            ],
            $collection[0],
        );
        self::assertSame(
            'the query parameter "placedSince" of the fetch_related route of relationship "orders" of type "pets"',
            $builder->parameters(OperationKind::FetchRelated, 'pets', 'orders')[0]['description'] ?? null,
        );
        self::assertNotContains('previewMode', self::names($collection));
        self::assertNotContains('nearBy', self::names($resource));
    }

    /**
     * `required` defaults to false (OpenAPI 3.1.2, Parameter Object),
     * so an optional parameter leaves it out
     */
    public function testAScalarParameterKeepsTheFormStyleAndIsRequiredWhereDeclaredSo(): void
    {
        $parameters = (new ParameterBuilder(self::petstore()))->endpointParameters(self::endpoint([
            new QueryParameterMetadata('listedAfter', new StringCodec(), true),
            new QueryParameterMetadata('minAge', new IntegerCodec(), false),
        ]));

        self::assertSame(['name', 'in', 'required', 'description', 'schema'], array_keys($parameters[0]));
        self::assertEquals(
            [
                'name' => 'listedAfter',
                'in' => 'query',
                'required' => true,
                'description' => 'the query parameter "listedAfter" of the endpoint "recentPets", which every request'
                    . ' carries',
                'schema' => (object) ['type' => 'string'],
            ],
            $parameters[0],
        );
        self::assertSame(['name', 'in', 'description', 'schema'], array_keys($parameters[1]));
    }

    public function testAParameterOfObjectShapeIsDescribedWithoutItsReadOnlyMembers(): void
    {
        $codec = new ObjectCodec(
            ['min' => new IntegerCodec(minimum: 0, maximum: 9), 'max' => new IntegerCodec(minimum: 0, maximum: 9)],
            readOnly: ['max'],
        );

        $parameter = (new ParameterBuilder(self::petstore()))->endpointParameters(
            self::endpoint([new QueryParameterMetadata('priceRange', $codec, false)]),
        )[0];

        self::assertEquals($codec->schema()->forRequest()->toObject(), $parameter['schema']);
        self::assertSame('{"type":"object","properties":{"min":{"type":"integer","minimum":0,"maximum":9}},'
            . '"additionalProperties":false}', json_encode($parameter['schema']));
    }

    /**
     * a list or a deeper nesting also names its bracket form in the description
     *
     * @param Codec<mixed> $codec
     */
    #[DataProvider('shapedParameters')]
    public function testAParameterOfObjectShapeIsADeepObject(Codec $codec, bool $nested): void
    {
        $parameter = (new ParameterBuilder(self::petstore()))->endpointParameters(
            self::endpoint([new QueryParameterMetadata(
                'petShape',
                $codec,
                false,
            )]),
        )[0];

        self::assertSame(['name', 'in', 'style', 'explode', 'description', 'schema'], array_keys($parameter));
        self::assertSame('deepObject', $parameter['style']);
        self::assertTrue($parameter['explode']);
        self::assertSame($nested, str_contains(self::text($parameter), 'petShape[]=a&petShape[]=b'));
    }

    /**
     * @return iterable<string, array{Codec<mixed>, bool}>
     */
    public static function shapedParameters(): iterable
    {
        yield 'an object of scalars' => [new ObjectCodec(['min' => new IntegerCodec()]), false];
        yield 'a union of objects' => [
            new OneOfCodec(
                'kind',
                [
                    'cat' => new ObjectCodec(
                        ['kind' => new ConstCodec('cat'), 'lives' => new IntegerCodec()],
                        ['kind'],
                    ),
                    'dog' => new ObjectCodec(['kind' => new ConstCodec('dog')], ['kind']),
                ],
                static function (array $pet): string {
                    return is_string($pet['kind'] ?? null) ? $pet['kind'] : '';
                },
            ),
            false,
        ];
        yield 'a list' => [new ListCodec(new IntegerCodec()), true];
        yield 'an object with a list' => [new ObjectCodec(['ids' => new ListCodec(new IntegerCodec())]), true];
    }

    /**
     * a meta response carries no resource; a monitor carries its own, which `fields` names alone
     *
     * @param array<int, ResponseMetadata> $responses
     * @param list<string>               $names
     */
    #[DataProvider('responsesWithoutIncludes')]
    public function testAnEndpointReadsTheFamiliesItsResponsesCarryResourcesFor(array $responses, array $names): void
    {
        $api = self::api([
            Samples::resource('pets', [Samples::attribute('name')]),
            Samples::resource('jobs', [Samples::attribute('state')]),
        ]);

        self::assertSame(
            $names,
            self::names((new ParameterBuilder($api))->endpointParameters(Samples::endpoint(responses: $responses))),
        );
    }

    /**
     * @return iterable<string, array{array<int, ResponseMetadata>, list<string>}>
     */
    public static function responsesWithoutIncludes(): iterable
    {
        yield 'meta' => [
            [200 => new ResponseMetadata(ResponseShapeKind::Meta, null, null, null, null, new IntegerCodec())],
            [],
        ];
        yield 'a monitor' => [
            [202 => new ResponseMetadata(ResponseShapeKind::Accepted, 'jobs', null, null, null, null)],
            ['fields[jobs]'],
        ];
        yield 'no content' => [
            [204 => new ResponseMetadata(ResponseShapeKind::NoContent, null, null, null, null, null)],
            [],
        ];
    }

    /**
     * the include paths of a linkage start with its relationship,
     * as those of the relationship route do
     */
    public function testALinkageIncludesFromItsRelationship(): void
    {
        $endpoint = Samples::endpoint(
            'petOrders',
            'GET',
            '/pets/{id}/order-ids',
            responses: [200 => new ResponseMetadata(
                ResponseShapeKind::Linkage,
                'orders',
                'id',
                'orders',
                Cardinality::ToMany,
                null,
            )],
        );

        $parameters = (new ParameterBuilder(self::petstore()))->endpointParameters($endpoint);

        self::assertSame(['include', 'fields[orders]'], self::names($parameters));
        self::assertEquals(
            (object) ['type' => 'array', 'items' => (object) ['type' => 'string', 'enum' => ['orders']]],
            $parameters[0]['schema'],
        );
    }

    public function testEachLanguageSourceIsOneReusableParameter(): void
    {
        $parameters = (new ParameterBuilder(self::languageApi(new LanguageSources([
            LanguageSourceMetadata::queryParameter('uiLocale'),
            LanguageSourceMetadata::acceptLanguage(),
        ]))))->languageParameters();

        self::assertSame(['uiLocale.query', 'Accept-Language.header'], array_keys($parameters));
        self::assertEquals(
            (object) [
                'name' => 'uiLocale',
                'in' => 'query',
                'description' => 'the language the client asks for, one basic language range such as uk or en-GB'
                    . ' [primary: RFC 4647 2.1]; an empty value or one of another form gets 400. every link of the'
                    . ' response carries it on as the request wrote it. it takes precedence over Accept-Language',
                'schema' => (object) ['type' => 'string', 'pattern' => '^[A-Za-z]{1,8}(?:-[A-Za-z0-9]{1,8})*$'],
            ],
            $parameters['uiLocale.query'],
        );
        self::assertEquals(
            (object) [
                'name' => 'Accept-Language',
                'in' => 'header',
                'description' => 'the languages the client prefers, by weight [primary: RFC 9110 12.5.4]; a malformed'
                    . ' element is disregarded. it is read only where the request does not carry the query parameter'
                    . ' uiLocale',
                'schema' => (object) ['type' => 'string'],
            ],
            $parameters['Accept-Language.header'],
        );
    }

    public function testAnApiThatNegotiatesNoLanguageDescribesNoLanguageParameter(): void
    {
        self::assertSame([], (new ParameterBuilder(self::languageApi(new LanguageSources([]))))->languageParameters());
    }

    private static function languageApi(LanguageSources $languageSources): ApiMetadata
    {
        $petstore = self::petstore();

        return new ApiMetadata(
            'petstore',
            '1.0',
            'acme/v1',
            $petstore->resources,
            [],
            [],
            $petstore->operators,
            [],
            null,
            $languageSources,
            NamingPolicy::SnakeCase,
            true,
        );
    }

    private static function petstore(
        int $maxIncludeDepth = 1,
        NamingPolicy $naming = NamingPolicy::SnakeCase,
    ): ApiMetadata {
        $pets = Samples::resource(
            'pets',
            [
                new AttributeMetadata(
                    'name',
                    new StringCodec(),
                    static function (object $record): string {
                        return 'a name';
                    },
                    new WriteRule(true, true, true),
                    true,
                    ['@eq', '@contains'],
                    null,
                    false,
                ),
                new AttributeMetadata(
                    'views',
                    new IntegerCodec(),
                    static function (object $record): int {
                        return 0;
                    },
                    WriteRule::readOnly(),
                    true,
                    ['@between', '@in', '@notIn', '@null', '@near', '@nearBy'],
                    null,
                    false,
                ),
                new AttributeMetadata(
                    'draft',
                    new StringCodec(),
                    null,
                    new WriteRule(true, true, false),
                    false,
                    [],
                    null,
                    false,
                ),
            ],
            [
                Samples::toOne('owner', 'customers', true, false, [RelationshipUpdate::Replace]),
                Samples::toMany(
                    'orders',
                    'orders',
                    true,
                    false,
                    [RelationshipUpdate::Add, RelationshipUpdate::Remove],
                    Sort::fromString('-address'),
                    new PaginationPolicy(5, 25),
                ),
            ],
            [
                Capability::CollectionReader,
                Capability::ResourceCreator,
                Capability::ResourceUpdater,
                Capability::ResourceDeleter,
                Capability::RelatedLoader,
                Capability::RelationshipReplacer,
                Capability::RelationshipAdder,
                Capability::RelationshipRemover,
            ],
            defaultSort: Sort::fromString('-name'),
            paginationPolicy: new PaginationPolicy(20, 50),
            maxIncludeDepth: $maxIncludeDepth,
        );
        $customers = Samples::resource('customers', [Samples::attribute('name', null, true, ['@eq'])]);
        $orders = Samples::resource(
            'orders',
            [Samples::attribute('address', null, true, ['@eq'])],
            [Samples::toOne('groomer', 'groomers')],
            [Capability::CollectionReader, Capability::RelatedLoader],
        );
        $groomers = Samples::resource('groomers', [Samples::attribute('name')]);

        return self::api([$pets, $customers, $orders, $groomers], $naming);
    }

    /**
     * @param list<QueryParameterMetadata> $queryParameters
     */
    private static function endpoint(array $queryParameters): EndpointMetadata
    {
        return Samples::endpoint(
            'recentPets',
            'GET',
            '/pets/recent',
            [],
            queryParameters: $queryParameters,
            responses: [200 => new ResponseMetadata(ResponseShapeKind::Collection, 'pets', null, null, null, null)],
        );
    }

    /**
     * @param list<ResourceMetadata> $resources
     */
    private static function api(array $resources, NamingPolicy $naming = NamingPolicy::SnakeCase): ApiMetadata
    {
        return new ApiMetadata(
            'petstore',
            '1.0',
            'acme/v1',
            new ResourceRegistry($resources),
            [],
            [],
            Samples::operators([
                OperatorMetadata::custom('@near', new StringCodec()),
                OperatorMetadata::custom('@nearBy', new StringCodec()),
            ]),
            [],
            null,
            Samples::languageSources(),
            $naming,
            true,
        );
    }

    /**
     * @param list<array<string, mixed>> $parameters
     *
     * @return list<string>
     */
    private static function names(array $parameters): array
    {
        return array_map(
            static function (array $parameter): string {
                self::assertArrayHasKey('name', $parameter);
                self::assertIsString($parameter['name']);

                return $parameter['name'];
            },
            $parameters,
        );
    }

    /**
     * @param list<array<string, mixed>> $parameters
     *
     * @return array<string, mixed>
     */
    private static function parameter(array $parameters, string $name): array
    {
        foreach ($parameters as $parameter) {
            if (($parameter['name'] ?? null) !== $name) {
                continue;
            }

            self::assertSame(['name', 'in', 'description', 'style', 'explode', 'schema'], array_keys($parameter));
            self::assertSame('query', $parameter['in']);

            return $parameter;
        }

        self::fail(sprintf('the route reads the parameter "%s"', $name));
    }

    /**
     * @param array<string, mixed> $parameter
     */
    private static function text(array $parameter): string
    {
        self::assertArrayHasKey('description', $parameter);
        self::assertIsString($parameter['description']);

        return $parameter['description'];
    }

    private static function at(mixed $value, string ...$path): mixed
    {
        foreach ($path as $segment) {
            self::assertInstanceOf(stdClass::class, $value);
            self::assertTrue(
                property_exists($value, $segment),
                sprintf('the schema carries the member "%s"', $segment),
            );
            $value = $value->{$segment};
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function members(mixed $value, string ...$path): array
    {
        $object = self::at($value, ...$path);
        self::assertInstanceOf(stdClass::class, $object);

        return array_map(static function (int|string $name): string {
            return (string) $name;
        }, array_keys(get_object_vars($object)));
    }

    /**
     * @return list<string>
     */
    private static function references(mixed $value): array
    {
        $found = [];

        if (is_array($value)) {
            foreach ($value as $item) {
                $found = array_merge($found, self::references($item));
            }

            return $found;
        }

        if (!$value instanceof stdClass) {
            return [];
        }

        foreach (get_object_vars($value) as $name => $member) {
            if ($name === '$ref') {
                self::assertIsString($member);
                $found[] = $member;

                continue;
            }

            $found = array_merge($found, self::references($member));
        }

        return $found;
    }
}
