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

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Codec\BooleanCodec;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Declaration\Relationship;
use Polunich\WpJsonApi\Declaration\ToMany;
use Polunich\WpJsonApi\Declaration\ToOne;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Operation\OperationKind;
use ReflectionClass;

/**
 * the parts a to-one and a to-many relationship declare alike, run on a builder of each
 *
 * @internal
 */
#[CoversClass(Relationship::class)]
final class RelationshipTest extends TestCase
{
    public function testTheClassIsAnAbstractMutablePublicBase(): void
    {
        $class = new ReflectionClass(Relationship::class);

        self::assertTrue($class->isAbstract());
        self::assertFalse($class->isReadOnly());
        self::assertStringNotContainsString('@internal', (string) $class->getDocComment());
        self::assertStringContainsString('@internal', (string) $class->getMethod('build')->getDocComment());
    }

    /**
     * @param Closure(): Relationship $make
     */
    #[DataProvider('builders')]
    public function testEveryMethodReturnsTheBuilderItChanged(Closure $make): void
    {
        $relationship = $make();

        self::assertSame($relationship, $relationship->linkageAlwaysPresent());
        self::assertSame($relationship, $relationship->notIncludable());
        self::assertSame($relationship, $relationship->except(OperationKind::FetchRelated));
        self::assertSame($relationship, $relationship->replaceable());
        self::assertSame($relationship, $relationship->creatable());
        self::assertSame($relationship, $relationship->updatable());
        self::assertSame($relationship, $relationship->required());
        self::assertSame($relationship, $relationship->description('the related resources'));
        self::assertSame($relationship, $relationship->deprecated());
        self::assertSame(
            $relationship,
            $relationship->queryParameter(OperationKind::FetchRelated, 'since', new StringCodec()),
        );
    }

    /**
     * @param Closure(): Relationship $make
     */
    #[DataProvider('builders')]
    public function testQueryParametersAreCarriedPerKindInDeclarationOrder(Closure $make): void
    {
        $relationship = $make()
            ->replaceable()
            ->queryParameter(OperationKind::FetchRelated, 'since', new StringCodec())
            ->queryParameter(OperationKind::ReplaceRelationship, 'notify', new BooleanCodec(), required: true)
            ->queryParameter(OperationKind::FetchRelated, 'until', new StringCodec())
            ->build();

        self::assertSame(['since', 'until'], array_map(static function (QueryParameterMetadata $parameter): string {
            return $parameter->name;
        }, $relationship->queryParametersOf(OperationKind::FetchRelated)));
        self::assertTrue($relationship->queryParametersOf(OperationKind::ReplaceRelationship)[0]->required);
        self::assertSame([], $relationship->queryParametersOf(OperationKind::FetchRelationship));
    }

