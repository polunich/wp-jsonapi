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

namespace Polunich\WpJsonApi\Tests\Unit\Declaration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Access\AllowAny;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Codec\Schema\Schema;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Declaration\BodyShape;
use Polunich\WpJsonApi\Declaration\Endpoint;
use Polunich\WpJsonApi\Declaration\Implementation;
use Polunich\WpJsonApi\Declaration\ResourceType;
use Polunich\WpJsonApi\Declaration\ResponseShape;
use Polunich\WpJsonApi\Declaration\ToMany;
use Polunich\WpJsonApi\Declaration\ToOne;
use Polunich\WpJsonApi\Metadata\Cardinality;
use Polunich\WpJsonApi\Metadata\Endpoint\BodyShapeKind;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaBodyShapeKind;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Routing\PathParameter;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\DescribedCodec;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(Endpoint::class)]
final class EndpointTest extends TestCase
{
    public function testTheBuilderIsPublicFinalAndMutable(): void
    {
        $class = new ReflectionClass(Endpoint::class);

        self::assertTrue($class->isFinal());
        self::assertFalse($class->isReadOnly());
        self::assertDoesNotMatchRegularExpression('/^\s*\*\s*@internal\b/m', (string) $class->getDocComment());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getConstructor()?->getDocComment());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getMethod('build')->getDocComment());
    }

    public function testADeclarationBuildsItsMetadata(): void
    {
        $endpoint = self::endpoint('POST', '/customers/{customer_id}/pets/{pet_id}/adopt')
            ->name('adoptPet')
            ->bind('pet_id', 'pets')
            ->bind('customer_id', 'customers')
            ->queryParameter('adoptionNote', new StringCodec(), required: true)
            ->body(BodyShape::meta(new StringCodec()))
            ->responds(ResponseShape::record('pet_id'))
            ->responds(ResponseShape::noContent())
            ->permission(Implementation::instance(new AllowAny()))
            ->tag('adoptions')
            ->description('a customer adopts a pet')
            ->preconditionRecord('pet_id')
            ->leavesUnchanged()
            ->build(self::registry());

        self::assertSame('adoptPet', $endpoint->name);
        self::assertSame(['POST'], $endpoint->methods);
        self::assertSame('/customers/{customer_id}/pets/{pet_id}/adopt', $endpoint->template->path);
        self::assertSame(['pet_id' => 'pets', 'customer_id' => 'customers'], $endpoint->bindings);
        self::assertSame('adoptionNote', $endpoint->queryParameters[0]->name);
        self::assertTrue($endpoint->queryParameters[0]->required);
        self::assertSame(BodyShapeKind::Meta, $endpoint->body?->kind);
        self::assertSame([200, 204], array_keys($endpoint->responses));
        self::assertSame(ResponseShapeKind::Record, $endpoint->responses[200]->kind);
        self::assertSame('pets', $endpoint->responses[200]->type);
        self::assertSame('adoptions', $endpoint->tag);
        self::assertSame('a customer adopts a pet', $endpoint->description);
        self::assertSame('pet_id', $endpoint->preconditionParameter);
        self::assertTrue($endpoint->leavesUnchanged);
    }

    public function testMediaResponsesAreDeclaredBesideTheJsonApiResponseInOrder(): void
    {
        $endpoint = self::endpoint('GET', '/orders/export')
            ->name('exportOrders')
            ->permission(Implementation::instance(new AllowAny()))
            ->responds(ResponseShape::content('text/csv; header=present'))
            ->responds(ResponseShape::collection('pets'))
            ->responds(ResponseShape::json('application/json', new IntegerCodec()))
            ->build(self::registry());

        self::assertSame([
            'text/csv;header=present',
            'application/json',
        ], array_map(static function (MediaResponseMetadata $mediaResponse): string {
            return $mediaResponse->mediaType->toHeaderValue();
        }, $endpoint->mediaResponses));
        self::assertNull($endpoint->mediaResponses[0]->codec);
        self::assertInstanceOf(IntegerCodec::class, $endpoint->mediaResponses[1]->codec);
        self::assertSame(1, $endpoint->jsonApiRank);
        self::assertSame(ResponseShapeKind::Collection, $endpoint->responses[200]->kind);
    }

    public function testTheFirstJsonApiResponsePlacesJsonApiAmongTheMediaResponses(): void
    {
        $endpoint = self::endpoint('GET', '/orders/export')
            ->name('exportOrders')
            ->permission(Implementation::instance(new AllowAny()))
            ->responds(ResponseShape::collection('pets'))
            ->responds(ResponseShape::content('text/csv'))
            ->responds(ResponseShape::noContent())
            ->build(self::registry());

        self::assertSame(0, $endpoint->jsonApiRank);
    }

    public function testAnEndpointOfMediaResponsesAloneNeedsNoJsonApiResponse(): void
    {
        $endpoint = self::endpoint('GET', '/pets/{id}/photo')
            ->name('petPhoto')
            ->bind('id', 'pets')
            ->permission(Implementation::instance(new AllowAny()))
            ->responds(ResponseShape::content('image/jpeg'))
            ->build(self::registry());

        self::assertSame([], $endpoint->responses);
        self::assertSame(0, $endpoint->jsonApiRank);
        self::assertSame('image/jpeg', $endpoint->mediaResponses[0]->mediaType->toHeaderValue());
    }

    public function testBodiesInOtherMediaTypesAreDeclaredBesideTheJsonApiOne(): void
    {
        $endpoint = self::endpoint('POST', '/pets/{id}/photos')
            ->name('addPhoto')
            ->bind('id', 'pets')
            ->permission(Implementation::instance(new AllowAny()))
            ->body(BodyShape::meta(new StringCodec()))
            ->body(BodyShape::form('multipart/form-data')->field(
                'caption',
                new StringCodec(),
                required: true,
            )->file('photo', required: true)->file('thumbnail'))
            ->body(BodyShape::json('application/json', new StringCodec()))
            ->body(BodyShape::bytes('image/png'))
            ->responds(ResponseShape::noContent())
            ->build(self::registry());

        self::assertSame(BodyShapeKind::Meta, $endpoint->body?->kind);
        self::assertSame([
            MediaBodyShapeKind::Form,
            MediaBodyShapeKind::Json,
            MediaBodyShapeKind::Bytes,
        ], array_map(static function ($body): MediaBodyShapeKind {
            return $body->kind;
        }, $endpoint->mediaBodies));
        self::assertSame('caption', $endpoint->mediaBodies[0]->fields[0]->name);
        self::assertTrue($endpoint->mediaBodies[0]->fields[0]->required);
        self::assertSame(['photo' => true, 'thumbnail' => false], $endpoint->mediaBodies[0]->files);
        self::assertInstanceOf(StringCodec::class, $endpoint->mediaBodies[1]->codec);
    }

    public function testATextThatIsNoMediaTypeIsRefusedWhenTheApiIsBuilt(): void
    {
        $endpoint = self::minimal('GET', '/orders/export')->responds(ResponseShape::content('csv'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the response of 200 of the endpoint "stats" names "csv", which is no media type',
        );

        $endpoint->build(self::registry());
    }

    public function testABodyOfAMediaTypeNoJsonIsRefusedWhenTheApiIsBuilt(): void
    {
        $endpoint = self::minimal('POST', '/webhooks/payments')->body(BodyShape::json('text/plain', new StringCodec()));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a body of "text/plain" is refused: a JSON body is read in a JSON media type');

        $endpoint->build(self::registry());
    }

    public function testABoundParameterTakesTheIdCodecOfItsType(): void
    {
        $codec = new DescribedCodec(new Schema(type: JsonType::String), '[a-z]+');
        $endpoint = self::minimal(
            'GET',
            '/pets/{id}/photos/{photo}',
        )->bind('id', 'pets')->pathParameter('photo', $codec)->build(self::registry());

        self::assertEquals(
            [new PathParameter('id', self::registry()->get('pets')->id->codec), new PathParameter('photo', $codec)],
            $endpoint->template->parameters(),
        );
    }

    public function testTheShapesResolveAgainstTheTypes(): void
    {
        $related = self::minimal(
            'GET',
            '/pets/{id}/buyers',
        )
            ->bind('id', 'pets')
            ->responds(ResponseShape::related('id', 'orders'))
            ->build(self::registry())
            ->responses[200];
        $linkage = self::minimal(
            'GET',
            '/pets/{id}/owners',
        )->bind('id', 'pets')->responds(ResponseShape::linkage('id', 'owner'))->build(self::registry())->responses[200];
        $body = self::minimal(
            'POST',
            '/pets/{id}/transfer',
        )->bind('id', 'pets')->body(BodyShape::record('id'))->build(self::registry())->body;

        self::assertSame(
            ['orders', 'orders', Cardinality::ToMany],
            [$related->type, $related->relationship, $related->cardinality],
        );
        self::assertSame(
            ['customers', 'owner', Cardinality::ToOne],
            [$linkage->type, $linkage->relationship, $linkage->cardinality],
        );
        self::assertSame(['pets', 'id'], [$body?->type, $body?->parameter]);
    }

    /**
     * @param callable(Endpoint): mixed $second
     */
    #[DataProvider('partsWrittenTwice')]
    public function testAPartWrittenTwiceIsRefused(callable $second, string $part): void
    {
        $endpoint = self::endpoint('POST', '/pets/{id}/adopt')
            ->name('adoptPet')
            ->bind('id', 'pets')
            ->queryParameter('adoptionNote', new StringCodec())
            ->body(BodyShape::toOne('customers'))
            ->responds(ResponseShape::record('id'))
            ->permission(Implementation::instance(new AllowAny()))
            ->tag('adoptions')
            ->description('adopts')
            ->preconditionRecord('id')
            ->leavesUnchanged()
            ->securitySchemes(SecurityScheme::restNonce())
            ->defaultSort('-name')
            ->pagination(2, 5);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'the endpoint "adoptPet" declares %s twice, and each part of a declaration is written once',
            $part,
        ));

        $second($endpoint);
    }

    /**
     * @return iterable<string, array{callable(Endpoint): mixed, string}>
     */
    public static function partsWrittenTwice(): iterable
    {
        $parts = [
            'the name' => ['a name', static function (Endpoint $endpoint): mixed {
                return $endpoint->name('again');
            }],
            'a binding' => [
                'the parameter "id", with a codec or a binding,',
                static function (Endpoint $endpoint): mixed {
                    return $endpoint->bind('id', 'customers');
                },
            ],
            'a codec beside a binding' => [
                'the parameter "id", with a codec or a binding,',
                static function (Endpoint $endpoint): mixed {
                    return $endpoint->pathParameter('id', new DigitsCodec());
                },
            ],
            'a query parameter' => ['the query parameter "adoptionNote"', static function (Endpoint $endpoint): mixed {
                return $endpoint->queryParameter('adoptionNote', new StringCodec());
            }],
            'the body' => ['a JSON:API body', static function (Endpoint $endpoint): mixed {
                return $endpoint->body(BodyShape::toMany('customers'));
            }],
            'a response of one status' => ['a response of 200', static function (Endpoint $endpoint): mixed {
                return $endpoint->responds(ResponseShape::resource('pets'));
            }],
            'the permission' => ['a permission', static function (Endpoint $endpoint): mixed {
                return $endpoint->permission(Implementation::instance(new AllowAny()));
            }],
            'the tag' => ['a tag', static function (Endpoint $endpoint): mixed {
                return $endpoint->tag('pets');
            }],
            'the description' => ['a description', static function (Endpoint $endpoint): mixed {
                return $endpoint->description('again');
            }],
            'the record of the preconditions' => [
                'the record of its preconditions',
                static function (Endpoint $endpoint): mixed {
                    return $endpoint->preconditionRecord('id');
                },
            ],
            'an unchanged record' => [
                'that it leaves its record unchanged',
                static function (Endpoint $endpoint): mixed {
                    return $endpoint->leavesUnchanged();
                },
            ],
            'the default sort' => ['a default sort', static function (Endpoint $endpoint): mixed {
                return $endpoint->defaultSort('name');
            }],
            'the pagination policy' => ['a pagination policy', static function (Endpoint $endpoint): mixed {
                return $endpoint->pagination(3, 6);
            }],
            'the security schemes' => ['its security schemes', static function (Endpoint $endpoint): mixed {
                return $endpoint->securitySchemes();
            }],
        ];

        foreach ($parts as $name => [$part, $second]) {
            yield $name => [$second, $part];
        }
    }

    public function testACodecWhosePatternNoRouteCanHoldIsRefusedWhereItIsDeclared(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the parameter "photo" of the endpoint GET /pets/{id}/photos/{photo}');

        self::endpoint(
            'GET',
            '/pets/{id}/photos/{photo}',
        )->pathParameter('photo', new DescribedCodec(new Schema(type: JsonType::String), '([a-z])\1'));
    }

    /**
     * @param callable(): mixed $build
     */
    #[DataProvider('refusedDeclarations')]
    public function testADeclarationTheTypesRefuseIsRefusedWhenItIsBuilt(callable $build, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $build();
    }

    /**
     * @return iterable<string, array{callable(): mixed, string}>
     */
    public static function refusedDeclarations(): iterable
    {
        yield 'no name' => [
            static function (): mixed {
                return self::endpoint(
                    'GET',
                    '/stats',
                )->responds(
                    ResponseShape::noContent(),
                )->permission(Implementation::instance(new AllowAny()))->build(self::registry());
            },
            'the endpoint GET /stats declares no name, and its name is the operationId of the contract',
        ];
        yield 'no permission' => [
            static function (): mixed {
                return self::endpoint(
                    'GET',
                    '/stats',
                )->name('stats')->responds(ResponseShape::noContent())->build(self::registry());
            },
            'the endpoint "stats" declares no permission, and every endpoint binds one; a public endpoint binds'
            . ' Access\AllowAny',
        ];
        yield 'a binding to an undeclared type' => [
            static function (): mixed {
                return self::minimal('GET', '/ghosts/{id}')->bind('id', 'ghosts')->build(self::registry());
            },
            'the parameter "id" of the endpoint "stats" names type "ghosts", which is not declared',
        ];
        yield 'a response of an undeclared type' => [
            static function (): mixed {
                return self::minimal(
                    'GET',
                    '/stats',
                )->responds(ResponseShape::collection('ghosts'))->build(self::registry());
            },
            'the response of 200 of the endpoint "stats" names type "ghosts", which is not declared',
        ];
        yield 'a response of a parameter bound to no type' => [
            static function (): mixed {
                return self::minimal('GET', '/stats')->responds(ResponseShape::record('id'))->build(self::registry());
            },
            'the response of 200 of the endpoint "stats" names the parameter "id", which is bound to no type',
        ];
        yield 'a relationship the bound type does not declare' => [
            static function (): mixed {
                return self::minimal(
                    'GET',
                    '/pets/{id}/vets',
                )->bind('id', 'pets')->responds(ResponseShape::related('id', 'vet'))->build(self::registry());
            },
            'the response of 200 of the endpoint "stats" names the relationship "vet" of the record bound to "id",'
            . ' and type "pets" declares no such relationship',
        ];
        yield 'a body of an undeclared type' => [
            static function (): mixed {
                return self::minimal('POST', '/stats')->body(BodyShape::resource('ghosts'))->build(self::registry());
            },
            'the body of the endpoint "stats" names type "ghosts", which is not declared',
        ];
        yield 'responses that start at two places' => [
            static function (): mixed {
                return self::minimal(
                    'POST',
                    '/pets',
                )->responds(ResponseShape::collection(
                    'pets',
                ))->responds(ResponseShape::created('customers'))->build(self::registry());
            },
            'the endpoint "stats" responds with resources of type "pets" and, with 201, of type "customers", and'
            . ' one request reads its include and fields parameters against one place',
        ];
        yield 'a linkage and resources of its related type' => [
            static function (): mixed {
                return self::minimal(
                    'POST',
                    '/pets/{id}/rehome',
                )->bind(
                    'id',
                    'pets',
                )->responds(ResponseShape::linkage(
                    'id',
                    'owner',
                ))->responds(ResponseShape::created('customers'))->build(self::registry());
            },
            'the endpoint "stats" responds with resources of relationship "owner" of type "pets" and, with 201, of'
            . ' type "customers", and one request reads its include and fields parameters against one place',
        ];
        yield 'a record of preconditions on GET' => [
            static function (): mixed {
                return self::minimal(
                    'GET',
                    '/pets/{id}/stats',
                )->bind('id', 'pets')->preconditionRecord('id')->build(self::registry());
            },
            'the endpoint "stats" responds to GET and names the record of its preconditions, which for a safe'
            . ' method is the record its response of 200 names',
        ];
        yield 'two versioned records and none named' => [
            static function (): mixed {
                return self::minimal(
                    'POST',
                    '/pets/{id}/swap/{other}',
                )->bind('id', 'pets')->bind('other', 'pets')->build(self::registry());
            },
            'the endpoint "stats" binds the records "id", "other" of versioned types, and its preconditions read'
            . ' one of them: name it with preconditionRecord()',
        ];
        yield 'a named record of a type without a version' => [
            static function (): mixed {
                return self::minimal(
                    'POST',
                    '/customers/{id}/rename',
                )->bind('id', 'customers')->preconditionRecord('id')->build(self::registry());
            },
            'the endpoint "stats" names "id" the record of its preconditions, and that is no parameter bound to a'
            . ' type that declares a version',
        ];
    }

    public function testThePreconditionRecordIsTheOneTheRulesName(): void
    {
        $read = self::minimal(
            'GET',
            '/pets/{id}/card',
        )->bind('id', 'pets')->responds(ResponseShape::record('id'))->build(self::registry());
        $readOfAnother = self::minimal(
            'GET',
            '/pets/{id}/owner-card',
        )->bind('id', 'pets')->responds(ResponseShape::related('id', 'owner'))->build(self::registry());
        $write = self::minimal(
            'POST',
            '/customers/{customer}/pets/{id}/adopt',
        )->bind('customer', 'customers')->bind('id', 'pets')->build(self::registry());
        $unversioned = self::minimal(
            'POST',
            '/customers/{id}/rename',
        )->bind('id', 'customers')->build(self::registry());
        $readOfUnversioned = self::minimal(
            'GET',
            '/customers/{id}/card',
        )->bind('id', 'customers')->responds(ResponseShape::record('id'))->build(self::registry());

        self::assertSame('id', $read->preconditionParameter);
        self::assertNull($readOfAnother->preconditionParameter);
        self::assertSame('id', $write->preconditionParameter);
        self::assertNull($unversioned->preconditionParameter);
        self::assertNull($readOfUnversioned->preconditionParameter);
    }

    public function testTheSecuritySchemesReachTheMetadataAndNoneDeclaredLeavesThoseOfTheApi(): void
    {
        $nonce = SecurityScheme::restNonce();
        $declared = self::minimal('GET', '/stats')->securitySchemes($nonce)->build(self::registry());
        $empty = self::minimal('GET', '/stats')->securitySchemes()->build(self::registry());
        $inherited = self::minimal('GET', '/stats')->build(self::registry());

        self::assertSame([$nonce], $declared->securitySchemes);
        self::assertSame([], $empty->securitySchemes);
        self::assertNull($inherited->securitySchemes);
    }

    public function testAPageCarriesItsDefaultSortAndPageSizesToTheMetadata(): void
    {
        $endpoint = self::endpoint('GET', '/pets/search')
            ->name('searchPets')
            ->permission(Implementation::instance(new AllowAny()))
            ->responds(ResponseShape::page('pets'))
            ->defaultSort('-name')
            ->pagination(2, 5)
            ->build(self::registry());

        self::assertSame('pets', $endpoint->pageType());
        self::assertSame('-name', $endpoint->defaultSort?->toString());
        self::assertEquals(new PaginationPolicy(2, 5), $endpoint->paginationPolicy);
    }

    public function testAPageWithoutADefaultSortOrPageSizesLeavesThemToItsType(): void
    {
        $endpoint = self::endpoint('GET', '/pets/search')
            ->name('searchPets')
            ->permission(Implementation::instance(new AllowAny()))
            ->responds(ResponseShape::page('pets'))
            ->build(self::registry());

        self::assertNull($endpoint->defaultSort);
        self::assertNull($endpoint->paginationPolicy);
    }

    private static function endpoint(string $method, string $path): Endpoint
    {
        return new Endpoint($method, $path, Implementation::service('handler'));
    }

    private static function minimal(string $method, string $path): Endpoint
    {
        return self::endpoint(
            $method,
            $path,
        )->name('stats')->permission(Implementation::instance(new AllowAny()))->responds(ResponseShape::noContent());
    }

    /**
     * versioned pets with a to-one owner and to-many orders,
     * customers without a version, and orders
     */
    private static function registry(): ResourceRegistry
    {
        $store = Implementation::service('store');
        $anyone = Implementation::instance(new AllowAny());
        $id = static function (Pet $pet): string {
            return $pet->id;
        };

        return new ResourceRegistry([
            ResourceType::make('pets')
                ->id(new DigitsCodec(), $id)
                ->relationship(ToOne::make('owner', 'customers')->relatedId(static function (Pet $pet): ?string {
                    return $pet->ownerId;
                }))
                ->relationship(ToMany::make('orders', 'orders'))
                ->loader($store)
                ->relatedLoader($store)
                ->permission(OperationKind::FetchResource, $anyone)
                ->permission(OperationKind::FetchRelated, $anyone)
                ->permission(OperationKind::FetchRelationship, $anyone)
                ->version(static function (Pet $pet): string {
                    return $pet->version;
                })
                ->build(),
            ResourceType::make('customers')->id(
                new DigitsCodec(),
                $id,
            )->loader($store)->permission(OperationKind::FetchResource, $anyone)->build(),
            ResourceType::make('orders')->id(
                new DigitsCodec(),
                $id,
            )->reader($store)->loader($store)->permission(
                OperationKind::FetchCollection,
                $anyone,
            )->permission(OperationKind::FetchResource, $anyone)->build(),
        ]);
    }
}
