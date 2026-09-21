# wp-jsonapi

JSON:API 1.1 on the WordPress REST API. a plugin declares its resources in PHP, and the library
registers the routes, reads and validates every request, calls the plugin's store, writes the
documents and publishes an OpenAPI 3.1.2 contract built from the same declarations.

- the routes of the specification for every declared type: the collection, the resource, the related
  resources and the relationship of each relationship;
- content negotiation with the JSON:API media type, the Atomic Operations extension and the Cursor
  Pagination profile;
- filters with nested `@and`, `@or` and `@not` and 21 comparison operators, sorts through a to-one
  relationship, pagination by page number, by offset and by cursor, `include` and sparse fieldsets;
- creates, updates and deletions with write rules per field, the three updates of a to-many
  relationship, client-generated ids, `ETag` and `If-Match`, and `202 Accepted`;
- atomic operations on the routes the API declares, each at a path of its own and for the types it
  names;
- every error as a JSON:API error document, a 401 only with a challenge that applies to the
  request, and WordPress's own errors on the namespace converted;
- the contract command line, `vendor/bin/wp-jsonapi openapi build` and `check`, and PHPUnit
  assertions that validate a response against the committed contract.

the library never queries storage: the plugin reads and writes its data behind the ports of
`Polunich\WpJsonApi\Store`, and the library hands it the parsed request as values.

## requirements

- PHP 8.3 or newer;
- WordPress 6.4 or newer.

## installation

```sh
composer require polunich/wp-jsonapi
```

a plugin that ships the library prefixes its namespace, with Strauss or PHP-Scoper, so that two
plugins can bundle two versions of it. every file of the library declares a namespace, none declares
a global function or constant, and a class is named with `::class`, never in a string, so a prefixing
tool rewrites every reference.

## a first API

```php
namespace Acme\Petstore;

use DateTimeImmutable;
use Polunich\WpJsonApi\Access\AllowAny;
use Polunich\WpJsonApi\ApiBuilder;
use Polunich\WpJsonApi\Codec\DateTimeCodec;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Declaration\Attribute;
use Polunich\WpJsonApi\Declaration\Implementation;
use Polunich\WpJsonApi\Declaration\ResourceType;
use Polunich\WpJsonApi\Declaration\ToOne;
use Polunich\WpJsonApi\JsonApi;
use Polunich\WpJsonApi\Operation\OperationKind;

final class PetstoreApi
{
    public static function define(): ApiBuilder
    {
        $anyone = Implementation::instance(new AllowAny());
        $pets = Implementation::instance(new PetStore());

        return JsonApi::api(name: 'petstore', version: '1.0.0', restNamespace: 'acme/v1')
            ->resource(
                ResourceType::make('pets')
                    ->id(new DigitsCodec(), static function (Pet $pet): string {
                        return $pet->id;
                    })
                    ->filterId('@eq')
                    ->attribute(
                        Attribute::make('name', new StringCodec(1, 200))
                            ->accessor(static function (Pet $pet): string {
                                return $pet->name;
                            })
                            ->sortable()
                            ->filter('@eq', '@containsi'),
                    )
                    ->attribute(
                        Attribute::make('listedAt', new DateTimeCodec())
                            ->accessor(static function (Pet $pet): DateTimeImmutable {
                                return $pet->listedAt;
                            })
                            ->sortable()
                            ->filter('@gte', '@lt'),
                    )
                    ->relationship(
                        ToOne::make('owner', 'customers')
                            ->relatedId(static function (Pet $pet): ?string {
                                return $pet->ownerId;
                            }),
                    )
                    ->reader($pets)
                    ->loader($pets)
                    ->permission(OperationKind::FetchCollection, $anyone)
                    ->permission(OperationKind::FetchResource, $anyone)
                    ->permission(OperationKind::FetchRelated, $anyone)
                    ->permission(OperationKind::FetchRelationship, $anyone)
                    ->defaultSort('-listedAt'),
            )
            ->resource(
                ResourceType::make('customers')
                    ->id(new DigitsCodec(), static function (Customer $customer): string {
                        return $customer->id;
                    })
                    ->attribute(
                        Attribute::make('name', new StringCodec())
                            ->accessor(static function (Customer $customer): string {
                                return $customer->name;
                            }),
                    )
                    ->loader(Implementation::instance(new CustomerStore()))
                    ->permission(OperationKind::FetchResource, $anyone),
            );
    }
}
```

the records are the plugin's own classes, the objects the accessors read. here a
pet is a post of the post type `pet`, its owner the id in its meta `owner_id`, and a customer a post
of the post type `customer`:

```php
namespace Acme\Petstore;

use DateTimeImmutable;

final readonly class Pet
{
    public function __construct(
        public string $id,
        public string $name,
        public DateTimeImmutable $listedAt,
        public ?string $ownerId,
    ) {}
}

final readonly class Customer
{
    public function __construct(public string $id, public string $name) {}
}
```

