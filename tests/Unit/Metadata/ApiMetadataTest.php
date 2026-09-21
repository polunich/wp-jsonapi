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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Codec\EnumCodec;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\NullableCodec;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Codec\Schema\Schema;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Declaration\Implementation;
use Polunich\WpJsonApi\Declaration\ResourceType;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\AtomicRoute;
use Polunich\WpJsonApi\Metadata\AtomicRouteMetadata;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\EndpointRoute;
use Polunich\WpJsonApi\Metadata\ImplementationReference;
use Polunich\WpJsonApi\Metadata\LanguageSourceMetadata;
use Polunich\WpJsonApi\Metadata\LanguageSources;
use Polunich\WpJsonApi\Metadata\OperationRoute;
use Polunich\WpJsonApi\Metadata\OperatorMetadata;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\Route;
use Polunich\WpJsonApi\Metadata\RouteExclusion;
use Polunich\WpJsonApi\Metadata\RouteOwner;
use Polunich\WpJsonApi\Metadata\RouteTable;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\Filter\Operators;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Routing\RouteKind;
use Polunich\WpJsonApi\Tests\Fixtures\Codec\Priority;
use Polunich\WpJsonApi\Tests\Fixtures\Codec\Shade;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\DescribedCodec;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use ReflectionClass;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(ApiMetadata::class)]
final class ApiMetadataTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(ApiMetadata::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testTheConstructorTakesTheTwelveMembersOfTheApi(): void
    {
        self::assertSame(
            [
                ['name', 'string'],
                ['version', 'string'],
                ['restNamespace', 'string'],
                ['resources', ResourceRegistry::class],
                ['endpoints', 'array'],
                ['atomicRoutes', 'array'],
                ['operators', 'array'],
                ['securitySchemes', 'array'],
                ['transactionBoundary', '?' . ImplementationReference::class],
                ['languageSources', LanguageSources::class],
                ['naming', NamingPolicy::class],
                ['strictQueryParameterNames', 'bool'],
            ],
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                (new ReflectionClass(ApiMetadata::class))->getConstructor()?->getParameters() ?? [],
            ),
        );
    }

    public function testFindsAnOperatorByTheNameAClientWrites(): void
    {
        $operators = Samples::operators([OperatorMetadata::custom('@nearBy', new StringCodec())]);
        $snake = self::api(operators: $operators);
        $camel = self::api(operators: $operators, naming: NamingPolicy::CamelCase);

        self::assertSame('@notIn', $snake->operatorWritten('@not_in')?->name);
        self::assertNull($snake->operatorWritten('@notIn'));
        self::assertSame('@notIn', $camel->operatorWritten('@notIn')?->name);
        self::assertNull($camel->operatorWritten('@not_in'));
        self::assertSame('@nearBy', $snake->operatorWritten('@nearBy')?->name);
        self::assertNull($snake->operatorWritten('@near_by'));
        self::assertSame('@not_in', $snake->operatorName($operators['@notIn']));
        self::assertSame('@nearBy', $snake->operatorName($operators['@nearBy']));
    }

    public function testRefusesACustomOperatorNamedAsAClientWritesABuiltInOne(): void
    {
        $operators = Samples::operators([OperatorMetadata::custom('@not_in', new StringCodec())]);

        self::assertSame(
            '@not_in',
            self::api(operators: $operators, naming: NamingPolicy::CamelCase)->operatorName($operators['@not_in']),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('custom operator "@not_in" is named as a client writes the built-in operator'
            . ' "@notIn"');

        self::api(operators: $operators);
    }

    public function testCarriesWhatTheDeclarationStates(): void
    {
        $resources = new ResourceRegistry([Samples::resource('pets')]);
        $operators = Samples::operators([OperatorMetadata::custom('@near', new StringCodec())]);
        $schemes = [
            SecurityScheme::applicationPasswords(),
            new SecurityScheme('bearer', ['type' => 'http', 'scheme' => 'bearer']),
        ];
        $boundary = ImplementationReference::service('transactions');
        $atomicRoutes = [Samples::atomicRoute()];
        $languageSources = new LanguageSources([LanguageSourceMetadata::queryParameter('uiLocale')]);

        $api = new ApiMetadata(
            'petstore',
            '1.0.0',
            'acme/v1',
            $resources,
            [],
            $atomicRoutes,
            $operators,
            $schemes,
            $boundary,
            $languageSources,
            NamingPolicy::CamelCase,
            false,
        );

        self::assertSame('petstore', $api->name);
        self::assertSame('1.0.0', $api->version);
        self::assertSame('acme/v1', $api->restNamespace);
        self::assertSame($resources, $api->resources);
        self::assertSame($atomicRoutes, $api->atomicRoutes);
        self::assertSame($operators, $api->operators);
        self::assertSame($schemes, $api->securitySchemes);
        self::assertSame($boundary, $api->transactionBoundary);
        self::assertSame($languageSources, $api->languageSources);
        self::assertSame(NamingPolicy::CamelCase, $api->naming);
        self::assertFalse($api->strictQueryParameterNames);
        self::assertNull(self::api()->transactionBoundary);
    }

    public function testRefusesAnEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the name of an API is info.title of its contract, which is never empty');

        self::api(name: '');
    }

    public function testRefusesAnEmptyVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the version of an API is info.version of its contract, which is never empty');

        self::api(version: '');
    }

    #[DataProvider('impossibleNamespaces')]
    public function testRefusesANamespaceWordPressObjectsTo(string $namespace, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::api(restNamespace: $namespace);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function impossibleNamespaces(): iterable
    {
        yield 'the empty namespace' => ['', 'the route namespace "" is empty to WordPress'];
        yield 'zero, which empty() reads as empty' => ['0', 'the route namespace "0" is empty to WordPress'];
        yield 'a leading slash' => ['/acme/v1', 'the route namespace "/acme/v1" starts or ends with a slash'];
        yield 'a trailing slash' => ['acme/v1/', 'the route namespace "acme/v1/" starts or ends with a slash'];
        yield 'a slash alone' => ['/', 'the route namespace "/" starts or ends with a slash'];
        yield 'a trailing slash behind a control character, escaped' => [
            "acme\n/",
            'the route namespace "acme\n/" starts or ends with a slash',
        ];
    }

    #[DataProvider('namespaces')]
    public function testAcceptsANamespaceWordPressRegisters(string $namespace): void
    {
        self::assertSame($namespace, self::api(restNamespace: $namespace)->restNamespace);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namespaces(): iterable
    {
        yield 'a name and a version' => ['acme/v1'];
        yield 'a name alone' => ['acme'];
        yield 'two zeros, which empty() reads as not empty' => ['00'];
        yield 'three segments' => ['acme/petstore/v2'];
    }

    public function testRefusesASecuritySchemeNameDeclaredTwice(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the API "petstore" declares the security scheme "applicationPasswords" twice');

        self::api(securitySchemes: [
            SecurityScheme::applicationPasswords(),
            new SecurityScheme('bearer', ['type' => 'http', 'scheme' => 'bearer']),
            new SecurityScheme('applicationPasswords', ['type' => 'http', 'scheme' => 'basic']),
        ]);
    }

    public function testTheComponentsHoldEverySchemeOnceInTheOrderItIsFirstDeclared(): void
    {
        $signature = new SecurityScheme(
            'paymentSignature',
            ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Signature'],
        );
        $api = self::schemedApi($signature);

        self::assertEquals(
            [SecurityScheme::applicationPasswords(), SecurityScheme::restNonce(), $signature],
            $api->componentSecuritySchemes,
        );
    }

    public function testTheSchemesOfARouteAreItsOwnElseThoseOfTheApi(): void
    {
        $signature = new SecurityScheme(
            'paymentSignature',
            ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Signature'],
        );
        $api = self::schemedApi($signature);
        $global = [SecurityScheme::applicationPasswords()];
        $schemesOf = static function (callable $matches) use ($api): array {
            foreach ($api->routes->all() as $route) {
                if ($matches($route)) {
                    return $api->securitySchemesOf($route);
                }
            }

            self::fail('the table lists no such route');
        };

        self::assertEquals($global, $api->securitySchemesOf(null));
        self::assertEquals([SecurityScheme::restNonce()], $schemesOf(static function (Route $route): bool {
            return $route instanceof OperationRoute && $route->operationKind === OperationKind::FetchResource;
        }));
        self::assertEquals($global, $schemesOf(static function (Route $route): bool {
            return $route instanceof OperationRoute && $route->operationKind === OperationKind::DeleteResource;
        }));
        self::assertEquals([], $schemesOf(static function (Route $route): bool {
            return $route instanceof OperationRoute && $route->operationKind === OperationKind::FetchRelated;
        }));
        $endpointSchemes = $schemesOf(static function (Route $route): bool {
            return $route instanceof EndpointRoute;
        });
        self::assertEquals([$signature, SecurityScheme::applicationPasswords()], $endpointSchemes);
        self::assertEquals([SecurityScheme::restNonce()], $schemesOf(static function (Route $route): bool {
            return $route instanceof AtomicRoute;
        }));
    }

    public function testRefusesOneNameGivenToDifferentSchemes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the endpoint "adoptPet" declares the security scheme "applicationPasswords" unlike the API "petstore", and'
            . ' a name is one entry of components.securitySchemes',
        );

        self::api(endpoints: [Samples::endpoint(securitySchemes: [
            new SecurityScheme('applicationPasswords', ['type' => 'http', 'scheme' => 'bearer']),
        ])]);
    }

    public function testAcceptsNoSecurityScheme(): void
    {
        self::assertSame([], self::api(securitySchemes: [])->securitySchemes);
    }

    public function testHoldsTheBuiltInOperatorsInTheirOrderAndThenTheCustomOnes(): void
    {
        $api = self::api(operators: Samples::operators([
            OperatorMetadata::custom('@near', new StringCodec()),
            OperatorMetadata::custom('@within', new IntegerCodec()),
        ]));

        self::assertSame([...Operators::names(), '@near', '@within'], array_keys($api->operators));
    }

    /**
     * @param array<string, OperatorMetadata> $operators
     */
    #[DataProvider('impossibleTables')]
    public function testRefusesAnImpossibleTableOfOperators(array $operators, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::api(operators: $operators);
    }

    /**
     * @return iterable<string, array{array<string, OperatorMetadata>, string}>
     */
    public static function impossibleTables(): iterable
    {
        $table = Samples::operators();
        $withoutLast = $table;
        unset($withoutLast['@endsWithi']);
        $swapped = ['@eqi' => $table['@eqi'], '@eq' => $table['@eq']] + $table;
        $customFirst = ['@near' => OperatorMetadata::custom('@near', new StringCodec())] + $table;
        $customInside = $withoutLast;
        $customInside['@near'] = OperatorMetadata::custom('@near', new StringCodec());
        $customInside['@endsWithi'] = $table['@endsWithi'];
        $customTwice = $table;
        $customTwice['@near'] = OperatorMetadata::custom('@near', new StringCodec());
        $customTwice['@nearby'] = OperatorMetadata::custom('@near', new IntegerCodec());
        $builtInTwice = $table;
        $builtInTwice['@equals'] = OperatorMetadata::builtIn('@eq');
        $misplaced = $table;
        $misplaced['@nearby'] = OperatorMetadata::custom('@near', new StringCodec());
        $misplacedUnderControl = $table;
        $misplacedUnderControl["@near\n"] = OperatorMetadata::custom('@near', new StringCodec());

        yield 'no operator' => [
            [],
            'the table of operators holds 0 operators, and it starts with the 21 built-in operators',
        ];
        yield 'the last built-in operator missing' => [
            $withoutLast,
            'the table of operators holds 20 operators, and it starts with the 21 built-in operators',
        ];
        yield 'two built-in operators swapped' => [
            $swapped,
            'the table of operators holds "@eqi" at position 0, where the built-in operator "@eq" stands',
        ];
        yield 'a custom operator first' => [
            $customFirst,
            'the table of operators holds "@near" at position 0, where the built-in operator "@eq" stands',
        ];
        yield 'a custom operator before the last built-in one' => [
            $customInside,
            'the table of operators holds "@near" at position 20, where the built-in operator "@endsWithi" stands',
        ];
        yield 'a custom name twice' => [$customTwice, 'operator "@near" is declared twice'];
        yield 'a built-in operator behind the table' => [$builtInTwice, 'operator "@eq" is declared twice'];
        yield 'an operator under another key' => [$misplaced, 'operator "@near" is held under the key "@nearby"'];
        yield 'an operator under a key with a control character, escaped' => [
            $misplacedUnderControl,
            'operator "@near" is held under the key "@near\n"',
        ];
    }

    /**
     * @param list<string> $declared
     */
    #[DataProvider('missingOperators')]
    public function testRefusesAnOperatorOfAnAttributeTheTableDoesNotHold(array $declared, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::api(
            resources: new ResourceRegistry([Samples::resource(
                'pets',
                attributes: [Samples::attribute('name', operators: $declared)],
            )]),
            operators: Samples::operators([OperatorMetadata::custom('@near', new StringCodec())]),
        );
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function missingOperators(): iterable
    {
        yield 'a custom operator that is not declared' => [
            ['@eq', '@far'],
            'attribute "name" of type "pets" declares operator "@far", which the table of operators does not hold',
        ];
        yield 'a name without @' => [
            ['eq'],
            'attribute "name" of type "pets" declares operator "eq", which the table of operators does not hold',
        ];
        yield 'a logical operator' => [
            ['@and'],
            'attribute "name" of type "pets" declares operator "@and", which the table of operators does not hold',
        ];
        yield 'a control character, escaped' => [
            ["@eq\n"],
            'attribute "name" of type "pets" declares operator "@eq\n", which the table of operators does not hold',
        ];
    }

    /**
     * @param Codec<mixed> $codec
     */
    #[DataProvider('textOperatorsOnNonStrings')]
    public function testRefusesATextOperatorOnAnAttributeThatAdmitsNoString(string $operator, Codec $codec): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'attribute "rank" of type "pets" declares the text operator "%s", and its codec admits no string',
            $operator,
        ));

        self::api(resources: new ResourceRegistry([Samples::resource(
            'pets',
            attributes: [Samples::attribute('rank', $codec, operators: ['@eq', $operator])],
        )]));
    }

    /**
     * @return iterable<string, array{string, Codec<mixed>}>
     */
    public static function textOperatorsOnNonStrings(): iterable
    {
        yield '@containsi on an integer' => ['@containsi', new IntegerCodec()];
        yield '@eqi on a nullable integer' => ['@eqi', new NullableCodec(new IntegerCodec())];
        yield '@startsWith on an int-backed enum' => ['@startsWith', new EnumCodec(Priority::class)];
        yield '@endsWithi on an object' => ['@endsWithi', new DescribedCodec(new Schema(type: JsonType::Object))];
    }

    /**
     * @param Codec<mixed> $codec
     * @param list<string> $declared
     */
    #[DataProvider('admittedOperators')]
    public function testAcceptsTheOperatorsTheCodecOfAnAttributeAdmits(Codec $codec, array $declared): void
    {
        $api = self::api(
            resources: new ResourceRegistry([Samples::resource(
                'pets',
                attributes: [Samples::attribute('rank', $codec, operators: $declared)],
            )]),
            operators: Samples::operators([OperatorMetadata::custom('@near', new StringCodec())]),
        );

        self::assertSame($declared, $api->resources->get('pets')->attributes['rank']->operators);
    }

    /**
     * @return iterable<string, array{Codec<mixed>, list<string>}>
     */
    public static function admittedOperators(): iterable
    {
        yield 'every text operator on a string' => [
            new StringCodec(),
            [
                '@eqi',
                '@nei',
                '@contains',
                '@notContains',
                '@containsi',
                '@notContainsi',
                '@startsWith',
                '@startsWithi',
                '@endsWith',
                '@endsWithi',
            ],
        ];
        yield 'a text operator on a nullable string' => [new NullableCodec(new StringCodec()), ['@containsi', '@null']];
        yield 'a text operator on a string-backed enum' => [new EnumCodec(Shade::class), ['@eqi']];
        yield 'a text operator on a schema without a type' => [new DescribedCodec(new Schema()), ['@contains']];
        yield 'scalar, list, boolean and pair operators on an integer' => [
            new IntegerCodec(),
            ['@eq', '@lt', '@in', '@notNull', '@between'],
        ];
        yield 'a custom operator on an integer' => [new IntegerCodec(), ['@near']];
        yield 'no operator' => [new IntegerCodec(), []];
    }

    public function testRefusesAnOperatorOfTheIdTheTableDoesNotHold(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the id of type "pets" declares operator "@far", which the table of operators does not hold',
        );

        self::api(resources: new ResourceRegistry([
            Samples::resource('pets', id: Samples::id(['@eq', '@far'])),
        ]));
    }

    public function testRefusesATextOperatorOnAnIdWhoseCodecAdmitsNoString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the id of type "pets" declares the text operator "@startsWith", and its codec admits no string',
        );

        self::api(resources: new ResourceRegistry([Samples::resource(
            'pets',
            id: Samples::id(['@startsWith'], new DescribedCodec(new Schema(type: JsonType::Integer))),
        )]));
    }

    public function testAcceptsTheOperatorsTheCodecOfAnIdAdmits(): void
    {
        $api = self::api(
            resources: new ResourceRegistry([
                Samples::resource('pets', id: Samples::id(['@eq', '@in', '@notIn', '@startsWith', '@near'])),
            ]),
            operators: Samples::operators([OperatorMetadata::custom('@near', new StringCodec())]),
        );

        self::assertSame(
            ['@eq', '@in', '@notIn', '@startsWith', '@near'],
            $api->resources->get('pets')->id->operators,
        );
    }

    public function testChecksTheOperatorsOfEveryResource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('attribute "name" of type "customers" declares operator "@far"');

        self::api(resources: new ResourceRegistry([
            Samples::resource('pets', attributes: [Samples::attribute('name', operators: ['@eq'])]),
            Samples::resource(
                'customers',
                attributes: [
                    Samples::attribute('age', new IntegerCodec(), operators: ['@gt']),
                    Samples::attribute('name', operators: ['@far']),
                ],
            ),
        ]));
    }

    /**
     * its routes clash only with an atomic route at `/operations`
     */
    public function testATypeNamedAfterTheAtomicRouteIsADeclaredType(): void
    {
        $api = self::api(resources: new ResourceRegistry([Samples::resource('operations')]));

        self::assertSame('operations', $api->resources->get('operations')->type);
    }

    public function testTheRoutesOfTheDeclarationAreBuiltWithIt(): void
    {
        $resources = new ResourceRegistry([Samples::resource('pets')]);
        $api = self::api(resources: $resources, transactionBoundary: ImplementationReference::service('boundary'));

        self::assertEquals(RouteTable::of($resources, [], [Samples::atomicRoute()]), $api->routes);
        self::assertEquals(RouteTable::of($resources, [], []), self::api(resources: $resources)->routes);
    }

    public function testADeclarationWhoseRoutesCollideIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the create_resource route of type "operations" on "/operations" and the atomic route'
            . ' "atomicOperations" on "/operations" both respond to POST',
        );

        self::api(
            resources: new ResourceRegistry([Samples::resource(
                'operations',
                capabilities: [Capability::ResourceCreator],
            )]),
            transactionBoundary: ImplementationReference::service('boundary'),
        );
    }

    public function testRefusesAMonitorTypeThatExcludesTheRouteOfItsResources(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" responds to create_resource with 202 and the monitor type "jobs",'
            . ' and a client checks the status of the write through the fetch_resource route of'
            . ' that type, so that type cannot exclude it without an endpoint in its place');

        self::api(resources: new ResourceRegistry([
            Samples::resource(
                'pets',
                capabilities: [Capability::ResourceCreator],
                monitorTypes: ['create_resource' => 'jobs'],
            ),
            Samples::resource('jobs', excluded: self::except(OperationKind::FetchResource)),
        ]));
    }

    /**
     * @param list<ResourceMetadata> $resources
     */
    #[DataProvider('unreadLoaders')]
    public function testRefusesAResourceLoaderThatIsReadAndNotBoundOrBoundAndNeverRead(
        array $resources,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::api(resources: new ResourceRegistry($resources));
    }

    /**
     * @return iterable<string, array{list<ResourceMetadata>, string}>
     */
    public static function unreadLoaders(): iterable
    {
        yield 'the fetch of a resource and no loader' => [
            [
                ResourceType::make('pets')
                    ->id(new DigitsCodec(), static function (Pet $pet): string {
                        return $pet->id;
                    })
                    ->permission(OperationKind::FetchResource, Implementation::service('permission'))
                    ->build(),
            ],
            'type "pets" binds no resource loader, and its fetch_resource route loads its target through it',
        ];
        yield 'an update beside an excluded fetch and no loader' => [
            [Samples::resource(
                'pets',
                capabilities: [Capability::ResourceUpdater],
                excluded: self::except(OperationKind::FetchResource),
            )],
            'type "pets" binds no resource loader, and its update_resource route loads its target through it',
        ];
        yield 'a to-one relationship with a related id accessor and no loader of its related type' => [
            [
                Samples::resource('pets', relationships: [Samples::toOne('owner', 'customers')]),
                Samples::resource('customers', excluded: self::except(OperationKind::FetchResource)),
            ],
            'type "customers" binds no resource loader, and the to-one relationship "owner" of type "pets" loads'
            . ' the resources it points at through it by id',
        ];
        yield 'a loader nothing reads' => [
            [Samples::resource(
                'customers',
                capabilities: [Capability::ResourceLoader],
                excluded: self::except(OperationKind::FetchResource),
            )],
            'type "customers" binds a resource loader, and nothing reads through it: the type enables no route on'
            . ' an existing resource, no to-one relationship with a related id accessor points at it, and no'
            . ' endpoint binds a parameter to it',
        ];
    }

    /**
     * @param list<ResourceMetadata> $resources
     */
    #[DataProvider('readLoaders')]
    public function testAcceptsAResourceLoaderExactlyWhereItIsRead(array $resources): void
    {
        self::assertCount(count($resources), self::api(resources: new ResourceRegistry($resources))->resources->all());
    }

    /**
     * @return iterable<string, array{list<ResourceMetadata>}>
     */
    public static function readLoaders(): iterable
    {
        yield 'no loader where nothing reads one' => [[
            Samples::resource(
                'customers',
                capabilities: [Capability::CollectionReader],
                excluded: self::except(OperationKind::FetchResource),
            ),
        ]];
        yield 'a loader read by a to-one relationship with a related id accessor' => [[
            Samples::resource('pets', relationships: [Samples::toOne('owner', 'customers')]),
            Samples::resource(
                'customers',
                capabilities: [Capability::ResourceLoader],
                excluded: self::except(OperationKind::FetchResource),
            ),
        ]];
        yield 'no loader where a to-one relationship reads its linkage through the related loader' => [[
            Samples::resource(
                'pets',
                relationships: [Samples::toOne('owner', 'customers', readsRelatedId: false)],
                capabilities: [Capability::RelatedLoader],
            ),
            Samples::resource('customers', excluded: self::except(OperationKind::FetchResource)),
        ]];
    }

    public function testTwoEndpointsOfOneNameAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the endpoint "adoptPet" and the endpoint "adoptPet" share their name, and the'
            . ' name is the operationId, which "MUST be unique among all operations described in the API" (OpenAPI)');

        self::api(endpoints: [Samples::endpoint(), Samples::endpoint(path: '/pets/{id}/rehome')]);
    }

    public function testAnAtomicRouteNamedAsAnEndpointIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the endpoint "adoptPet" and the atomic route "adoptPet" share their name');

        self::api(
            transactionBoundary: ImplementationReference::service('boundary'),
            endpoints: [Samples::endpoint()],
            atomicRoutes: [Samples::atomicRoute(name: 'adoptPet')],
        );
    }

    public function testTwoAtomicRoutesOfOneNameAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the atomic route "atomicOperations" and the atomic route "atomicOperations" share their name',
        );

        self::api(
            transactionBoundary: ImplementationReference::service('boundary'),
            atomicRoutes: [Samples::atomicRoute(), Samples::atomicRoute(path: '/pets/operations')],
        );
    }

    public function testSeveralAtomicRoutesOfTheirOwnNamesAreListed(): void
    {
        $routes = [
            Samples::atomicRoute(),
            Samples::atomicRoute(name: 'petOperations', path: '/pets/operations'),
        ];
        $api = self::api(
            transactionBoundary: ImplementationReference::service('boundary'),
            atomicRoutes: $routes,
        );

        self::assertSame($routes, $api->atomicRoutes);
    }

    public function testABoundaryWithoutAnAtomicRouteIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the API declares a transaction boundary and no atomic route, so no'
            . ' operation runs in it: declare the route with ApiBuilder::atomic()');

        self::api(transactionBoundary: ImplementationReference::service('boundary'), atomicRoutes: []);
    }

    public function testAnAtomicRouteWithoutABoundaryIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the atomic route "atomicOperations" runs its operations in one'
            . ' transaction, and the API declares no transaction boundary to provide it: declare one with'
            . ' ApiBuilder::transactionBoundary()');

        self::api(atomicRoutes: [Samples::atomicRoute()]);
    }

    /**
     * @param list<EndpointMetadata> $endpoints
     */
    #[DataProvider('parametersOfAToZAlone')]
    public function testTheStrictRuleRefusesACustomQueryParameterOfAToZAlone(
        array $endpoints,
        LanguageSources $languageSources,
        string $parameter,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            $parameter . ' holds a-z alone, and JSON:API keeps such names for the parameters it may define: a custom'
            . ' one "contains at least one non a-z character". ApiBuilder::strictQueryParameterNames(false) admits it',
        );

        self::api(endpoints: $endpoints, languageSources: $languageSources);
    }

    /**
     * @return iterable<string, array{list<EndpointMetadata>, LanguageSources, string}>
     */
    public static function parametersOfAToZAlone(): iterable
    {
        yield 'a language parameter' => [
            [],
            new LanguageSources([LanguageSourceMetadata::queryParameter('locale')]),
            'the language parameter "locale"',
        ];
        yield 'an endpoint query parameter' => [
            [Samples::endpoint(queryParameters: [new QueryParameterMetadata('format', new StringCodec(), false)])],
            Samples::languageSources(),
            'the query parameter "format" of the endpoint "adoptPet"',
        ];
    }

    public function testWithoutTheStrictRuleACustomQueryParameterOfAToZAloneIsAdmitted(): void
    {
        $endpoint = Samples::endpoint(queryParameters: [
            new QueryParameterMetadata('format', new StringCodec(), false),
        ]);

        $api = self::api(
            endpoints: [$endpoint],
            languageSources: new LanguageSources([LanguageSourceMetadata::queryParameter('locale')]),
            strictQueryParameterNames: false,
        );

        self::assertSame([$endpoint], $api->endpoints);
        self::assertSame(['locale'], $api->languageSources->queryParameters());
    }

    #[DataProvider('routeParametersOfAToZAlone')]
    public function testTheStrictRuleRefusesAQueryParameterOfARouteOfAToZAlone(
        ResourceRegistry $resources,
        string $parameter,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($parameter . ' holds a-z alone, and JSON:API keeps such names');

        self::api(resources: $resources);
    }

    /**
     * @return iterable<string, array{ResourceRegistry, string}>
     */
    public static function routeParametersOfAToZAlone(): iterable
    {
        $near = [new QueryParameterMetadata('near', new StringCodec(), false)];

        yield 'a parameter of a resource route' => [
            new ResourceRegistry([
                Samples::resource('pets', queryParameters: [OperationKind::FetchResource->value => $near]),
            ]),
            'the query parameter "near" of the fetch_resource route of type "pets"',
        ];
        yield 'a parameter of a relationship route' => [
            new ResourceRegistry([
                Samples::resource(
                    'pets',
                    relationships: [Samples::toMany('tags', 'tags', queryParameters: [
                        OperationKind::FetchRelated->value => $near,
                    ])],
                    capabilities: [Capability::RelatedLoader],
                ),
                Samples::resource('tags', capabilities: [Capability::CollectionReader]),
            ]),
            'the query parameter "near" of the fetch_related route of relationship "tags" of type "pets"',
        ];
    }

    public function testARouteParameterUnderTheNameOfALanguageParameterIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'type "pets" declares the query parameter "uiLocale" for its fetch_resource route, which names the language'
            . ' of a request on every route of the API',
        );

        self::api(
            resources: new ResourceRegistry([Samples::resource('pets', queryParameters: [
                OperationKind::FetchResource->value => [new QueryParameterMetadata(
                    'uiLocale',
                    new StringCodec(),
                    false,
                )],
            ])]),
            languageSources: new LanguageSources([LanguageSourceMetadata::queryParameter('uiLocale')]),
        );
    }

    public function testAnEndpointParameterUnderTheNameOfALanguageParameterIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the endpoint "adoptPet" declares the query parameter "uiLocale", which names the language of a request on'
            . ' every route of the API',
        );

        self::api(
            endpoints: [Samples::endpoint(queryParameters: [
                new QueryParameterMetadata('uiLocale', new StringCodec(), false),
            ])],
            languageSources: new LanguageSources([
                LanguageSourceMetadata::acceptLanguage(),
                LanguageSourceMetadata::queryParameter('uiLocale'),
            ]),
        );
    }

    public function testAnEndpointParameterOfAnotherNameThanTheLanguageParameterIsAccepted(): void
    {
        $endpoint = Samples::endpoint(queryParameters: [
            new QueryParameterMetadata('uiTheme', new StringCodec(), false),
        ]);

        self::assertSame(
            [$endpoint],
            self::api(
                endpoints: [$endpoint],
                languageSources: new LanguageSources([LanguageSourceMetadata::queryParameter('uiLocale')]),
            )->endpoints,
        );
    }

    public function testAMonitorTypeWhoseFetchAnEndpointRespondsToIsAccepted(): void
    {
        $api = self::api(
            resources: new ResourceRegistry([
                Samples::resource(
                    'pets',
                    capabilities: [Capability::ResourceCreator],
                    monitorTypes: ['create_resource' => 'jobs'],
                ),
                Samples::resource(
                    'jobs',
                    capabilities: [Capability::ResourceLoader],
                    excluded: self::except(OperationKind::FetchResource),
                ),
            ]),
            endpoints: [Samples::endpoint('jobStatus', 'GET', '/jobs/{id}', ['id' => 'jobs'], responses: [
                200 => new ResponseMetadata(ResponseShapeKind::Record, 'jobs', 'id', null, null, null),
            ])],
        );

        self::assertTrue($api->routes->fetches(RouteKind::Resource, 'jobs', null));
    }

    public function testAnEndpointWhoseMonitorTypeExcludesItsFetchIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the endpoint "adoptPet" responds with 202 and the monitor type "jobs", and a'
            . ' client checks the status of the write through the fetch_resource route of'
            . ' that type, so that type cannot exclude it without an endpoint in its place');

        self::api(
            resources: new ResourceRegistry([
                Samples::resource('pets'),
                Samples::resource('jobs', excluded: self::except(OperationKind::FetchResource)),
            ]),
            endpoints: [Samples::endpoint(responses: [202 => new ResponseMetadata(
                ResponseShapeKind::Accepted,
                'jobs',
                null,
                null,
                null,
                null,
            )])],
        );
    }

    public function testAnEndpointThatBindsATypeReadsItsResourceLoader(): void
    {
        $api = self::api(
            resources: new ResourceRegistry([
                Samples::resource(
                    'pets',
                    capabilities: [Capability::CollectionReader, Capability::ResourceLoader],
                    excluded: self::except(OperationKind::FetchResource),
                ),
            ]),
            endpoints: [Samples::endpoint()],
        );

        self::assertCount(1, $api->endpoints);
    }

    public function testAnEndpointThatBindsATypeWithoutAResourceLoaderIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" binds no resource loader, and the endpoint "adoptPet" loads the'
            . ' record of its parameter "id" through it');

        self::api(
            resources: new ResourceRegistry([
                Samples::resource(
                    'pets',
                    capabilities: [Capability::CollectionReader],
                    excluded: self::except(OperationKind::FetchResource),
                ),
            ]),
            endpoints: [Samples::endpoint()],
        );
    }

    public function testATypeDefaultSortNothingReadsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" declares a default sort that nothing reads: its collection route is'
            . ' not enabled, since the type binds no collection reader or excludes the route, and no endpoint responds'
            . ' with a page of the type without a default sort of its own');

        self::api(resources: self::sortedPets());
    }

    public function testATypeDefaultSortOnlyAPageEndpointWithASortOfItsOwnPagesIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" declares a default sort that nothing reads');

        self::api(
            resources: self::sortedPets(),
            endpoints: [Samples::pageEndpoint(defaultSort: Sort::fromString('-name'))],
        );
    }

    public function testAPageEndpointWithoutADefaultSortReadsTheOneOfItsType(): void
    {
        $api = self::api(resources: self::sortedPets(), endpoints: [Samples::pageEndpoint()]);

        self::assertSame('name', $api->resources->endpointDefaultSort($api->endpoints[0])?->toString());
    }

    public function testTheDefaultSortOfAnEndpointNamesAPathItsPageSortsBy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the default sort of the endpoint "searchPets": sort path "age" ends at "age",'
            . ' which is no sortable attribute of type "pets"');

        self::api(
            resources: new ResourceRegistry([Samples::resource('pets')]),
            endpoints: [Samples::pageEndpoint(defaultSort: Sort::fromString('age'))],
        );
    }

    public function testTheDefaultSortOfAnEndpointNeverOrdersByANullablePath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the default sort of the endpoint "searchPets" orders by "nickname", and'
            . ' attribute "nickname" of type "pets" may be null');

        $nickname = Samples::attribute('nickname', new NullableCodec(new StringCodec()), sortable: true);

        self::api(
            resources: new ResourceRegistry([Samples::resource('pets', attributes: [$nickname])]),
            endpoints: [Samples::pageEndpoint(defaultSort: Sort::fromString('nickname'))],
        );
    }

    /**
     * pets without a collection reader, sorted by name by default
     */
    private static function sortedPets(): ResourceRegistry
    {
        return new ResourceRegistry([Samples::resource(
            'pets',
            attributes: [Samples::attribute('name', sortable: true)],
            defaultSort: Sort::fromString('name'),
        )]);
    }

    private static function except(OperationKind $kind): RouteExclusion
    {
        return RouteExclusion::except(RouteOwner::Resource, [$kind], 'a type');
    }

    /**
     * the API declares Application Passwords; the fetch of a pet the nonce,
     * the related route of its owner none, the endpoint a signature beside Application Passwords,
     * the atomic route the nonce
     */
    private static function schemedApi(SecurityScheme $signature): ApiMetadata
    {
        $resources = new ResourceRegistry([
            Samples::resource(
                'pets',
                relationships: [Samples::toOne(
                    'owner',
                    'pets',
                    securitySchemes: [OperationKind::FetchRelated->value => []],
                )],
                securitySchemes: [OperationKind::FetchResource->value => [SecurityScheme::restNonce()]],
            ),
        ]);

        return self::api(
            resources: $resources,
            transactionBoundary: ImplementationReference::service('boundary'),
            endpoints: [Samples::endpoint(securitySchemes: [$signature, SecurityScheme::applicationPasswords()])],
            atomicRoutes: [Samples::atomicRoute(securitySchemes: [SecurityScheme::restNonce()])],
        );
    }

    /**
     * without its own routes, an API with a boundary takes `/operations` of every type
     *
     * @param ?array<string, OperatorMetadata> $operators
     * @param ?list<SecurityScheme>            $securitySchemes
     * @param list<EndpointMetadata>           $endpoints
     * @param ?list<AtomicRouteMetadata>       $atomicRoutes
     */
    private static function api(
        string $name = 'petstore',
        string $version = '1.0.0',
        string $restNamespace = 'acme/v1',
        ?ResourceRegistry $resources = null,
        ?array $operators = null,
        ?array $securitySchemes = null,
        ?ImplementationReference $transactionBoundary = null,
        array $endpoints = [],
        ?LanguageSources $languageSources = null,
        NamingPolicy $naming = NamingPolicy::SnakeCase,
        bool $strictQueryParameterNames = true,
        ?array $atomicRoutes = null,
    ): ApiMetadata {
        $resources ??= new ResourceRegistry([Samples::resource('pets')]);

        return new ApiMetadata(
            $name,
            $version,
            $restNamespace,
            $resources,
            $endpoints,
            $atomicRoutes ?? Samples::atomicRoutes($resources, $transactionBoundary),
            $operators ?? Samples::operators(),
            $securitySchemes ?? [SecurityScheme::applicationPasswords()],
            $transactionBoundary,
            $languageSources ?? Samples::languageSources(),
            $naming,
            $strictQueryParameterNames,
        );
    }
}