    #[DataProvider('kindsOfTheResourceRoutes')]
    public function testRefusesAQueryParameterForAKindOfTheResourceRoutes(OperationKind $kind): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'relationship "owner" declares a query parameter for %s, which addresses no relationship: a type declares'
            . ' the query parameters of its resource routes with its own queryParameter()',
            $kind->value,
        ));

        ToOne::make('owner', 'customers')->queryParameter($kind, 'since', new StringCodec());
    }

    /**
     * @return iterable<string, array{OperationKind}>
     */
    public static function kindsOfTheResourceRoutes(): iterable
    {
        foreach (OperationKind::cases() as $kind) {
            if (!$kind->targetsRelationship()) {
                yield $kind->value => [$kind];
            }
        }
    }

    public function testRefusesAQueryParameterNamedTwiceForOneKind(): void
    {
        $relationship = ToMany::make('tags', 'tags')
            ->queryParameter(OperationKind::FetchRelated, 'since', new StringCodec());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship "tags" declares the query parameter "since" for fetch_related'
            . ' twice, and each part of a declaration is written once');

        $relationship->queryParameter(OperationKind::FetchRelated, 'since', new StringCodec());
    }

    /**
     * @param Closure(): Relationship $make
     */
    #[DataProvider('builders')]
    public function testSecuritySchemesAreCarriedPerKindAndAKindWithoutThemTakesThoseOfTheApi(Closure $make): void
    {
        $nonce = SecurityScheme::restNonce();
        $relationship = $make()
            ->replaceable()
            ->securitySchemes(OperationKind::FetchRelated, $nonce)
            ->securitySchemes(OperationKind::ReplaceRelationship)
            ->build();

        self::assertSame([$nonce], $relationship->securitySchemesOf(OperationKind::FetchRelated));
        self::assertSame([], $relationship->securitySchemesOf(OperationKind::ReplaceRelationship));
        self::assertNull($relationship->securitySchemesOf(OperationKind::FetchRelationship));
    }

    #[DataProvider('kindsOfTheResourceRoutes')]
    public function testRefusesSecuritySchemesForAKindOfTheResourceRoutes(OperationKind $kind): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'relationship "owner" declares security schemes for %s, which addresses no relationship: a type'
            . ' declares the security schemes of its resource routes with its own securitySchemes()',
            $kind->value,
        ));

        ToOne::make('owner', 'customers')->securitySchemes($kind, SecurityScheme::restNonce());
    }

    public function testRefusesTheSecuritySchemesOfOneKindWrittenTwice(): void
    {
        $relationship = ToMany::make('tags', 'tags')->securitySchemes(OperationKind::FetchRelated);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship "tags" declares the security schemes of fetch_related twice, and'
            . ' each part of a declaration is written once');

        $relationship->securitySchemes(OperationKind::FetchRelated, SecurityScheme::restNonce());
    }

    public function testRefusesSecuritySchemesForAWriteTheRelationshipDoesNotDeclare(): void
    {
        $relationship = ToMany::make('tags', 'tags')->securitySchemes(
            OperationKind::AddToRelationship,
            SecurityScheme::restNonce(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship "tags" declares security schemes for add_to_relationship, which'
            . ' it does not enable');

        $relationship->build();
    }

    /**
     * @param Closure(): Relationship $make
     */
    #[DataProvider('builders')]
    public function testCarriesWhatTheDeclarationStates(Closure $make): void
    {
        $relationship = $make()
            ->linkageAlwaysPresent()
            ->replaceable()
            ->creatable()
            ->updatable()
            ->description('the related resources')
            ->deprecated()
            ->build();

        self::assertTrue($relationship->linkageAlwaysPresent);
        self::assertTrue($relationship->includable);
        self::assertSame([RelationshipUpdate::Replace], $relationship->updates);
        self::assertSame(
            [true, true, false],
            [
                $relationship->writeRule->creatable,
                $relationship->writeRule->updatable,
                $relationship->writeRule->required,
            ],
        );
        self::assertSame('the related resources', $relationship->description);
        self::assertTrue($relationship->deprecated);
    }

    /**
     * @param Closure(): Relationship $make
     */
    #[DataProvider('builders')]
    public function testARelationshipIsIncludedAndNeitherWrittenNorLinkedUntilTheDeclarationWritesMore(
        Closure $make,
    ): void {
        $relationship = $make()->build();

        self::assertFalse($relationship->linkageAlwaysPresent);
        self::assertTrue($relationship->includable);
        self::assertSame([], $relationship->updates);
        self::assertSame([], $relationship->excluded->kinds);
        self::assertSame(
            [false, false, false],
            [
                $relationship->writeRule->creatable,
                $relationship->writeRule->updatable,
                $relationship->writeRule->required,
            ],
        );
        self::assertNull($relationship->description);
        self::assertFalse($relationship->deprecated);
    }

    /**
     * @param Closure(): Relationship $make
     */
    #[DataProvider('builders')]
    public function testNotIncludableMakesTheRelationshipOneIncludeMayNotName(Closure $make): void
    {
        self::assertFalse($make()->notIncludable()->build()->includable);
    }

    /**
     * @param Closure(Relationship): Relationship $declaration
     * @param array{bool, bool, bool}             $rule        creatable, updatable
     *                                                         and required on create
     */
    #[DataProvider('writeRules')]
    public function testEachPartOfTheWriteRuleIsDeclaredOnItsOwn(Closure $declaration, array $rule): void
    {
        foreach (self::builders() as [$make]) {
            $relationship = $declaration($make())->build();

            self::assertSame(
                $rule,
                [
                    $relationship->writeRule->creatable,
                    $relationship->writeRule->updatable,
                    $relationship->writeRule->required,
                ],
            );
        }
    }

    /**
     * @return iterable<string, array{Closure(Relationship): Relationship, array{bool, bool, bool}}>
     */
    public static function writeRules(): iterable
    {
        yield 'a relationship a create writes' => [
            static function (Relationship $relationship): Relationship {
                return $relationship->creatable();
            },
            [true, false, false],
        ];
        yield 'a relationship an update writes' => [
            static function (Relationship $relationship): Relationship {
                return $relationship->updatable();
            },
            [false, true, false],
        ];
        yield 'a relationship a create requires' => [
            static function (Relationship $relationship): Relationship {
                return $relationship->creatable()->required();
            },
            [true, false, true],
        ];
    }

    /**
     * @param Closure(Relationship): Relationship $select
     * @param list<OperationKind>                 $excluded
     */
    #[DataProvider('selections')]
    public function testOnlyAndExceptNameTheReadsThatLeave(Closure $select, array $excluded, bool $alwaysPresent): void
    {
        foreach (self::builders() as [$make]) {
            $relationship = $select($make())->build();

            self::assertSame($excluded, $relationship->excluded->kinds);
            self::assertSame($alwaysPresent, $relationship->linkageAlwaysPresent);
        }
    }

    /**
     * @return iterable<string, array{
     *     Closure(Relationship): Relationship,
     *     list<OperationKind>,
     *     bool,
     * }>
     */
    public static function selections(): iterable
    {
        yield 'except of the related route' => [
            static function (Relationship $relationship): Relationship {
                return $relationship->except(OperationKind::FetchRelated);
            },
            [OperationKind::FetchRelated],
            false,
        ];
        yield 'only the related route' => [
            static function (Relationship $relationship): Relationship {
                return $relationship->only(OperationKind::FetchRelated);
            },
            [OperationKind::FetchRelationship],
            false,
        ];
        yield 'except of both reads' => [
            static function (Relationship $relationship): Relationship {
                return $relationship->except(OperationKind::FetchRelationship, OperationKind::FetchRelated);
            },
            [OperationKind::FetchRelated, OperationKind::FetchRelationship],
            true,
        ];
    }

    #[DataProvider('kindsThatAreNoRead')]
    public function testRefusesAKindThatIsNoReadOfARelationshipWhereItIsWritten(OperationKind $kind): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'relationship "owner" names %s in only(), and it names only fetch_related, fetch_relationship there: a'
            . ' relationship enables its writes with replaceable(), addable() or removable(), and the routes of the'
            . ' resources are excluded by the only() or except() of their type',
            $kind->value,
        ));

        ToOne::make('owner', 'customers')->only($kind);
    }

    /**
     * @return iterable<string, array{OperationKind}>
     */
    public static function kindsThatAreNoRead(): iterable
    {
        foreach (OperationKind::cases() as $kind) {
            if (!$kind->targetsRelationship() || !$kind->isFetch()) {
                yield $kind->value => [$kind];
            }
        }
    }

    /**
     * @param Closure(Relationship): mixed $second
     */
    #[DataProvider('partsWrittenTwice')]
    public function testRefusesAPartOfTheDeclarationWrittenTwice(Closure $second, string $message): void
    {
        $relationship = ToMany::make('tags', 'tags')->description('the tags')->except(OperationKind::FetchRelated);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $second($relationship);
    }

    /**
     * @return iterable<string, array{Closure(Relationship): mixed, string}>
     */
    public static function partsWrittenTwice(): iterable
    {
        yield 'a description' => [
            static function (Relationship $relationship): Relationship {
                return $relationship->description('other tags');
            },
            'relationship "tags" declares a description twice, and each part of a declaration is written once',
        ];
        yield 'its routes' => [
            static function (Relationship $relationship): Relationship {
                return $relationship->only(OperationKind::FetchRelated);
            },
            'relationship "tags" declares its routes twice, and each part of a declaration is written once',
        ];
    }

    public function testAPartWrittenTwiceNamesAnUncheckedNameEscaped(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship "own\ner" declares a description twice');

        ToOne::make("own\ner", 'customers')->description('the owner')->description('the owner');
    }

    /**
     * @param Closure(): Relationship $declaration
     */
    #[DataProvider('impossibleRelationships')]
    public function testRefusesADeclarationNoRelationshipCanHave(Closure $declaration, string $message): void
    {
        $relationship = $declaration();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $relationship->build();
    }

    /**
     * @return iterable<string, array{Closure(): Relationship, string}>
     */
    public static function impossibleRelationships(): iterable
    {
        yield 'a name that is no member name' => [
            static function (): Relationship {
                return ToOne::make('owner name-', 'customers');
            },
            'relationship "owner name-" is no member name',
        ];
        yield 'an @-member' => [
            static function (): Relationship {
                return ToMany::make('@tags', 'tags');
            },
            'relationship "@tags" is an @-member',
        ];
        yield 'a name of a resource object' => [
            static function (): Relationship {
                return ToOne::make('type', 'customers');
            },
            'relationship "type" shares the namespace of type and id, and no attribute or relationship is named'
            . ' type or id [spec, Fields]',
        ];
        yield 'a related type that is no member name' => [
            static function (): Relationship {
                return ToMany::make('tags', '');
            },
            'relationship "tags" points at type "", which is no member name [spec]',
        ];
        yield 'required on create and not creatable' => [
            static function (): Relationship {
                return ToMany::make('tags', 'tags')->required();
            },
            'a field required on create is creatable',
        ];
        yield 'a query parameter of a write the relationship does not declare' => [
            static function (): Relationship {
                return ToMany::make(
                    'tags',
                    'tags',
                )->queryParameter(OperationKind::AddToRelationship, 'notify', new BooleanCodec());
            },
            'relationship "tags" declares the query parameter "notify" for add_to_relationship, which it does not'
            . ' enable',
        ];
        yield 'a query parameter of a read the relationship excludes' => [
            static function (): Relationship {
                return ToMany::make('tags', 'tags')
                    ->except(OperationKind::FetchRelated)
                    ->queryParameter(OperationKind::FetchRelated, 'since', new StringCodec());
            },
            'relationship "tags" declares the query parameter "since" for fetch_related, which it does not enable',
        ];
        yield 'a query parameter of an addition to a to-one relationship' => [
            static function (): Relationship {
                return ToOne::make('owner', 'customers')
                    ->queryParameter(OperationKind::AddToRelationship, 'notify', new BooleanCodec());
            },
            'relationship "owner" declares the query parameter "notify" for add_to_relationship, which it does not'
            . ' enable',
        ];
    }

    /**
     * @return iterable<string, array{Closure(): Relationship}>
     */
    public static function builders(): iterable
    {
        yield 'to-one' => [static function (): Relationship {
            return ToOne::make('owner', 'customers');
        }];
        yield 'to-many' => [static function (): Relationship {
            return ToMany::make('tags', 'tags');
        }];
    }
}