the stores implement the ports of [the store](#the-store) the declaration binds: `reader()` a
`CollectionReader`, `loader()` a `ResourceLoader`. the reader turns the filter tree into SQL with a
visitor (see [the filter tree](#the-filter-tree)), and sorts and pages in the same query:

```php
namespace Acme\Petstore;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Polunich\WpJsonApi\Store\CollectionQuery;
use Polunich\WpJsonApi\Store\CollectionReader;
use Polunich\WpJsonApi\Store\LoadRequest;
use Polunich\WpJsonApi\Store\ResourceLoader;
use Polunich\WpJsonApi\Store\Slice;
use WP_Post;

/**
 * @implements CollectionReader<Pet>
 * @implements ResourceLoader<Pet>
 */
final class PetStore implements CollectionReader, ResourceLoader
{
    // `name` compares by its bytes, in the sort and in the filter alike,
    // so the conditions of a cursor agree with the order of the page
    public const COLUMNS = ['id' => 'ID', 'name' => 'CAST(post_title AS BINARY)', 'listedAt' => 'post_date_gmt'];

    public function read(CollectionQuery $query): Slice
    {
        global $wpdb;

        $where = "post_type = 'pet' AND post_status = 'publish'";

        if ($query->filter !== null) {
            $where .= ' AND ' . $query->filter->accept(new PetFilter());
        }

        $order = [];

        foreach ($query->sort->fields() as $field) {
            $order[] = self::COLUMNS[$field->field->toString()] . ' ' . $field->direction->value;
        }

        $ids = $wpdb->get_col(sprintf(
            "SELECT ID FROM {$wpdb->posts} WHERE %s ORDER BY %s LIMIT %d OFFSET %d",
            $where,
            implode(', ', $order),
            $query->window->limit,
            $query->window->offset,
        ));
        $total = $query->totalRequested
            ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE {$where}")
            : null;

        return new Slice(array_values(array_filter(array_map($this->posts($ids), $ids))), $total);
    }

    public function load(LoadRequest $request): array
    {
        return array_map($this->posts($request->ids), $request->ids);
    }

    /**
     * @param list<string> $ids
     *
     * @return Closure(string): ?Pet
     */
    private function posts(array $ids): Closure
    {
        $pets = [];

        if ($ids !== []) {
            $posts = get_posts(['post_type' => 'pet', 'post__in' => array_map('intval', $ids), 'numberposts' => -1]);

            foreach ($posts as $post) {
                $pets[(string) $post->ID] = self::fromPost($post);
            }
        }

        return static function (string $id) use ($pets): ?Pet {
            return $pets[$id] ?? null;
        };
    }

    public static function fromPost(WP_Post $post): Pet
    {
        $ownerId = get_post_meta($post->ID, 'owner_id', true);

        return new Pet(
            (string) $post->ID,
            $post->post_title,
            new DateTimeImmutable($post->post_date_gmt, new DateTimeZone('UTC')),
            is_string($ownerId) && $ownerId !== '' ? $ownerId : null,
        );
    }
}

/**
 * @implements ResourceLoader<Customer>
 */
final class CustomerStore implements ResourceLoader
{
    public function load(LoadRequest $request): array
    {
        $customers = [];
        $posts = get_posts(['post_type' => 'customer', 'post__in' => array_map('intval', $request->ids), 'numberposts' => -1]);

        foreach ($posts as $post) {
            $customers[(string) $post->ID] = new Customer((string) $post->ID, $post->post_title);
        }

        return array_map(static function (string $id) use ($customers): ?Customer {
            return $customers[$id] ?? null;
        }, $request->ids);
    }
}
```

`get_posts()` returns only published posts, so a draft is missing to the client, 404, as the reader
leaves it out of every page. the visitor writes each condition of the filter as SQL:

```php
namespace Acme\Petstore;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Polunich\WpJsonApi\Query\Filter\AllOf;
use Polunich\WpJsonApi\Query\Filter\AnyOf;
use Polunich\WpJsonApi\Query\Filter\Condition;
use Polunich\WpJsonApi\Query\Filter\FilterNode;
use Polunich\WpJsonApi\Query\Filter\Not;
use Polunich\WpJsonApi\Query\Filter\RelationshipCondition;
use Polunich\WpJsonApi\Query\Filter\Visitor;

/**
 * @implements Visitor<string>
 */
final readonly class PetFilter implements Visitor
{
    // the operators the declaration opens, and `@gt` and `@lt` of a cursor
    private const COMPARISONS = ['@eq' => '=', '@gt' => '>', '@gte' => '>=', '@lt' => '<'];

    public function condition(Condition $node): string
    {
        global $wpdb;

        $value = $node->value instanceof DateTimeImmutable
            ? $node->value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
            : $node->value;

        if ($node->operator === '@containsi') {
            return $wpdb->prepare('LOWER(post_title) LIKE LOWER(%s)', '%' . $wpdb->esc_like($value) . '%');
        }

        $column = PetStore::COLUMNS[$node->field->toString()];

        return $wpdb->prepare($column . ' ' . self::COMPARISONS[$node->operator] . ' %s', $value);
    }

    public function allOf(AllOf $node): string
    {
        return '(' . implode(' AND ', $this->each($node->nodes)) . ')';
    }

    public function anyOf(AnyOf $node): string
    {
        return '(' . implode(' OR ', $this->each($node->nodes)) . ')';
    }

    public function not(Not $node): string
    {
        return 'NOT (' . $node->node->accept($this) . ')';
    }

    public function relationshipCondition(RelationshipCondition $node): string
    {
        throw new LogicException('no attribute of a customer opens a filter, so none runs through the owner.');
    }

    /**
     * @param list<FilterNode> $nodes
     *
     * @return list<string>
     */
    private function each(array $nodes): array
    {
        return array_map(function (FilterNode $node): string {
            return $node->accept($this);
        }, $nodes);
    }
}
```

the main file of the plugin registers the API. the library builds it when WordPress first prepares a
REST request, so a request that is no REST request builds nothing:

```php
use Acme\Petstore\PetstoreApi;
use Polunich\WpJsonApi\Api;
use Polunich\WpJsonApi\JsonApi;

JsonApi::register(static function (): Api {
    return PetstoreApi::define()->build();
});
```

a plugin with a PSR-11 container names its stores with `Implementation::service()` instead and
sets the container with `container()` (see [implementations](#implementations)).

`GET /wp-json/acme/v1/pets?filter[listedAt][@gte]=2026-01-01T00:00:00Z&sort=name&include=owner`
then gets a compound document, and `GET /wp-json/acme/v1/pets/1/relationships/owner` the
linkage of one pet. the declaration of an API that uses every feature is
[tests/Fixtures/Api/FixtureApi.php](tests/Fixtures/Api/FixtureApi.php), which the test suites run.
it lies in the repository of the library: the Composer package leaves `tests/` out.

## declaring an API

an API is declared with builders, and the declaration is the one source of both the routes and the
contract. `JsonApi::api()` starts it and returns an `ApiBuilder`; `build()` returns the `Api`, which
writes the contract and which `JsonApi::register()` registers with WordPress.

```php
$builder = JsonApi::api(name: 'petstore', version: '1.0.0', restNamespace: 'acme/v1');
```

- `name` is `info.title` of the contract, and the channel of the default log;
- `version` is `info.version` of the contract, which OpenAPI requires;
- `restNamespace` is the namespace WordPress serves the routes under, with no slash at either end.

### the parts of the API builder

| method | what it declares | default |
|---|---|---|
| `resource(ResourceType)` | a resource type; call it once per type | |
| `get()`, `post()`, `put()`, `patch()`, `delete()` | an endpoint of the plugin, by its path and its handler (see [endpoints](#endpoints)) | none |
| `operator(Operator)` | a custom filter operator | the 21 built-in operators |
| `securitySchemes(SecurityScheme ...)` | the schemes an operation accepts unless it declares its own (see [security schemes](#security-schemes)) | `SecurityScheme::restNonce()`, `SecurityScheme::applicationPasswords()` |
| `atomic(string $path)` | a route of atomic operations (see [atomic operations](#atomic-operations)) | none |
| `transactionBoundary(Implementation)` | the transaction the operations of an atomic request run in, which every atomic route needs | none |
| `container(ContainerInterface)` | the PSR-11 container that creates the implementations named by entry id | none |
| `logger(LoggerInterface)` | where a failure of the library is logged | PHP's `error_log()` |
| `languageSources(LanguageSource ...)` | where the language of a request comes from, in order (see [languages](#languages)) | `LanguageSource::acceptLanguage()` |
| `contentLanguage(ContentLanguage)` | who applies the locale the request asks for | none |
| `errorCatalog(ErrorCatalog)` | the titles and details of errors | the catalog of the library |
| `challengeProvider(ChallengeProvider)` | the challenges of a 401 | `Basic` for Application Passwords |
| `realm(string)` | the realm of the default challenge | `WordPress` |
| `identity(Identity)` | whether the client is authenticated | the current user of WordPress |
| `exceptionMapper(ExceptionMapper)` | a mapper from an exception of the plugin to an error | none |
| `debugOutput(bool)` | whether a 500 carries its exceptions in `meta.exceptions` | `false` |
| `namingPolicy(NamingPolicy)` | how a client spells the names of several words the library defines (see [names](#names)) | `NamingPolicy::SnakeCase` |
| `strictQueryParameterNames(bool)` | whether `build()` refuses a language parameter, or a query parameter of an endpoint or a route, of `a-z` alone, such as `locale` (see [languages](#languages)) | `true` |

every part but `resource()`, the endpoints, the atomic routes, `operator()` and `exceptionMapper()` is
written once, and a second call is refused with `InvalidArgumentException`. `realm()` configures the default
provider and is refused together with `challengeProvider()`.

#### names

the library defines a few names of several words that reach the client: the page member that asks
for the total, the built-in filter operators such as `@not_in`, and the members of `meta.page` from
the Cursor Pagination profile. `Query\NamingPolicy` spells them:

| policy | spelling |
|---|---|
| `NamingPolicy::SnakeCase`, the default | `page[with_count]`, `@not_in`, `meta.page.estimated_total.best_guess`, as WordPress spells `per_page` |
| `NamingPolicy::CamelCase` | `page[withCount]`, `@notIn`, `meta.page.estimatedTotal.bestGuess`, as JSON:API recommends and the profile spells them |

```php
use Polunich\WpJsonApi\Query\NamingPolicy;

$builder->namingPolicy(NamingPolicy::CamelCase);
```

under snake case the members of `meta.page` differ from the names the Cursor Pagination profile
defines, so a client that reads the profile's `estimatedTotal` finds no estimate. the PHP code of
the plugin names the operators in camel case under either policy, `filter('@notIn')` and
`$condition->operator === '@notIn'`, and the names the plugin declares, its types, fields and query
parameters, reach the client as declared.

`resource()` reads a type at once and refuses one that is incomplete in itself, such as a type with
no id or with no permission for an operation it enables. `build()` refuses what spans two types: a
related type that is not declared, a sort path the related type does not have, a to-many
relationship whose related type binds no collection reader, a resource loader bound where nothing
reads through it or missing where something does, and an endpoint that names a type or a relationship
that is not declared or a path another route of its method matches. either way the mistake stops the
declaration, never a request.

### resource types

```php
use Polunich\WpJsonApi\Declaration\ToMany;

$store = Implementation::instance(new PetStore());

ResourceType::make('pets')
    ->id(new DigitsCodec(), static function (Pet $pet): string {
        return $pet->id;
    })
    ->filterId('@eq', '@in')
    ->attribute(
        Attribute::make('name', new StringCodec(1, 200))
            ->accessor(static function (Pet $pet): string {
                return $pet->name;
            })
            ->sortable(),
    )
    ->relationship(
        ToOne::make('owner', 'customers')
            ->relatedId(static function (Pet $pet): ?string {
                return $pet->ownerId;
            }),
    )
    ->relationship(ToMany::make('orders', 'orders'))
    ->reader($store)
    ->loader($store)
    ->relatedLoader($store)
    ->creator($store)
    ->permission(OperationKind::FetchCollection, $anyone)
    ->defaultSort('-listedAt');
```

- `id(SegmentCodec, Closure, bool $globallyUnique = false)` reads and writes the id; every type declares
  it, with `DigitsCodec`, `UuidCodec` or a segment codec of the plugin (see [codecs](#codecs));
- `filterId(string ...$operators)` opens the id to the operators named, as `filter()` opens an
  attribute (see [filter operators](#filter-operators)): `filter[id][@in][]=1&filter[id][@in][]=3`
  selects a known set of resources in one request, and a filter through a relationship that points at
  the type reaches the id too, `filter[owner][id][@eq]=1`. the id codec reads each value;
- `idParameter(string $name)` names the id in every route of the type, `/pets/{pet_id}` for
  `pet_id`, and in the contract; without it the name is `id`. a name is a letter or `_` followed by at
  most 31 letters, digits or `_`, a group name every supported PHP compiles;
- `path(string $path)` lays out every route of the type below the path of its collection:
  `->path('/database/tables')` gives `/database/tables`, `/database/tables/{id}` and
  `/database/tables/{id}/relationships/<relationship>`; without it the path is `/<type>`. documents keep
  the type, and every link follows the path. the path is written as the path of an endpoint with literal
  segments alone, since a link to a resource of the type from another route could not fill a parameter.
  a path on which another route of the same method matches is refused, as for an endpoint;
- `attribute()` and `relationship()` add a field, in the order of the contract;
- the implementations bind the ports of the store, each enabling what it serves:

| method | port | enables |
|---|---|---|
| `reader()` | `CollectionReader` | `GET /<type>`, and the related and relationship routes of a to-many relationship to the type |
| `loader()` | `ResourceLoader` | `GET /<type>/<id>`, and the target of every other route on `/<type>/<id>`; also the resources a to-one relationship with a related id accessor points at |
| `relatedLoader()` | `RelatedLoader` | the linkage and the includes of a to-many relationship, and of a to-one relationship without a related id accessor |
| `creator()` | `ResourceCreator` | `POST /<type>` |
| `updater()` | `ResourceUpdater` | `PATCH /<type>/<id>` |
| `deleter()` | `ResourceDeleter` | `DELETE /<type>/<id>` |
| `relationshipReplacer()` | `RelationshipReplacer` | `PATCH` on a relationship that declares `replaceable()` |
| `relationshipAdder()` | `RelationshipAdder` | `POST` on a relationship that declares `addable()` |
| `relationshipRemover()` | `RelationshipRemover` | `DELETE` on a relationship that declares `removable()` |

- `permission(OperationKind, Implementation)` names the permission of each kind the type enables, and of no
  other (see [permissions and errors](#permissions-and-errors));
- `monitorType(OperationKind, string $monitorType)` lets a write get `202 Accepted` with a resource of
  the monitor type;
- `clientIds(bool $required = false)` lets a create send the id, which needs ids of the uuid format or
  an id codec declared globally unique;
- `version(Closure, bool $preconditionsRequired = false)` gives every single resource a strong `ETag`
  and evaluates `If-Match` on its writes; with the flag a write without `If-Match` gets 428;
- `defaultSort(string)` orders the collection without a `sort` parameter, in the syntax of the
  specification, `-listedAt,name`; without it the order is id ascending, and id is appended to
  every sort so the order is total. a page endpoint of the type without a default sort of its own
  takes it too, and a type that neither enables its collection route nor has such an endpoint
  declares none;
- `pagination(int $defaultSize, int $maxSize)` sets the page sizes of the collection route, and of a
  page endpoint of the type without its own; the default is 10 and 100;
- `maxIncludeDepth(int)` is the deepest include path, 1 unless declared;
- `queryParameter(OperationKind, string $name, Codec, bool $required = false)` declares a query
  parameter the route of one of its kinds reads (see
  [query parameters of the routes](#query-parameters-of-the-routes));
- `securitySchemes(OperationKind, SecurityScheme ...)` declares the schemes the route of one of its kinds
  accepts in place of the API's (see [security schemes](#security-schemes));
- `description(string)` describes the type in the contract. a text a declaration gives the contract,
  a description or a tag of a type, a field, an endpoint or an atomic route, is never empty:
  `build()` refuses an empty one, which would only blank the text the library writes in its place.

a type binds the loader exactly when something reads through it: a route on `/<type>/<id>` or below
it, or a to-one relationship with a related id accessor that points at the type. `build()` refuses
a missing loader and one nothing reads.

an operation a type does not enable is still routed and gets 403, so a client learns that
the operation exists and is not supported.

#### only and except

`only(OperationKind $kind, OperationKind ...$kinds)` keeps the routes of the kinds it names, and
`except()`, with the same parameters, excludes them, as Laravel's partial resource routes do. both
choose among the five routes of the resources:
`FetchCollection` and `CreateResource` on `/<type>`, `FetchResource`, `UpdateResource` and
`DeleteResource` on `/<type>/<id>`.

```php
ResourceType::make('orders')
    // ...
    ->except(OperationKind::FetchCollection);
```

- an excluded route stays registered and responds with 403 `operation_not_supported`, as a write whose
  port is not bound does; the contract leaves it out, and no document links to it, unless an endpoint
  takes the place of the read (see [endpoints](#endpoints));
- a write is enabled by binding its port, so excluding a write whose port is bound is refused;
- `reader()` may stand beside an excluded `FetchCollection`: it still serves the related and
  relationship routes of the to-many relationships that point at the type, and it is refused where
  none does. a default sort needs the collection route, or a page endpoint of the type that declares
  none of its own;
- without `FetchResource` a resource object of the type carries no self link, a create gets 201
  without a self link and without `Location`, and an update gets 200 without a top-level self
  link. a monitor type keeps `FetchResource`, since a client checks the status of the write through
  it.

### attributes

```php
Attribute::make('listedAt', new DateTimeCodec())
    ->accessor(static function (Pet $pet): DateTimeImmutable {
        return $pet->listedAt;
    })
    ->creatable()
    ->updatable()
    ->required()
    ->sortable()
    ->filter('@gte', '@lt', '@between');
```

- `accessor()` returns the value of a record; an attribute without one is written and never read;
- `creatable()` and `updatable()` let a create and an update send the attribute, and a member sent
  without its rule gets 422 with a pointer to it;
- `required()` makes a create send the attribute, and a create without it gets 422 with a pointer to
  the object that lacks it. an update never has to send it. it needs `creatable()`, or `build()`
  refuses the type;
- `sortable()` and `filter()` open the attribute to `sort` and to the operators named;
- `description()` and `deprecated()` describe it in the contract.

#### filter operators

`filter()` names the operators a client may apply to the attribute, out of 21 built-in ones and the
[custom operators](#custom-operators) of the API. each operator takes one of five operands; the table
spells the operators as a client writes them under the default [naming policy](#names), and
`filter()` names them in camel case, `filter('@notIn')`:

| operators | operand | query |
|---|---|---|
| `@eq`, `@ne`, `@lt`, `@lte`, `@gt`, `@gte` | one value of the attribute | `filter[listedAt][@gte]=2026-01-01T00:00:00Z` |
| `@eqi`, `@nei`, `@contains`, `@not_contains`, `@containsi`, `@not_containsi`, `@starts_with`, `@starts_withi`, `@ends_with`, `@ends_withi` | one string, not necessarily a value the codec accepts; an `i` at the end ignores case | `filter[name][@containsi]=rex` |
| `@in`, `@not_in` | a list of values | `filter[name][@in][]=Rex&filter[name][@in][]=Bella` |
| `@between` | two values, the bounds of the range | `filter[listedAt][@between][]=2026-01-01T00:00:00Z&filter[listedAt][@between][]=2026-02-01T00:00:00Z` |
| `@null`, `@not_null` | `true` or `false` | `filter[name][@null]=true` |

the conditions of one request all hold. `@or` takes a list of filters of which one holds, `@and` a
list of which all hold, and `@not` a filter that does not hold, around a whole filter or inside a
field:

```text
filter[@or][0][name][@eq]=Rex&filter[@or][1][name][@eq]=Bella
filter[name][@not][@eq]=Rex
```

### relationships

`ToOne::make(name, relatedType)` and `ToMany::make(name, relatedType)` declare a relationship. both
take `creatable()`, `updatable()`, `required()`, `notIncludable()`, `replaceable()`,
`linkageAlwaysPresent()`, `description()` and `deprecated()`.

- `ToOne::relatedId(Closure)` reads the related id from the record, so the linkage is rendered
  without a store; `notNullable()` refuses a null linkage and needs `required()`;
- `ToMany::addable()` and `removable()` enable `POST` and `DELETE` on the relationship route,
  `defaultSort(string)` orders its linkage and its routes, and `pagination()` sets its page sizes; the
  linkage of a document holds at most the default page size, with pagination links to the rest.
- `only()` and `except()` of a relationship keep or exclude its two reads: `FetchRelated`,
  `GET /<type>/<id>/<relationship>`, and `FetchRelationship`,
  `GET /<type>/<id>/relationships/<relationship>`. an excluded read gets 403
  `relationship_fetch_not_supported`, and the relationship object loses the `related` or the `self`
  link. without both it carries its linkage in every document instead, as `linkageAlwaysPresent()`
  does. a write is enabled by declaring it, so a write kind there is refused. a to-many relationship
  keeps its relationship route while a document can carry its linkage, whose pages link to that route:
  where it is includable, always linked, or declares an update.
- `queryParameter(OperationKind, string $name, Codec, bool $required = false)` declares a query
  parameter a route of the relationship reads, its related or relationship route or a write it declares,
  and `securitySchemes(OperationKind, SecurityScheme ...)` the schemes such a route accepts in place of
  the API's (see [security schemes](#security-schemes)).

a relationship name opens a filter on the related type,
`filter[owner][name][@eq]=Ada`, as deep as the include depth, and a sort may run through one to-one
relationship, to a sortable attribute or to the id, `sort=owner.name` or `sort=owner.id`.

### query parameters of the routes

a route the library lays out reads the families of JSON:API alone, and responds to any other parameter
with 400 `unsupported_query_parameter`, as the specification asks. a type declares more for the routes
of its resources, and a relationship for its own routes, one kind at a time:

```php
ResourceType::make('pets')
    ->queryParameter(OperationKind::FetchCollection, 'namePrefix', new StringCodec(1, 200))
    ->queryParameter(OperationKind::CreateResource, 'notifyOwner', new BooleanCodec(), required: true);

ToMany::make('orders', 'orders')
    ->queryParameter(OperationKind::FetchRelated, 'placedAfter', new DateTimeCodec());
```

- a parameter is named and read as an endpoint's is (see [endpoints](#endpoints)): a legal member name
  with a character other than `a-z`, no family of JSON:API, no name WordPress reads and no language
  parameter, its value read by its codec, a violation refused with 400 at the parameter and a missing
  required one with 400 `missing_query_parameter`;
- a kind the declaration does not enable takes none, and `build()` refuses one declared there;
- the store reads the decoded values as `$queryParameters`, an `Operation\Values`: on the operation a
  write port receives, and on the `CollectionQuery`, `LoadRequest` or `RelatedLoadRequest` that reads
  the primary data or the target of the route. a request for included resources, or for the records a
  cursor is read off, carries none;
- a link of a document carries the parameters of the request the route it leads to reads, as sent:
  the self link and the pagination links of a read carry its own, so the next page reads what the
  first one did, while the self link of a write, which leads to the read on its path or to the
  created resource, leaves out a parameter only the write reads. no link carries a parameter
  WordPress consumes, such as `_method`, which WordPress applies to a GET of the link as well;
- a route the documents link to with no parameter of its own, the fetch of one resource, a related
  route or a relationship route, requires none: `build()` refuses a required parameter there, as a
  link to the route could never carry it;
- an operation of an atomic request carries no query parameter, as it carries no precondition: its
  `$queryParameters` is empty, and a kind whose route requires one gets 400
  `missing_query_parameter` at the operation;
- the contract lists each parameter on its operation, before the families of the route.

### endpoints

an endpoint is a route of the plugin beside the routes of its types, as Laravel supplements a resource
controller with `Route::get('/photos/popular', ...)` beside `Route::resource()`. `get()`, `post()`,
`put()`, `patch()` and `delete()` of the API builder take its path and its handler, an
`Endpoint\Handler`, and return an `Endpoint`, which takes the rest:

```php
use Polunich\WpJsonApi\Declaration\ResponseShape;
use Polunich\WpJsonApi\Declaration\BodyShape;

$builder->post('/pets/{id}/adopt', Implementation::instance(new AdoptPet()))
    ->name('adoptPet')
    ->bind('id', 'pets')
    ->body(BodyShape::toOne('customers'))
    ->responds(ResponseShape::record('id'))
    ->permission(Implementation::instance(new StaffOnly()))
    ->description('the customer the body names adopts the pet.');
```

- `name(string)` is the operationId of the contract: a letter or `_`, then letters, digits and `_`,
  unique among the endpoints. every endpoint declares one;
- the path names each parameter in braces, as a whole segment, and a literal segment holds letters,
  digits, `-`, `_` and `~`. `bind(string $parameter, string $type)` gives a parameter the id codec of
  the type, and the loader of the type loads its record before the handler runs, 404 where it is
  missing; `pathParameter(string $name, SegmentCodec)` gives a parameter a codec of its own;
- `queryParameter(string $name, Codec, bool $required = false)` declares a query parameter, named as
  JSON:API names a custom query parameter: a legal member name with a character other than `a-z`, no
  family of JSON:API and none WordPress reads itself; `strictQueryParameterNames(false)` admits a name
  of `a-z` alone, such as `format`. its codec reads the text of the query as a filter reads an operand,
  an object or a list written with brackets included, `priceRange[min]=10&priceRange[max]=50`. a request
  without a required one gets 400 `missing_query_parameter`, and one with an undeclared one 400;
- `body(BodyShape)` declares a body the endpoint reads, one per media type; the `Content-Type` of a
  request picks it, and content of a media type the endpoint reads no body of gets 415, with the
  media types it reads in `Accept`. a document in the JSON:API media type is read by the rules of the
  library, each problem reported with its pointer: `resource($type)` as a create of the type,
  `record($parameter)` as an update of the bound record, `toOne($type)` and `toMany($type)` as linkage,
  `meta(Codec)` as a document of top-level `meta` alone. a body in another media type is read by its
  own rules (see [content in other media types](#content-in-other-media-types));
- `responds(ResponseShape)` declares one JSON:API response per status and any number of media responses
  of 200 in other media types, and every endpoint declares one response at least;
- `defaultSort(string $sort)` and `pagination(int $defaultSize, int $maxSize)` serve a page response
  as the same calls of a resource type serve its collection route; without them the page takes those
  of its type (see [a page of a collection](#a-page-of-a-collection));
- `permission(Implementation)` binds an `Access\EndpointPermission` (see [permissions](#permissions)), which
  every endpoint does;
- `securitySchemes(SecurityScheme ...)` declares the schemes it accepts in place of the API's (see
  [security schemes](#security-schemes));
- `tag()` and `description()` describe it in the contract; without a tag it takes the type of its first
  bound parameter.

| response | status | the handler returns |
|---|---|---|
| `resource(string $type)` | 200 | `Response::resource($record)`, a resource of the type |
| `record(string $parameter)` | 200 | `Response::resource($record)`, the bound record as the handler leaves it |
| `collection(string $type)` | 200 | `Response::collection($records)`, a whole list without pages |
| `page(string $type)` | 200 | `Response::page($slice)`, the `Store\Slice` the handler read for `$request->collectionQuery`, paged as the collection route is |
| `related(string $parameter, string $relationship)` | 200 | `Response::resource()` of the related record, or null, for a to-one relationship; `Response::collection()` for a to-many one |
| `linkage(string $parameter, string $relationship)` | 200 | `Response::linkage($related)`, written as resource identifiers |
| `created(string $type)` | 201 | `Response::created($record)`, linked and named in `Location` where the type keeps the fetch of its resources |
| `meta(Codec)` | 200 | `Response::meta($value)`, written by the codec as top-level `meta` |
| `accepted(string $monitorType)` | 202 | `Response::accepted($monitor)`, named in `Content-Location` |
| `noContent()` | 204 | `Response::noContent()` |
| `content(string $mediaType)` | 200 | `Response::content($content, $mediaType, $attachmentName)`, an `Http\Content` of that media type |
| `json(string $mediaType, Codec)` | 200 | `Response::json($value, $mediaType)`, written by the codec in `application/json` or a `+json` type |

```php
namespace Acme\Petstore;

use Polunich\WpJsonApi\Endpoint\Response;
use Polunich\WpJsonApi\Endpoint\Handler;
use Polunich\WpJsonApi\Endpoint\Request;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\NotFoundException;
use Polunich\WpJsonApi\Operation\Linkage;

final class AdoptPet implements Handler
{
    public function handle(Request $request): Response
    {
        $pet = $request->record('id');
        assert($pet instanceof Pet && $request->body instanceof Linkage);

        // null where the body clears the owner
        $customer = $request->body->identifier();
        $post = $customer === null ? null : get_post((int) $customer->id);

        if ($customer !== null && ($post?->post_type !== 'customer' || $post->post_status !== 'publish')) {
            throw new NotFoundException([new ErrorDescription('customer_not_found', pointer: '/data')]);
        }

        update_post_meta((int) $pet->id, 'owner_id', $customer?->id ?? '');

        return Response::resource(new Pet($pet->id, $pet->name, $pet->listedAt, $customer?->id));
    }
}
```

`Endpoint\Request` holds the path values decoded by their codecs, the bound records, the declared
query parameters the request carries in `$queryParameters` (one left out is absent, never null, as
`Values::has()` tells), the reading of a page response in `$collectionQuery`, the body and the media type of its declaration in `$bodyMediaType`, the
language preferences of the request in `$languages`, the precondition, the request as it arrived, and in `$mediaType` the
representation `Accept` picked. a response of a status the endpoint does not declare, of another shape, or a record other
than the bound one is a fault of the handler: 500, with the reason in the log.

`include` and `fields` apply where the responses carry resources, which all start at one type or at one
relationship; `filter`, `sort` and `page` apply to a page response alone and are left to the endpoint's
own parameters otherwise. a document with
primary data carries the URL of a GET endpoint, with the parameters of its query it reads, as its self
link, since JSON:API requires a server to fetch resource data at every self link; a document of any
other method, and one of top-level `meta` alone, carries none.

the preconditions of an endpoint read one record. a GET reads the record its response of 200 names, where
its type declares a version: the response carries its `ETag`, and the library responds to
`If-None-Match` with 304. a write reads the one bound record of a versioned type, or the one
`preconditionRecord(string $parameter)` names among several, and gets `If-Match`, 412 and 428 as a
write of that type does. the library cannot make the handler's write conditional, so the handler
receives the condition in `$request->precondition`, makes it a condition of its own write and throws
`Store\VersionMismatchException` where the stored version is not one it admits, and the client
gets 412. `If-Match: *` admits every version, so under it the exception is a fault of the handler,
and the client gets 500. `leavesUnchanged()` states that the write does not change that record, so
a request without `If-Match` passes. a response to PUT carries no `ETag`, since the library cannot
know that the content was stored as sent.

#### a page of a collection

`ResponseShape::page($type)` responds with a page of the resources of the type that the endpoint
selects, as `GET /customers/{id}/pets` selects the pets of one customer:

```php
$builder->get('/customers/{id}/pets', Implementation::instance(new CustomerPets()))
    ->name('customerPets')
    ->bind('id', 'customers')
    ->responds(ResponseShape::page('pets'))
    ->defaultSort('-listedAt')
    ->pagination(10, 50)
    ->permission(Implementation::instance(new AllowAny()));
```

the library reads `filter`, `sort` and `page` of the request against the type, as on the collection
route, and hands the handler the reading in `$request->collectionQuery`: a `Store\CollectionQuery`
resolved as for a `CollectionReader`, with the window of the page and the one record past it, the sort
ending with `id`, and the conditions of a cursor in the filter. the handler narrows the reading to what
the endpoint selects inside its storage query, reads the records of the window in the order of the sort,
and returns them as a `Store\Slice`:

```php
final class CustomerPets implements Handler
{
    public function __construct(private PetReader $pets) {}

    public function handle(Request $request): Response
    {
        $query = $request->collectionQuery;
        assert($query !== null);

        return Response::page($this->pets->readOwnedBy($request->path['id'], $query));
    }
}
```

the library trims the record past the page and writes the document of the collection route: the
pagination links to the URL of the endpoint with the query of its self link, `meta.page`, and the
cursor pagination profile where the page is read by cursor. a slice with more records than the window,
or a response of another shape, is a fault of the handler.

a page responds to GET alone, since a client follows its pagination links with GET; the endpoint may take
the place of the excluded collection route of its type. `ResponseShape::collection()` stays the shape of
a list without pages, which reads no `filter`, `sort` or `page`.

#### content in other media types

an endpoint may respond with and read other media types than JSON:API: a file, CSV for a spreadsheet,
the JSON a payment service sends.

```php
use Polunich\WpJsonApi\Codec\ObjectCodec;

$builder->get('/pets/export', Implementation::instance(new ExportPets()))
    ->name('exportPets')
    ->responds(ResponseShape::content('text/csv'))
    ->responds(ResponseShape::collection('pets'))
    ->permission(Implementation::instance(new AllowAny()));

$builder->post('/pets/{id}/photos', Implementation::instance(new AddPetPhoto()))
    ->name('addPetPhoto')
    ->bind('id', 'pets')
    ->body(BodyShape::form('multipart/form-data')
        ->field('caption', new StringCodec(), required: true)
        ->file('photo', required: true))
    ->responds(ResponseShape::noContent())
    ->permission(Implementation::instance(new StaffOnly()));

$builder->post('/webhooks/payments', Implementation::instance(new PaymentWebhook()))
    ->name('paymentWebhook')
    ->body(BodyShape::json('application/json', new ObjectCodec(['id' => new StringCodec()], ['id'])))
    ->responds(ResponseShape::json('application/json', new ObjectCodec(['received' => new StringCodec()], ['received'])))
    ->permission(Implementation::instance(new AllowAny()));
```

`Accept` picks the representation of 200 as RFC 9110 weighs it: the most specific range that admits a
declared media type gives its weight, the highest above 0 wins, and the first declared wins among equals
or where the request states no preference; no acceptable one is 406. the handler reads the pick in
`$request->mediaType` and responds in it:

```php
namespace Acme\Petstore;

use Polunich\WpJsonApi\Endpoint\Response;
use Polunich\WpJsonApi\Endpoint\Handler;
use Polunich\WpJsonApi\Endpoint\Request;
use Polunich\WpJsonApi\Http\Content;

final class ExportPets implements Handler
{
    public function handle(Request $request): Response
    {
        $pets = array_map(PetStore::fromPost(...), get_posts(['post_type' => 'pet', 'numberposts' => -1]));

        if ($request->mediaType !== 'text/csv') {
            return Response::collection($pets);
        }

        return Response::content(Content::chunks(self::rows($pets)), 'text/csv', 'pets.csv');
    }

    /**
     * @param list<Pet> $pets
     *
     * @return iterable<string>
     */
    private static function rows(array $pets): iterable
    {
        yield "id,name,listedAt\r\n";

        foreach ($pets as $pet) {
            $name = '"' . str_replace('"', '""', $pet->name) . '"';

            yield $pet->id . ',' . $name . ',' . $pet->listedAt->format(DATE_RFC3339) . "\r\n";
        }
    }
}
```

`Http\Content` takes four forms: `bytes()`, `file()` by its path, `stream()` of an open resource the
library then owns, and `chunks()` of any iterable of strings, written as they come. the library opens the
file, and runs the chunks to their first piece, before the status is sent, so for a file that cannot be
read the library responds with 500 as a document; a failure later can only cut the content, and is
logged. it writes the content itself, after every other callback of WordPress sent its header fields,
ends the output buffers PHP lets it remove and flushes each piece, so a file is never read into memory
whole; a HEAD gets the header fields and no content. a name given to `Response::content()` becomes
`Content-Disposition: attachment`, and a content of known size carries `Content-Length` where nothing
between the site and the client changes it. errors stay JSON:API documents, whatever was picked.

a body in another media type is read by its declaration:

- `BodyShape::json($mediaType, Codec)`: a JSON text of `application/json` or a `+json` type, 400 where it
  is no JSON and 422 with its pointer for a value the codec refuses; the handler reads an
  `Endpoint\JsonBody`;
- `BodyShape::form($mediaType)`: `application/x-www-form-urlencoded`, or `multipart/form-data` for a POST
  alone, since PHP stores the fields and files of no other method. `field($name, Codec, $required)` reads
  a field as a query value is read, and `file($name, $required)` one file; the handler reads an
  `Endpoint\FormBody` of the decoded fields and `Http\UploadedFile`s. a field or file the form lacks
  where it is required, one it does not declare, a value a codec refuses and several files under one name
  are 422; a form larger than PHP's `post_max_size`, or a file above its limits, is 413; a file that
  arrived cut is 400; a file PHP could not store is 500. JSON:API names no `source` for a form field, so
  each of these errors names its field in the member `field` of its `meta`;
- `BodyShape::bytes($mediaType)`: the bytes as they came, an `Endpoint\BytesBody`.

a request without content is read as the empty content of the first body declared, the JSON:API one
first.

an endpoint may take the place of a read that a type or a relationship excludes with `only()` or
`except()`: a GET on the path of that route, with its parameter bound to the type and the response of the
read, as `GET /orders` in place of the collection of orders, with a page or the whole list of them, or
`GET /pets/{id}/owner` in place of the related route of `owner`. the endpoint serves the route, the contract describes the endpoint, and every
link to the read stays, so the endpoint requires no query parameter, which no link would carry. a path
that another route of the same method matches is refused, as is one that names a parameter of its
hierarchy differently, which OpenAPI forbids.

### codecs

a codec decodes a value of a request, encodes a value of a record and describes the value in the
contract, and every constraint it publishes it enforces in both directions: the library responds to a
value of a record outside it with 500 instead of a document that breaks the contract.

| codec | value |
|---|---|
| `StringCodec(?int $minLength, ?int $maxLength, ?string $pattern)` | a string |
| `IntegerCodec(?int $minimum, ?int $maximum)` | an integer |
| `NumberCodec(?float $minimum, ?float $maximum)` | a number |
| `BooleanCodec()` | a boolean |
| `DateCodec()`, `DateTimeCodec()` | a date and a date-time of RFC 3339, as `\DateTimeImmutable` |
| `DigitsCodec()` | decimal digits without a leading zero, as a string of any length |
| `UuidCodec()` | a UUID of RFC 9562 |
| `EnumCodec(class-string $enumClass)` | a case of a backed enum |
| `ConstCodec(scalar $value)` | one value |
| `NullableCodec(Codec)` | the inner value or null |
| `ListCodec(Codec, ?int $minItems, ?int $maxItems)` | a list |
| `MapCodec(Codec)` | an object whose members share one codec |
| `ObjectCodec(array $properties, array $required, bool $additionalProperties, array $readOnly, array $writeOnly)` | an object |
| `OneOfCodec(string $discriminator, array $variants, Closure $discriminatorOf)` | one of several objects |
| `MappedCodec(Codec, Closure $toValue, Closure $fromValue)` | a value object of the plugin |

a member of an `ObjectCodec` named in `$readOnly` travels in responses alone: a request that sends it
gets 422 `read_only_member` with a pointer to the member, as a read-only field does, and only a
response must carry it where it is required. a member named in `$writeOnly` travels in requests alone
and is never written. the contract describes each direction on its own, a request without the
read-only members and a response without the write-only ones.

`MappedCodec` turns a decoded value into a value object of the plugin; its `toValue` refuses a value
with `RejectedValueException`, which becomes a violation at the place of the value, as every refusal
of a codec does: a 422 with a pointer for a member of a document, a 400 with the parameter for a value
of a filter. a rule between members names the member to correct with `path`, such as
`new RejectedValueException('end_before_start', path: ['end'])`, and the violation points at that
member where the request carries it, or at the deepest value on the way that it carries.

the id of a type is read by a `SegmentCodec`: a codec of strings that also gives `segmentPattern()`,
the PCRE of one path segment, without delimiters, anchors or flags. `DigitsCodec` and `UuidCodec` are
the built-in ones; a plugin implements the interface for ids of another form. the pattern stands in
the WordPress route of the type, so a segment it refuses matches no route of that shape:
`GET /pets/abc` of a type with digit ids is WordPress's `rest_no_route`, 404, as a JSON:API error
document. it sees a segment in one form, whatever form the client sent: every unreserved character of
RFC 3986 as itself, every other octet percent-encoded with upper-case hex digits; it reads bytes and
ignores case. it must compile on its own, match no empty segment, never match `/`, hold no capturing
group (a group is written `(?:...)`) and no `@` without a backslash (`\@` or `\x40`), and a type whose
pattern breaks one of the checkable rules fails to build.

### implementations

the implementation of a port, a store, a permission, an endpoint handler or the transaction boundary,
is named in one of three ways:

- `Implementation::service(string $entryId)`: an entry of the container set with `container()`, created when
  a request needs it;
- `Implementation::instance(object)`: an object the plugin built;
- `Implementation::factory(Closure)`: a closure that creates the object when a request needs it.

the first `rest_api_init` checks every reference before the library adds a hook of a request: an entry
id the container does not hold, or an instance of another port, is refused with `LogicException`.

### custom operators

```php
use Polunich\WpJsonApi\Codec\ListCodec;
use Polunich\WpJsonApi\Codec\NumberCodec;
use Polunich\WpJsonApi\Declaration\Operator;

$builder->operator(Operator::make('@near', new ListCodec(new NumberCodec(), 2, 2)));
```

an attribute then names `@near` in `filter()`, and the store receives a condition with the operator
`@near` and the value the codec decoded. a client writes a custom operator as declared under either
naming policy, and `build()` refuses one named as a client writes a built-in operator, such as
`@not_in` under snake case.

### security schemes

the contract declares how a client authenticates, and a client generated from it sends each
credential where its scheme says: a scheme is input to the generator, not only text for a reader.
the default is the two ways WordPress core authenticates:

- `SecurityScheme::restNonce()`, cookie authentication, `{"type": "apiKey", "in": "header", "name":
  "X-WP-Nonce"}`: a page of the site creates the nonce with `wp_create_nonce('wp_rest')`, and the
  browser sends the logged-in cookie beside it, as a plugin's admin screen does;
- `SecurityScheme::applicationPasswords()`, `{"type": "http", "scheme": "basic"}`: an Application
  Password over HTTPS, for a client outside the browser.

any one scheme lets a request through. `securitySchemes()` of the API builder replaces the default
with the schemes given, such as the one of an authentication plugin, and none declares none. the
top-level `security` lists every scheme and an empty requirement, since the permissions decide at
runtime who is let through.

an operation that takes other credentials declares its own schemes, which replace the API's for that
operation alone, as the `security` of an OpenAPI operation "overrides any declared top-level
`security`":

```php
use Polunich\WpJsonApi\Authentication\SecurityScheme;

$paymentSignature = new SecurityScheme('paymentSignature', [
    'type' => 'apiKey',
    'in' => 'header',
    'name' => 'X-Payment-Signature',
]);

$builder->post('/orders/{id}/payment', Implementation::instance(new RecordPayment()))
    ->securitySchemes($paymentSignature);

ResourceType::make('pets')
    ->securitySchemes(OperationKind::DeleteResource, SecurityScheme::applicationPasswords());

ToMany::make('orders', 'orders')
    ->securitySchemes(OperationKind::FetchRelated, SecurityScheme::restNonce());

$builder->atomic('/operations')
    ->securitySchemes(SecurityScheme::applicationPasswords());
```

- an endpoint and an atomic route declare their schemes once, a type once for each kind of its
  resource routes, and a relationship once for each kind of its own routes; `build()` refuses the
  schemes of a kind the declaration does not enable, and a scheme named twice in one list;
- the `security` of such an operation ends with the empty requirement too, so a call with no scheme
  lists that requirement alone, which admits a request with no credentials;
- `components.securitySchemes` holds every scheme the API or an operation names, once by its name;
  `build()` refuses two different schemes of one name;
- an operation of an atomic request takes the schemes of its atomic route, which the contract
  lists on that route;
- the schemes change nothing WordPress does: it authenticates the request as before, and the
  permission decides. they choose the challenges of a 401 (see [401 or 403](#401-or-403)).

## the store

the library never queries storage. it parses a request into values and hands them to the implementations a
type binds, the ports of `Polunich\WpJsonApi\Store`; the plugin reads and writes its data there, with
`WP_Query`, `wpdb` or anything else. every port is generic over the record class of the plugin, the
object the accessors of the declaration read.

### reading

#### CollectionReader

```php
public function read(CollectionQuery $query): Slice;
```

one reader serves every collection of its type: `GET /<type>`, the related route and the
relationship route of a to-many relationship that points at the type, and the relationship read back
after a relationship write. the `CollectionQuery` holds the whole reading:

| member | what it holds |
|---|---|
| `$type` | the type of the records |
| `$filter` | the filter tree, `null` for none, the conditions of a cursor included |
| `$sort` | the order, which always ends with `id`, so it is total |
| `$window` | `$offset` and `$limit`; the limit is the page size plus one |
| `$totalRequested` | whether the page asks for the total, `page[with_count]` |
| `$fields` | the fields to load, a hint the store may disregard |
| `$languages` | the language preferences of the request (see [languages](#languages)) |
| `$parent` | on a related or relationship route, the type, id and relationship the records hang from |
| `$queryParameters` | the query parameters the route declares (see [query parameters of the routes](#query-parameters-of-the-routes)) |

the reader returns `new Slice($records, $total, $estimatedTotal)`: at most `$window->limit` records in
the order of the sort, starting at `$window->offset`. the record past the page tells the library that
a next page exists, so no count is needed for it; `$total` is counted only where
`$totalRequested` asks for it, and `null` otherwise.

the reader leaves out the records the client may not see inside its query and not afterwards, so a
page holds the page size whenever enough records exist, as the cursor profile requires.

#### the filter tree

`$query->filter` is a tree of `Query\Filter\FilterNode`, which a `Query\Filter\Visitor` of the plugin walks:

| node | the plugin reads |
|---|---|
| `Condition` | `$field`, a `FieldPath`; `$operator`, `"@eq"` or another operator; `$value`, decoded by the codec of the field or of the operator |
| `AllOf`, `AnyOf` | `$nodes`, every one or at least one of which matches |
| `Not` | `$node`, which must not match |
| `RelationshipCondition` | `$relationship` and `$condition`: a to-one relationship matches when its record does, a to-many one when at least one of its records does |

```php
$where = $query->filter?->accept(new MyVisitor());
```

a visitor returns whatever the storage speaks, an argument array of `WP_Query` or a fragment of SQL.
a condition on the id names the field `id`, which `FieldPath::isId()` tells from an attribute:
a cursor writes one, and so does `filterId()`; inside a `RelationshipCondition` it compares the id the
relationship points at, which a store may read off its foreign key.
a condition of a cursor names a field path through at most one to-one relationship, as a sort does.

#### ResourceLoader

```php
public function load(LoadRequest $request): array;
```

the loader returns the record of each id of `$request->ids`, at the position of its id, and `null`
for a record that does not exist or that the client may not see; a list of another length is a 500.
it serves `GET /<type>/<id>`, the target and the parent of every operation on an existing resource,
and the included records of a to-one relationship whose id the declaration reads, all ids of one
level in one call.

#### RelatedLoader

```php
public function loadRelated(RelatedLoadRequest $request): array;
```

the related loader returns, for each parent id of `$request->parentIds`, at most `$request->limit`
related records of `$request->relationship` in `$request->sort`: the linkage of a to-many relationship
in a document, which holds at most the default page size and reads one record more, and the includes
of one level of parents in one call.

### writing

| port | method | returns |
|---|---|---|
| `ResourceCreator` | `create(CreateResource)` | the created record, or an `Accepted` |
| `ResourceUpdater` | `update(UpdateResource)` | the updated record, or an `Accepted` |
| `ResourceDeleter` | `delete(DeleteResource)` | `null`, or an `Accepted` |
| `RelationshipReplacer` | `replace(ReplaceRelationship)` | `null`, or an `Accepted` |
| `RelationshipAdder` | `add(AddToRelationship)` | `null`, or an `Accepted` |
| `RelationshipRemover` | `remove(RemoveFromRelationship)` | `null`, or an `Accepted` |

- `$operation->attributes` and `$operation->relationships` are `Operation\Values`: a member the request
  leaves out is absent, `has()` tells it from a member set to `null`, and `get()` returns its decoded
  value, or the `Operation\Linkage` of a relationship; `$operation->queryParameters` holds the query
  parameters its route declares, in the same form;
- a linkage lists `Operation\Identifier` values, each a type and an id; every lid of an atomic
  request is replaced by its id before the store sees it;
- `$operation->precondition` is `null` where the request sent no `If-Match`; otherwise the store
  writes only where `$precondition->admits($storedVersion)`, in the write itself, since only the
  write is atomic. `If-Match: *` admits every version, so under it only a record gone meanwhile fails
  the write, with `ResourceNotFoundException`.

a store refuses what it alone knows with the exceptions of `Store`. each names only the fact, and
the library writes the error document, with the pointer into the request, which the store never
sees:

| exception | response |
|---|---|
| `ResourceNotFoundException` | 404: the target or the parent is gone |
| `RelatedResourceNotFoundException` | 404 with a pointer to each identifier that names no record the client may see |
| `ResourceExistsException` | 409: the client id is taken |
| `ConstraintViolationException` | 409 with the constraint and the fields it concerns |
| `VersionMismatchException` | 412: the precondition does not admit the stored version; 500 for a write without a precondition or under `If-Match: *`, since neither refuses a version |

a removal skips an identifier that names no member, so it throws no
`RelatedResourceNotFoundException`, and an addition does not add a member twice.

`new Accepted($monitorType, $record)` responds with 202 and the resource that monitors the work, for a
kind the type declares with `monitorType()`.

### atomic operations

`atomic()` of the API builder declares a route of the Atomic Operations extension, `POST` on the
path it writes, and returns an `AtomicRoute`, which takes the rest:

```php
$builder->transactionBoundary(Implementation::service('transactions'));

$builder->atomic('/operations')
    ->name('atomicOperations');

$builder->atomic('/pets/operations')
    ->name('petOperations')
    ->types('pets');
```

- `name(string)` is the operationId of the contract, under the rule of an endpoint's name, and unique
  among the endpoints and the atomic routes. every route declares one;
- the path holds literal segments alone, as an endpoint's path does: each operation names its target in
  its document, by `ref` or by its `data`, so a parameter would carry nothing;
- `types(string ...)` limits the route to those types. an operation whose target is of another type
  gets 409 `type_mismatch` at its `type`, as a POST of a type outside the collection of its
  endpoint does; a relationship of a type the route takes still points at any type. without `types()` the
  route takes every type the API declares;
- `securitySchemes(SecurityScheme ...)` declares the schemes it accepts in place of the API's (see
  [security schemes](#security-schemes)); every operation of its requests is challenged for them;
- `tag()` and `description()` describe it in the contract; without a tag it takes its type where it has
  one, else `atomic:operations`.

the operations of a request run in the transaction of a `TransactionBoundary`. `build()` refuses a
boundary without an atomic route and an atomic route without a boundary:

```php
public function begin(): void;
public function commit(): void;
public function rollBack(): void;
```

the library calls `begin()` once every operation of the request passed its checks, runs the
operations in order with the ports above, and calls `commit()`, or `rollBack()` when one failed,
whose error points into the request, `/atomic:operations/<n>`. an `Accepted` inside the transaction
is such a failure, since an atomic request finishes or fails.

## permissions and errors

### permissions

every request on the routes of a type is one of the ten kinds of `Operation\OperationKind`:

| kind | request |
|---|---|
| `FetchCollection` | `GET /<type>` |
| `FetchResource` | `GET /<type>/<id>` |
| `FetchRelated` | `GET /<type>/<id>/<relationship>` |
| `FetchRelationship` | `GET /<type>/<id>/relationships/<relationship>` |
| `CreateResource` | `POST /<type>`, or an atomic `add` of a resource object |
| `UpdateResource` | `PATCH /<type>/<id>`, or an atomic `update` of a resource object |
| `DeleteResource` | `DELETE /<type>/<id>`, or an atomic `remove` of a resource |
| `ReplaceRelationship` | `PATCH` on the relationship route, or an atomic `update` of a relationship |
| `AddToRelationship` | `POST` on the relationship route of a to-many relationship, or an atomic `add` to it |
| `RemoveFromRelationship` | `DELETE` on the relationship route of a to-many relationship, or an atomic `remove` from it |

every kind a type enables names a permission, an `Access\OperationPermission`:

```php
interface OperationPermission
{
    public function allows(OperationAttempt $attempt): bool;
}
```

WordPress requires a permission callback on every route, and the library passes one of its own for
each; an operation open to anyone names `Access\AllowAny`, which returns true.

the permission is asked twice, as Django REST framework asks its two methods:

1. before anything else of the request is read, with `$attempt->record` null: `$attempt->kind`,
   `$attempt->type`, `$attempt->relationship` and `$attempt->request`, the method, header fields,
   query and body of the request;
2. for an operation on an existing resource, once the loader returned it, with the record in
   `$attempt->record`: the target of a fetch, an update or a deletion, the parent of a relationship
   operation.

an endpoint names an `Access\EndpointPermission`, asked at the same two levels with an
`Access\EndpointAttempt`: first with `$attempt->records` null, the name of the endpoint in
`$attempt->endpointName` and the request; then, where the endpoint binds a parameter to a type, with the
loaded records by the name of the parameter. `Access\AllowAny` implements both ports.

```php
namespace Acme\Petstore;

use Polunich\WpJsonApi\Access\EndpointAttempt;
use Polunich\WpJsonApi\Access\EndpointPermission;

final class StaffOnly implements EndpointPermission
{
    public function allows(EndpointAttempt $attempt): bool
    {
        return current_user_can('edit_others_posts');
    }
}
```

a permission has no side effect: WordPress also calls it for the methods of a route it does not serve
when it responds to `OPTIONS` with `Allow`. a record the client may not see at all is left out by the
store, whose loader returns null for it, so the client gets 404; a record it may see but not change
is denied by the permission, 403.

### 401 or 403

a denial becomes 401 or 403 by one rule:

- a client that is authenticated gets 403;
- a client that is not gets 401 with the challenges that apply to its request, and 403 with the code
  `authentication_required` where none applies.

the identity comes from `Authentication\Identity`, by default the current user of WordPress, and the
challenges from `Authentication\ChallengeProvider`, which is given the request and the schemes of the
operation it asked for, its own or else the API's:

```php
public function challenges(ServerRequest $request, array $schemes): array;
```

the default provider returns `Basic realm="WordPress"` where three things hold: the schemes of the
operation hold Application Passwords, they are available on the site, which WordPress reports for HTTPS
or a local site unless a filter says otherwise, and the request carries Basic credentials. so a browser
script of a visitor who is not logged in gets 403 and no login dialog, and a client with a wrong
Application Password gets 401 with the challenge. `realm()` changes the realm, and `challengeProvider()`
replaces the provider, for example to return `Bearer` for a scheme of the plugin.

a 401 WordPress raises itself on the namespace of the API, for example from an authentication
filter, follows the same rule: it gets the challenges of the provider, or becomes 403 and keeps its
code. it is challenged for the schemes of the route of its method on its path, which WordPress may
not have matched yet, since it authenticates a request before it looks for the handler; where no route
of the path responds to the method, for those of the API.

### errors

every error is a JSON:API error document with `status`, `code`, `title` and `detail`, a `source` where
one member of the request caused it, and the most generally applicable status where a request has
several problems. for a failure the exceptions of `Store` do not name, a store and a handler throw
an exception of `Error`, which carries its whole error: one or more `Error\ErrorDescription`, each
with its own source:

```php
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\NotFoundException;

throw new NotFoundException([new ErrorDescription('pet_not_listed', detail: 'the pet is no longer listed.')]);
```

| exception | status |
|---|---|
| `BadRequestException` | 400 |
| `UnauthorizedException` | 401, with the challenges of the provider or as 403 |
| `ForbiddenException` | 403 |
| `NotFoundException` | 404 |
| `NotAcceptableException` | 406 |
| `ConflictException` | 409 |
| `PreconditionFailedException` | 412 |
| `ContentTooLargeException` | 413 |
| `UnsupportedMediaTypeException` | 415 |
| `UnprocessableContentException` | 422 |
| `PreconditionRequiredException` | 428 |
| `InternalServerErrorException` | 500 |
| `JsonApiException` | any 4xx or 5xx status given |

a description carries a code, a string of the plugin or a case of `Error\ErrorCode`, and at most one
source: `pointer`, `parameter` or `header`.

#### exception mappers

an exception of the plugin becomes an error through an `Error\ExceptionMapper`:

```php
use Polunich\WpJsonApi\Error\ConflictException;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ExceptionMapper;
use Polunich\WpJsonApi\Error\JsonApiException;
use Throwable;

/** @implements ExceptionMapper<PetAlreadySold> */
final class PetAlreadySoldMapper implements ExceptionMapper
{
    public function exceptionClass(): string
    {
        return PetAlreadySold::class;
    }

    public function map(Throwable $exception): JsonApiException
    {
        return new ConflictException([new ErrorDescription('pet_already_sold')], $exception);
    }
}
```

the mapper of the nearest superclass of an exception wins, and every other throwable becomes a 500
with the code `internal_error`, no internal message, and the exception in the log; with
`debugOutput(true)` the chain of exceptions goes to `meta.exceptions` as well.

#### the error catalog

titles and details come from an `Error\ErrorCatalog`, by code:

```php
interface ErrorCatalog
{
    public function title(string $code): ?string;

    public function detail(string $code, array $parameters): ?string;
}
```

a catalog set with `errorCatalog()` is asked first, and the catalog of the library words
what the plugin's catalog returns null for; a code neither knows, such as one of the plugin's own,
takes the title of the generic code of its status. a description may carry its own `title` and
`detail`, which win over every catalog. the library translates no text itself; a catalog of the
plugin may word its texts in the locale it applied.

#### the log

every 5xx the library responds with is written to the `Psr\Log\LoggerInterface` set with `logger()`, and
without one to PHP's `error_log()`, one line `wp-jsonapi [<name of the API>] <level>: <message>`
followed by the exception.

### languages

`languageSources()` declares where the language of a request comes from, in order: a query parameter,
`LanguageSource::queryParameter('uiLocale')`, and the `Accept-Language` field,
`LanguageSource::acceptLanguage()`. the first source the request carries decides and the ones after it
are not read. without the call the language comes from `Accept-Language` alone, and a call without
sources negotiates no language.

```php
use Polunich\WpJsonApi\Declaration\LanguageSource;

$builder->languageSources(LanguageSource::queryParameter('uiLocale'), LanguageSource::acceptLanguage());
```

with that declaration `GET /pets/7` with `uiLocale=uk` asks for Ukrainian whatever `Accept-Language` says,
and without the parameter the field decides. the parameter carries one language range such as `uk` or `en-GB`;
an empty value, one of another form or the parameter in brackets gets 400 `invalid_query_parameter`.
its name follows the rule of an endpoint's query parameter: no name WordPress reads, such as `_locale`,
and a character outside a-z, since JSON:API keeps the names of a-z alone for the parameters it may define.
`build()` therefore refuses `locale`. `strictQueryParameterNames(false)` admits it, and the API then departs
from JSON:API, which asks 400 for a query parameter that breaks its naming conventions:

```php
$builder
    ->strictQueryParameterNames(false)
    ->languageSources(LanguageSource::queryParameter('locale'), LanguageSource::acceptLanguage());
```

every route takes the parameter, and an endpoint cannot declare a parameter of the same name.

the preferences of the source that decided reach the store as `LanguagePreferences` in every query and
load request, and the handler of an endpoint in `$languages`. a `Localization\ContentLanguage` set with
`contentLanguage()` applies a locale, with `switch_to_locale()` for example, and returns its tag, which a
document carries in `Content-Language`.

`Vary` names `Accept` on every response, error responses included, and `Accept-Language` where the field
is a source and no source before it stands in the request: a query parameter is part of the URL, which
`Vary` never names. where the parameter decided, every link the response writes carries it as the client
wrote it, so a client that follows a link keeps its language; no other part of the query travels. the
contract describes each source as a parameter of every operation.

## the contract and tests

### the contract

the declaration of an API is the one source of its OpenAPI 3.1.2 contract: the paths and the methods
the declaration enables, the parameters of `filter`, `sort`, `page`, `include` and `fields` with the
values each route admits, every endpoint with its parameters, its bodies and its responses in each of their
media types, the documents
of every request and response, the header fields of the conditional requests and the security
schemes. `Api::openApiJson()` returns it, and two builds of one
declaration are one string: pretty printed, slashes unescaped, the members in declaration order and a
final newline.

### the command line

the plugin keeps the declaration in one place, as `PetstoreApi::define()` of [a first API](#a-first-api)
does, so that its registration and its contract build one API. the `composer.json` of the plugin names
that method under `extra.wp-jsonapi.api`, written `Class::method`; the method is public and static,
takes no argument and returns the `ApiBuilder` of the API:

```json
{
    "extra": {
        "wp-jsonapi": {
            "api": "Acme\\Petstore\\PetstoreApi::define"
        }
    }
}
```

```sh
vendor/bin/wp-jsonapi openapi build [--output=<path>]
vendor/bin/wp-jsonapi openapi check [--output=<path>]
vendor/bin/wp-jsonapi --help
```

- `build` writes every contract whose file differs, whole or not at all, and leaves a file that
  matches as it is;
- `check` compares every contract with its file byte for byte and names the first line of a file that
  differs;
- the contract goes to `openapi.json` beside `composer.json`, in the root of the plugin next to
  `vendor`, and `--output=<path>` names another file, absolute or relative to the working directory.

the command reads the `composer.json` of the root package of Composer, whichever directory it runs in.
a plugin that publishes several APIs maps the path of each contract file, relative to the root of the
plugin as every path of `composer.json` is, to its method, and `--output` is then refused:

```json
"api": {
    "openapi/pets.json": "Acme\\Petstore\\PetstoreApi::define",
    "openapi/orders.json": "Acme\\Petstore\\OrdersApi::define"
}
```

Composer puts `vendor/bin` on the `PATH` of the scripts of the root package, so a script runs the
command as a command of Composer, `composer openapi`:

```json
"scripts": {
    "openapi": "wp-jsonapi openapi build"
}
```

| exit status | meaning |
|---|---|
| 0 | every contract was written, or matches its file |
| 1 | `check` found a contract that differs from its file, or a file that does not exist |
| 2 | the command line, `composer.json` or a file is in the way |

neither command loads WordPress: the command calls `build()` on the builder the method returns, and
`build()` calls no function of WordPress. it calls the builder and the API it builds by their method
names alone, so a prefixed copy of the library builds its contract as well. every method runs before
the first file is written, so a method that fails leaves every file as it was. `openapi.json` is
committed with the plugin, and a CI job runs the check after the tests:

```yaml
- run: vendor/bin/wp-jsonapi openapi check
```

### the PHPUnit assertions

`Polunich\WpJsonApi\Testing\JsonApiAssertions` checks a response of the API against the committed
contract. it needs `phpunit/phpunit` and `opis/json-schema` 2.2 or later as development
dependencies of the plugin, and it works with PHPUnit 9.6, the version the test library of WordPress
runs, as with PHPUnit 12.

```php
use Polunich\WpJsonApi\Testing\JsonApiAssertions;

/**
 * keeps the status and the header fields `serve_request()` sends,
 * which a command line process never sends
 */
final class RecordingServer extends WP_REST_Server
{
    public int $status = 200;

    /** @var array<string, string> */
    public array $headers = [];

    public function send_header($key, $value)
    {
        $this->headers[strtolower($key)] = $value;
    }

    public function remove_header($key)
    {
        unset($this->headers[strtolower($key)]);
    }

    protected function set_status($code)
    {
        $this->status = $code;
    }
}

final class PetsTest extends WP_UnitTestCase
{
    use JsonApiAssertions;

    public function testTheCollection(): void
    {
        add_filter('wp_rest_server_class', static function (): string {
            return RecordingServer::class;
        });
        $GLOBALS['wp_rest_server'] = null;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_ACCEPT'] = 'application/vnd.api+json';
        $_GET = [];

        $server = rest_get_server();
        ob_start();
        $server->serve_request('/acme/v1/pets');
        $body = (string) ob_get_clean();

        self::assertResponseMatchesContract(
            __DIR__ . '/../openapi.json',
            '/pets',
            'GET',
            $server->status,
            $server->headers['content-type'] ?? null,
            $body,
        );
    }
}
```

`rest_get_server()` creates the server, of the class the filter names, and fires `rest_api_init`, so
the routes register on it. `serve_request()` reads the request from `$_SERVER`, `$_GET` and `$_POST`,
the body from `$GLOBALS['HTTP_RAW_POST_DATA']`, and echoes the document. a content an endpoint
responds with in another media type is written past the output buffers PHP lets the library remove, so
`ob_start()` catches it only in a buffer opened below PHPUnit's own, without
`PHP_OUTPUT_HANDLER_REMOVABLE`.

- the path is the one the request was sent to below the REST namespace, `/pets/1`; a query string
  is not read, a concrete path of the contract is matched before a template, and a percent-encoding
  is compared in its normal form;
- the response is the one of the status, else of its range, `4XX`, else `default`;
- a response without content in the contract asserts an empty body; for one with content the
  `Content-Type` the test passes picks the most specific media type of the contract, and a JSON body is
  valid against its schema, formats included, text is valid as one string, and a media type without a
  schema, such as `image/png`, is not checked; every violation stands in the message;
- a response the contract does not describe, a status or a method it does not list, fails the
  assertion.

the assertion validates with opis's `CompliantValidator`, which enables the options of the JSON Schema
standard alone: the plain `Validator` of opis writes the default of a schema into the document it
validates. `Testing\ContractSchemas` returns the same check as a list of violations, for a test that
wants to read them.

`serve_request()` of WordPress applies every filter the library adds, where `rest_do_request()`
dispatches only and applies `rest_request_after_callbacks` alone: it returns the document of the
library, a denial included, but leaves an error WordPress raises itself in the shape of WordPress and
skips `rest_post_dispatch` and `rest_pre_serve_request`. a test of the documents a client receives
serves the request, as the example above does.

## WordPress

### registration

`JsonApi::register()` is called once per API, before `rest_api_init` fires: in the main file of the
plugin, or on `plugins_loaded` or `init`. it adds one callback, to `rest_api_init`, and builds nothing,
so a request that never reaches the REST API builds no API, as WordPress asks of endpoint objects: they
"should be created and register their hooks on this action rather than another action to ensure
they're only loaded when needed" (`rest_api_init`, `wp-includes/rest-api.php`). the first
`rest_api_init` of the process calls the closure, checks the implementation references of the
declaration and adds the other callbacks; every `rest_api_init` registers the routes, since each one
prepares a server of its own:

| hook | what the library does there |
|---|---|
| `rest_api_init` | builds the API the first time; registers one route per path of the API, with a handler per method, each with its own `permission_callback` and no `args` |
| `rest_pre_dispatch`, at `PHP_INT_MIN` | writes the route of a request in one form before WordPress matches it, where that form lies on a route of the library |
| `rest_request_before_callbacks`, at `PHP_INT_MIN` | takes back a request on a route of the library whose body WordPress read as broken JSON, so the library responds to it |
| `rest_request_after_callbacks`, at `PHP_INT_MIN` | puts the document of a denial in place of the `WP_Error` its permission callback returned |
| `rest_post_dispatch`, at `PHP_INT_MIN` | serves the response of the library for its routes, and converts every error WordPress raises on a route of the library into a JSON:API error document |
| `rest_pre_serve_request`, at `PHP_INT_MIN` | removes the `Content-Type` WordPress sent before dispatch from a response of the library that has none, a 204 or a 304, and sends a status WordPress does not know |
| `rest_pre_serve_request`, at `PHP_INT_MAX` | writes a content of another media type, after every other callback, which may still send a header field |

a declaration the library refuses throws there, on the first REST request of the process.

the library defines no hook of its own: a hook name is global to the process, and two plugins may
bundle two copies of the library.

a closure that returns an `Api` another registration returned already is refused with `LogicException`,
since that API would register every route twice.

### requests

WordPress routes a request to the library; the library reads its method, header fields, query
parameters, route parameters and body, and responds with a document of its own, whose `Content-Type`
replaces the one WordPress sends first. an `application/x-www-form-urlencoded` body is parsed from its
bytes whatever the method, and a `multipart/form-data` one of a POST from the fields and files PHP
stored. WordPress itself responds to a body of a JSON media type that is no JSON before any callback; the
library takes such a request back, so it responds to its permission, `Accept` and preconditions first and
to the body last, as for any other request. the permission callback returns a denial as a `WP_Error` of
the library, and the library puts its own document in place of it on `rest_request_after_callbacks`.

the response of the library passes the filters of WordPress as the response of any route does. a
callback of `rest_request_after_callbacks` or `rest_post_dispatch` that runs after the library's, at a
priority above `PHP_INT_MIN` or added after it, reads the document, a denial included, as a
`WP_REST_Response`:

- a change a callback makes to that response, a header field, the status or the data, reaches the
  client;
- a value a callback returns in place of it reaches the client as WordPress sends it, and an error
  among them becomes a JSON:API error document with its status and its code, as every error WordPress
  raises on a route of the library does.

the query parameters, the method and the paths WordPress handles itself meet the library as follows:

- `rest_route`, `_method` and `_wpnonce` are consumed by WordPress and pass;
- `_fields`, `_embed`, `_envelope`, `_jsonp` and `_locale` change a response of WordPress, so a route
  of the library refuses them with 400 and the parameter;
- `OPTIONS` stays WordPress's response, whose `Allow` names the methods whose permission lets the
  request through;
- a path that lies on a route of the library and matches none, an id its codec refuses or a method no
  handler of the path responds to, is WordPress's `rest_no_route`, 404, as a JSON:API error document;
- a path of the namespace that lies on no route of the library, and a route another plugin registers
  on the namespace, keep WordPress's response and the plugin's own.

### links

every link is absolute, from `rest_url()`, so it follows the site: with pretty permalinks
`https://example.com/wp-json/acme/v1/pets/1`, without them
`https://example.com/index.php?rest_route=/acme/v1/pets/1`. a type, a relationship and an id are
percent-encoded into the path, and on a site without pretty permalinks once more into `rest_route`,
which PHP decodes once. `Location` after a create is the self link of the new resource.
a link is written only to a route that responds to its GET, so an excluded read route leaves every link to
it, unless an endpoint takes its place. a link is the path of the route it leads to, filled with the values
of its parameters, as a router generates the URL of a named route: it follows the path a type declares and
the endpoint that takes the place of a read.

the library matches a path in one form, every unreserved character as itself and every other octet
percent-encoded: `/pets/%31` reaches the pet `1`, and a site whose permalinks start with `index.php/`,
where WordPress routes the path decoded, reaches every route; a path that lies on no route of the
library keeps the form it was sent in.

### authentication

WordPress authenticates the request, with cookies and a nonce, with an Application Password, or with
a plugin of the site; the library asks `is_user_logged_in()` only to choose between 401 and 403 for a
denial. its default challenge, `Basic realm="WordPress"`, applies where the operation accepts
Application Passwords, they are available and the request carries Basic credentials.

### what the library leaves to WordPress

- CORS: `rest_send_cors_headers()` and its filters are the site's policy;
- the no-cache header fields WordPress sends for a logged-in user;
- `X-Robots-Tag`, `X-Content-Type-Options` and the `Link` of the API root;
- `_envelope` and `_jsonp`, which WordPress applies after dispatch, so it may still wrap a response
  the library refused.

### the integration suite

`tests/Integration` runs the library inside WordPress, as the CI job does for WordPress 6.4.10 on
PHP 8.3 and WordPress 7.1 on PHP 8.3, 8.4 and 8.5.

## versioning

the library follows semantic versioning: a major release is the one that may break the public API,
and a minor or a patch release keeps it compatible. the public API is every class, interface, enum
and trait below `Polunich\WpJsonApi` that carries no `@internal` tag, with the public and protected
members of each that carry none either. a class or a member marked `@internal` may change in any
release, and nothing below `Polunich\WpJsonApi\Tests` is public API.

PHPStan keeps the tag in step with that set: `tests/Architecture/PublicApiCheck.php` reports a class
of the public API that carries the tag, any other class of the library that lacks it, and a class of the public API
that names an internal class in its signature.

## license

GPL-2.0-or-later. see [LICENSE](LICENSE).
