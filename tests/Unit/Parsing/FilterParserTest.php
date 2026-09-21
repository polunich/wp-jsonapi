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
use OutOfBoundsException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\BooleanCodec;
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Codec\DecodeException;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\NullableCodec;
use Polunich\WpJsonApi\Codec\NumberCodec;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Codec\Schema\Schema;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Codec\UuidCodec;
use Polunich\WpJsonApi\Codec\Violation;
use Polunich\WpJsonApi\Codec\ViolationCode;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Error\StatusSelector;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\OperatorMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Parsing\FilterParser;
use Polunich\WpJsonApi\Query\Filter\AllOf;
use Polunich\WpJsonApi\Query\Filter\AnyOf;
use Polunich\WpJsonApi\Query\Filter\Condition;
use Polunich\WpJsonApi\Query\Filter\FilterNode;
use Polunich\WpJsonApi\Query\Filter\Not;
use Polunich\WpJsonApi\Query\Filter\RelationshipCondition;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use stdClass;

/**
 * @internal
 */
#[CoversClass(FilterParser::class)]
final class FilterParserTest extends TestCase
{
    public function testReadsAComparisonIntoACondition(): void
    {
        $errors = self::errorList();

        $node = self::parser()->parse(['views' => ['@gt' => '18']], 'pets', $errors);

        self::assertInstanceOf(Condition::class, $node);
        self::assertSame('views', $node->field->toString());
        self::assertSame('@gt', $node->operator);
        self::assertSame(18, $node->value);
        self::assertNull($errors->exception());
    }

    /**
     * @param array<array-key, mixed> $filter
     */
    #[DataProvider('conjunctions')]
    public function testReadsTheMembersOfOneObjectAsAConjunction(array $filter, string $tree): void
    {
        self::assertSame($tree, self::read($filter));
    }

    /**
     * @return array<string, array{array<array-key, mixed>, string}>
     */
    public static function conjunctions(): array
    {
        return [
            'two fields' => [
                ['name' => ['@eq' => 'Kyiv'], 'views' => ['@gt' => '18']],
                '@and(name@eq="Kyiv",views@gt=18)',
            ],
            'two operators on one field' => [
                ['views' => ['@gt' => '18', '@eq' => '20']],
                '@and(views@gt=18,views@eq=20)',
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $filter
     */
    #[DataProvider('logicalOperators')]
    public function testReadsTheLogicalOperatorsAndOrAndNot(array $filter, string $tree): void
    {
        self::assertSame($tree, self::read($filter));
    }

    /**
     * @return array<string, array{array<array-key, mixed>, string}>
     */
    public static function logicalOperators(): array
    {
        return [
            '@or' => [
                ['@or' => [['name' => ['@eq' => 'Kyiv']], ['name' => ['@eq' => 'Lviv']]]],
                '@or(name@eq="Kyiv",name@eq="Lviv")',
            ],
            '@and' => [
                ['@and' => [['name' => ['@eq' => 'Kyiv']], ['views' => ['@gt' => '18']]]],
                '@and(name@eq="Kyiv",views@gt=18)',
            ],
            '@and of one condition' => [
                ['@and' => [['name' => ['@eq' => 'Kyiv']]]],
                '@and(name@eq="Kyiv")',
            ],
            '@not where a condition stands' => [
                ['@not' => ['name' => ['@eq' => 'Kyiv']]],
                '@not(name@eq="Kyiv")',
            ],
            '@not inside a field' => [
                ['name' => ['@not' => ['@eq' => 'Kyiv']]],
                '@not(name@eq="Kyiv")',
            ],
            '@not over a conjunction inside a field' => [
                ['views' => ['@not' => ['@gt' => '18', '@eq' => '20']]],
                '@not(@and(views@gt=18,views@eq=20))',
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $filter
     */
    #[DataProvider('relationshipPaths')]
    public function testReadsBothFormsOfARelationshipPathAsOneShape(array $filter, string $tree): void
    {
        self::assertSame($tree, self::read($filter));
    }

    /**
     * @return array<string, array{array<array-key, mixed>, string}>
     */
    public static function relationshipPaths(): array
    {
        return [
            'the relationship as a key' => [
                ['owner' => ['name' => ['@eq' => 'Lesya']]],
                'owner(name@eq="Lesya")',
            ],
            'a to-many relationship, which matches when one related resource matches' => [
                ['orders' => ['address' => ['@eq' => 'yes']]],
                'orders(address@eq="yes")',
            ],
            'two fields of a to-many relationship, which one related resource holds together' => [
                ['orders' => ['address' => ['@eq' => 'yes'], 'status' => ['@eq' => 'paid']]],
                'orders(@and(address@eq="yes",status@eq="paid"))',
            ],
            'two fields of a to-many relationship, which two related resources may hold apart' => [
                [
                    '@and' => [
                        ['orders' => ['address' => ['@eq' => 'yes']]],
                        ['orders' => ['status' => ['@eq' => 'paid']]],
                    ],
                ],
                '@and(orders(address@eq="yes"),orders(status@eq="paid"))',
            ],
            'a group over the fields of the related type' => [
                ['owner' => ['@or' => [['name' => ['@eq' => 'Lesya']], ['name' => ['@eq' => 'Ivan']]]]],
                'owner(@or(name@eq="Lesya",name@eq="Ivan"))',
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $filter
     */
    #[DataProvider('ids')]
    public function testReadsAConditionOnTheIdWithTheIdCodec(array $filter, string $tree): void
    {
        self::assertSame($tree, self::read($filter));
    }

    /**
     * @return array<string, array{array<array-key, mixed>, string}>
     */
    public static function ids(): array
    {
        return [
            'the id of the type' => [['id' => ['@eq' => '7']], 'id@eq="7"'],
            'a list of ids' => [['id' => ['@in' => ['7', '9']]], 'id@in=["7","9"]'],
            'no id of a list' => [['id' => ['@not' => ['@in' => ['7']]]], '@not(id@in=["7"])'],
            'the id of a related resource' => [
                ['owner' => ['id' => ['@eq' => '00000000-0000-4000-8000-000000000001']]],
                'owner(id@eq="00000000-0000-4000-8000-000000000001")',
            ],
            'the id beside an attribute of the related resource' => [
                ['owner' => ['id' => ['@eq' => '00000000-0000-4000-8000-000000000001'], 'name' => ['@eq' => 'Lesya']]],
                'owner(@and(id@eq="00000000-0000-4000-8000-000000000001",name@eq="Lesya"))',
            ],
        ];
    }

    public function testDecodesAnIdByTheCodecOfItsType(): void
    {
        $errors = self::errorList();

        self::assertNull(self::parser()->parse(
            ['id' => ['@eq' => 'abc'], 'owner' => ['id' => ['@eq' => '7']]],
            'pets',
            $errors,
        ));
        self::assertSame(
            [ViolationCode::PatternMismatch->value, ViolationCode::InvalidFormat->value],
            self::codes($errors),
        );
        self::assertSame('filter[id][@eq]', self::problems($errors)[0]->parameter);
        self::assertSame('filter[owner][id][@eq]', self::problems($errors)[1]->parameter);
    }

    /**
     * @param array<array-key, mixed> $filter
     */
    #[DataProvider('arrayValues')]
    public function testConvertsTheItemsOfAnArrayValue(array $filter, string $tree): void
    {
        self::assertSame($tree, self::read($filter));
    }

    /**
     * @return array<string, array{array<array-key, mixed>, string}>
     */
    public static function arrayValues(): array
    {
        return [
            'a list' => [['views' => ['@in' => ['1', '2']]], 'views@in=[1,2]'],
            'a pair' => [['views' => ['@between' => ['1', '5']]], 'views@between=[1,5]'],
            'a list of strings' => [['name' => ['@in' => ['Kyiv']]], 'name@in=["Kyiv"]'],
            'an object, member by member' => [['meta' => ['@eq' => ['a' => '1', 'b' => '2']]], 'meta@eq={"a":1,"b":2}'],
        ];
    }

    public function testReadsTheBooleanOfANullOperator(): void
    {
        self::assertSame('name@null=true', self::read(['name' => ['@null' => 'true']]));
        self::assertSame('name@notNull=false', self::read(['name' => ['@not_null' => 'false']]));
    }

    #[DataProvider('spelledOperators')]
    public function testReadsABuiltInOperatorAsTheNamingPolicySpellsIt(NamingPolicy $naming, string $written): void
    {
        $errors = self::errorList();
        $node = self::parser($naming)->parse(['name' => [$written => 'false']], 'pets', $errors);

        self::assertNull($errors->exception());
        self::assertNotNull($node);
        self::assertSame('name@notNull=false', self::render($node));
    }

    /**
     * @return array<string, array{NamingPolicy, string}>
     */
    public static function spelledOperators(): array
    {
        return [
            'snake case' => [NamingPolicy::SnakeCase, '@not_null'],
            'camel case' => [NamingPolicy::CamelCase, '@notNull'],
        ];
    }

    #[DataProvider('misspelledOperators')]
    public function testRefusesABuiltInOperatorSpelledByTheOtherPolicy(NamingPolicy $naming, string $written): void
    {
        $errors = self::errorList();

        self::assertNull(self::parser($naming)->parse(['name' => [$written => 'false']], 'pets', $errors));
        self::assertSame([ErrorCode::UnsupportedFilterOperator->value], self::codes($errors));
        self::assertSame(['field' => 'name', 'operator' => $written], self::problems($errors)[0]->parameters);
        self::assertSame('filter[name][' . $written . ']', self::problems($errors)[0]->parameter);
    }

    /**
     * @return array<string, array{NamingPolicy, string}>
     */
    public static function misspelledOperators(): array
    {
        return [
            'camel case under snake case' => [NamingPolicy::SnakeCase, '@notNull'],
            'snake case under camel case' => [NamingPolicy::CamelCase, '@not_null'],
        ];
    }

    public function testReadsACustomOperatorAsDeclaredUnderSnakeCase(): void
    {
        self::assertSame('views@nearBy="12"', self::read(['views' => ['@nearBy' => '12']]));
    }

    /**
     * `@near` decodes with its own string codec,
     * so `12` stays a string on the integer field `views`
     */
    public function testDecodesACustomOperatorWithItsOwnCodec(): void
    {
        self::assertSame('views@near="12"', self::read(['views' => ['@near' => '12']]));
    }

    #[DataProvider('texts')]
    public function testTurnsTheTextOfAValueIntoTheWireFormTheCodecReads(
        string $field,
        string $text,
        string $tree,
    ): void {
        self::assertSame($tree, self::read([$field => ['@eq' => $text]]));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function texts(): array
    {
        return [
            'an integer' => ['views', '18', 'views@eq=18'],
            'a negative integer' => ['views', '-18', 'views@eq=-18'],
            'a number' => ['rating', '1.5', 'rating@eq=1.5'],
            'a number written with an exponent' => ['rating', '2e2', 'rating@eq=200'],
            'an integer where a number is admitted' => ['rating', '3', 'rating@eq=3'],
            // JSON Schema counts any number with a zero fraction as an `integer`
            'an integer written with a zero fraction' => ['views', '18.0', 'views@eq=18'],
            'an integer written with a minus zero' => ['views', '-0', 'views@eq=0'],
            'an integer written with an exponent' => ['views', '1e2', 'views@eq=100'],
            'true' => ['draft', 'true', 'draft@eq=true'],
            'false' => ['draft', 'false', 'draft@eq=false'],
            'null where the codec admits it' => ['code', 'null', 'code@eq=null'],
            'null where the codec admits a string only' => ['name', 'null', 'name@eq="null"'],
            'digits where the codec admits a string only' => ['name', '18', 'name@eq="18"'],
        ];
    }

    /**
     * past `PHP_INT_MAX` the text decodes to a float,
     * which the integer codec refuses by its bound, as in a document
     */
    public function testANumberBeyondThePlatformIsRefusedByTheBoundOfTheCodec(): void
    {
        $errors = self::errorList();

        self::assertNull(self::parser()->parse(['views' => ['@eq' => '9223372036854775808']], 'pets', $errors));
        self::assertSame(['value_too_large'], self::codes($errors));
        self::assertSame('filter[views][@eq]', self::problems($errors)[0]->parameter);
    }

    #[DataProvider('unreadableTexts')]
    public function testLeavesTextThatIsNoValueOfAnAdmittedTypeToTheCodec(
        string $field,
        string $text,
        string $type,
    ): void {
        $errors = self::errorList();

        self::assertNull(self::parser()->parse([$field => ['@eq' => $text]], 'pets', $errors));
        self::assertSame(['invalid_type'], self::codes($errors));
        self::assertSame(['expected' => $type], self::problems($errors)[0]->parameters);
        self::assertSame('filter[' . $field . '][@eq]', self::problems($errors)[0]->parameter);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function unreadableTexts(): array
    {
        return [
            'a leading zero' => ['views', '018', 'integer'],
            'a fraction where an integer is admitted' => ['views', '3.5', 'integer'],
            'a leading zero where a number is admitted' => ['rating', '018', 'number'],
            'two decimal points' => ['rating', '1.2.3', 'number'],
            'a fraction without an int' => ['rating', '.5', 'number'],
            'a decimal point without a fraction' => ['rating', '1.', 'number'],
            'a number written with a plus' => ['rating', '+1', 'number'],
            'an exponent without digits' => ['rating', '1e', 'number'],
            'a number that is not finite' => ['rating', '1e999', 'number'],
            'a boolean written as a digit' => ['draft', '1', 'boolean'],
            'a boolean written in capitals' => ['draft', 'TRUE', 'boolean'],
            'an object where the family writes a scalar' => ['views', 'many', 'integer'],
        ];
    }

    public function testNamesTheParameterOfAViolationAsTheClientWroteIt(): void
    {
        $errors = self::errorList();

        self::assertNull(self::parser()->parse(['views' => ['@in' => ['1', 'x', 'y']]], 'pets', $errors));
        self::assertSame(['invalid_type', 'invalid_type'], self::codes($errors));
        self::assertSame('filter[views][@in][1]', self::problems($errors)[0]->parameter);
        self::assertSame('filter[views][@in][2]', self::problems($errors)[1]->parameter);
    }

    public function testAnArrayTheClientDidNotWriteAsAListIsRefused(): void
    {
        $errors = self::errorList();

        self::assertNull(self::parser()->parse(['views' => ['@in' => [1 => '1', 3 => '2']]], 'pets', $errors));
        self::assertSame(['invalid_type'], self::codes($errors));
        self::assertSame(['expected' => 'array'], self::problems($errors)[0]->parameters);
        self::assertSame('filter[views][@in]', self::problems($errors)[0]->parameter);
    }

    public function testUnescapesAReferenceTokenOfAViolationIntoTheKeyTheClientWrote(): void
    {
        $errors = self::errorList();

        self::assertNull(self::parser()->parse(
            ['meta' => ['@eq' => ['a/b' => 'x', 'c~d' => 'y']]],
            'pets',
            $errors,
        ));
        self::assertSame(['invalid_type', 'invalid_type'], self::codes($errors));
        self::assertSame('filter[meta][@eq][a/b]', self::problems($errors)[0]->parameter);
        self::assertSame('filter[meta][@eq][c~d]', self::problems($errors)[1]->parameter);
    }

    public function testNamesTheParameterOfTheOperatorWhereTheViolationPointsAtTheValue(): void
    {
        $errors = self::errorList();

        self::assertNull(self::parser()->parse(['views' => ['@between' => ['1']]], 'pets', $errors));
        self::assertSame(['too_few_items'], self::codes($errors));
        self::assertSame('filter[views][@between]', self::problems($errors)[0]->parameter);
        self::assertSame(['minItems' => 2], self::problems($errors)[0]->parameters);
    }

    /**
     * @param array<array-key, mixed> $filter
     * @param array<string, scalar>   $parameters
     */
    #[DataProvider('unsupportedFields')]
    public function testRefusesAKeyThatNamesNoFilterableField(array $filter, array $parameters, string $parameter): void
    {
        $errors = self::errorList();

        self::assertNull(self::parser()->parse($filter, 'pets', $errors));
        self::assertSame([ErrorCode::UnsupportedFilterField->value], self::codes($errors));
        self::assertSame($parameters, self::problems($errors)[0]->parameters);
        self::assertSame($parameter, self::problems($errors)[0]->parameter);
    }

    /**
     * @return array<string, array{array<array-key, mixed>, array<string, scalar>, string}>
     */
    public static function unsupportedFields(): array
    {
        return [
            'a field the type does not declare' => [
                ['bogus' => ['@eq' => 'x']],
                ['type' => 'pets', 'field' => 'bogus'],
                'filter[bogus]',
            ],
            'a field that declares no operator' => [
                ['description' => ['@eq' => 'x']],
                ['type' => 'pets', 'field' => 'description'],
                'filter[description]',
            ],
            'a key that is no field path' => [
                ['a..b' => ['@eq' => 'x']],
                ['type' => 'pets', 'field' => 'a..b'],
                'filter[a..b]',
            ],
            'an operator where a field stands' => [
                ['@eq' => 'x'],
                ['type' => 'pets', 'field' => '@eq'],
                'filter[@eq]',
            ],
            'a relationship the type does not declare' => [
                ['ghost' => ['name' => ['@eq' => 'x']]],
                ['type' => 'pets', 'field' => 'ghost'],
                'filter[ghost]',
            ],
            'a relationship of a related type beyond the include depth' => [
                ['owner' => ['supplier' => ['name' => ['@eq' => 'x']]]],
                ['type' => 'customers', 'field' => 'supplier'],
                'filter[owner][supplier]',
            ],
            'the id of a related type that declares no operator for it' => [
                ['orders' => ['id' => ['@eq' => '1']]],
                ['type' => 'orders', 'field' => 'id'],
                'filter[orders][id]',
            ],
            'the id of a related type beyond the include depth' => [
                ['owner' => ['supplier' => ['id' => ['@eq' => '1']]]],
                ['type' => 'customers', 'field' => 'supplier'],
                'filter[owner][supplier]',
            ],
            'a dotted path, which a condition through a relationship is not written as' => [
                ['owner.name' => ['@eq' => 'x']],
                ['type' => 'pets', 'field' => 'owner.name'],
                'filter[owner.name]',
            ],
            'a dotted path to the id of a related type' => [
                ['owner.id' => ['@in' => ['00000000-0000-4000-8000-000000000001']]],
                ['type' => 'pets', 'field' => 'owner.id'],
                'filter[owner.id]',
            ],
            'a dotted path inside a nested one' => [
                ['owner' => ['supplier.name' => ['@eq' => 'x']]],
                ['type' => 'customers', 'field' => 'supplier.name'],
                'filter[owner][supplier.name]',
            ],
        ];
    }

    public function testFiltersThroughAsManyRelationshipsAsTheMaxIncludeDepthOfTheTypeAllows(): void
    {
        $errors = self::errorList();

        $node = self::parser()->parse(['owner' => ['supplier' => ['name' => ['@eq' => 'Acme']]]], 'reports', $errors);

        self::assertNotNull($node);
        self::assertSame('owner(supplier(name@eq="Acme"))', self::render($node));
        self::assertNull($errors->exception());
    }

    /**
     * @param array<array-key, mixed> $filter
     * @param array<string, scalar>   $parameters
     */
    #[DataProvider('unsupportedOperators')]
    public function testRefusesAnOperatorTheFieldDoesNotAdmit(array $filter, array $parameters, string $parameter): void
    {
        $errors = self::errorList();

        self::assertNull(self::parser()->parse($filter, 'pets', $errors));
        self::assertSame([ErrorCode::UnsupportedFilterOperator->value], self::codes($errors));
        self::assertSame($parameters, self::problems($errors)[0]->parameters);
        self::assertSame($parameter, self::problems($errors)[0]->parameter);
    }

    /**
     * @return array<string, array{array<array-key, mixed>, array<string, scalar>, string}>
     */
    public static function unsupportedOperators(): array
    {
        return [
            'a built-in operator the field does not declare' => [
                ['name' => ['@lt' => 'x']],
                ['field' => 'name', 'operator' => '@lt'],
                'filter[name][@lt]',
            ],
            'a name the table of the API does not hold' => [
                ['name' => ['@bogus' => 'x']],
                ['field' => 'name', 'operator' => '@bogus'],
                'filter[name][@bogus]',
            ],
            'a custom operator the field does not declare' => [
                ['name' => ['@near' => 'x']],
                ['field' => 'name', 'operator' => '@near'],
                'filter[name][@near]',
            ],
            'a logical operator inside a field' => [
                ['name' => ['@or' => [['@eq' => 'x']]]],
                ['field' => 'name', 'operator' => '@or'],
                'filter[name][@or]',
            ],
            'a field name where an operator stands' => [
                ['name' => ['views' => 'x']],
                ['field' => 'name', 'operator' => 'views'],
                'filter[name][views]',
            ],
            'an operator the id does not declare' => [
                ['id' => ['@gt' => '1']],
                ['field' => 'id', 'operator' => '@gt'],
                'filter[id][@gt]',
            ],
        ];
    }

    #[DataProvider('unreadableValues')]
    public function testRefusesAValueThatIsNoFilterObject(mixed $filter, string $parameter): void
    {
        $errors = self::errorList();

        self::assertNull(self::parser()->parse($filter, 'pets', $errors));
        self::assertSame([ErrorCode::InvalidQueryParameter->value], self::codes($errors));
        self::assertSame(['name' => $parameter], self::problems($errors)[0]->parameters);
        self::assertSame($parameter, self::problems($errors)[0]->parameter);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function unreadableValues(): array
    {
        return [
            'text where the filter stands' => ['Kyiv', 'filter'],
            'the empty object' => [[], 'filter'],
            'text where a field writes its comparisons' => [['name' => 'Kyiv'], 'filter[name]'],
            'the empty object of a field' => [['name' => []], 'filter[name]'],
            'text where a group writes its children' => [['@or' => 'Kyiv'], 'filter[@or]'],
            'the empty group' => [['@and' => []], 'filter[@and]'],
            'text where a relationship writes its condition' => [['owner' => 'Lesya'], 'filter[owner]'],
            'text where "@not" writes its condition' => [['@not' => 'Kyiv'], 'filter[@not]'],
        ];
    }

    public function testCollectsEveryMistakeOfTheFilter(): void
    {
        $errors = self::errorList();

        $node = self::parser()->parse([
            'bogus' => ['@eq' => 'x'],
            'name' => ['@lt' => 'x'],
            '@or' => [['views' => ['@eq' => 'x']], ['owner' => ['name' => ['@lt' => 'y']]]],
        ], 'pets', $errors);

        self::assertNull($node);
        self::assertSame(
            [
                ErrorCode::UnsupportedFilterField->value,
                ErrorCode::UnsupportedFilterOperator->value,
                'invalid_type',
                ErrorCode::UnsupportedFilterOperator->value,
            ],
            self::codes($errors),
        );
        self::assertSame(400, $errors->exception()?->status());
    }

    public function testReturnsNullWhenOneMemberOfTheTreeIsRefused(): void
    {
        $errors = self::errorList();

        self::assertNull(self::parser()->parse(
            ['@or' => [['name' => ['@eq' => 'Kyiv']], ['bogus' => ['@eq' => 'x']]]],
            'pets',
            $errors,
        ));
        self::assertSame([ErrorCode::UnsupportedFilterField->value], self::codes($errors));
    }

    public function testReadsAKeyOfDigitsAsTheNameItIs(): void
    {
        $errors = self::errorList();

        self::assertNull(self::parser()->parse([0 => ['@eq' => 'x']], 'pets', $errors));
        self::assertSame(['type' => 'pets', 'field' => '0'], self::problems($errors)[0]->parameters);
        self::assertSame('filter[0]', self::problems($errors)[0]->parameter);
    }

    public function testReturnsNoTreeWhereTheRequestWritesNoFilter(): void
    {
        $errors = self::errorList();

        self::assertNull(self::parser()->parse(null, 'pets', $errors));
        self::assertNull($errors->exception());
    }

    public function testRefusesATypeThatIsNotDeclared(): void
    {
        $this->expectException(OutOfBoundsException::class);

        self::parser()->parse(['name' => ['@eq' => 'x']], 'ghosts', self::errorList());
    }

    /**
     * the rendered tree; any collected problem fails the test
     *
     * @param array<array-key, mixed> $filter
     */
    private static function read(array $filter): string
    {
        $errors = self::errorList();
        $node = self::parser()->parse($filter, 'pets', $errors);

        self::assertNull($errors->exception());
        self::assertNotNull($node);

        return self::render($node);
    }

    private static function render(FilterNode $node): string
    {
        if ($node instanceof Condition) {
            return $node->field->toString() . $node->operator . '=' . json_encode($node->value, JSON_THROW_ON_ERROR);
        }

        if ($node instanceof AllOf) {
            return '@and(' . implode(',', array_map(self::render(...), $node->nodes)) . ')';
        }

        if ($node instanceof AnyOf) {
            return '@or(' . implode(',', array_map(self::render(...), $node->nodes)) . ')';
        }

        if ($node instanceof Not) {
            return '@not(' . self::render($node->node) . ')';
        }

        self::assertInstanceOf(RelationshipCondition::class, $node);

        return $node->relationship . '(' . self::render($node->condition) . ')';
    }

    /**
     * `pets` keeps the default include depth of 1; `reports` raises it to 2.
     * the ids of `pets` (digits) and of `customers` (UUIDs) declare filter operators;
     * the id of `orders` declares none
     */
    private static function parser(NamingPolicy $naming = NamingPolicy::SnakeCase): FilterParser
    {
        $suppliers = Samples::resource('suppliers', attributes: [Samples::attribute('name', operators: ['@eq'])]);

        $customers = Samples::resource(
            'customers',
            attributes: [Samples::attribute('name', operators: ['@eq', '@contains'])],
            relationships: [Samples::toOne('supplier', 'suppliers')],
            id: Samples::id(['@eq', '@in'], new UuidCodec()),
        );

        $pets = Samples::resource(
            'pets',
            attributes: [
                Samples::attribute('name', operators: ['@eq', '@contains', '@in', '@null', '@notNull']),
                Samples::attribute(
                    'views',
                    codec: new IntegerCodec(),
                    operators: ['@eq', '@gt', '@in', '@between', '@near', '@nearBy'],
                ),
                Samples::attribute('rating', codec: new NumberCodec(), operators: ['@eq']),
                Samples::attribute('draft', codec: new BooleanCodec(), operators: ['@eq']),
                Samples::attribute('code', codec: new NullableCodec(new StringCodec()), operators: ['@eq']),
                Samples::attribute('meta', codec: self::members(), operators: ['@eq']),
                Samples::attribute('description'),
            ],
            relationships: [
                Samples::toOne('owner', 'customers'),
                Samples::toMany('orders', 'orders', includable: false),
            ],
            capabilities: [Capability::CollectionReader],
            id: Samples::id(['@eq', '@in']),
        );

        $orders = Samples::resource(
            'orders',
            attributes: [
                Samples::attribute('address', operators: ['@eq']),
                Samples::attribute('status', operators: ['@eq']),
            ],
            capabilities: [Capability::CollectionReader],
        );

        $reports = Samples::resource(
            'reports',
            relationships: [Samples::toOne('owner', 'customers')],
            maxIncludeDepth: 2,
        );

        return new FilterParser(new ApiMetadata(
            'an API',
            '1.0.0',
            'acme/v1',
            new ResourceRegistry([$pets, $customers, $suppliers, $orders, $reports]),
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
        ));
    }

    /**
     * @return Codec<mixed>
     */
    private static function members(): Codec
    {
        return new class implements Codec {
            /**
             * @return array<string, int>
             */
            #[Override]
            public function decode(mixed $input, Pointer $pointer): array
            {
                if (!$input instanceof stdClass) {
                    throw new DecodeException([
                        new Violation($pointer, 'invalid_type', ['expected' => JsonType::Object->value]),
                    ]);
                }

                $violations = [];
                $decoded = [];

                foreach (get_object_vars($input) as $key => $value) {
                    $name = (string) $key;

                    if (!is_int($value)) {
                        $violations[] = new Violation(
                            $pointer->descend($name),
                            'invalid_type',
                            ['expected' => JsonType::Integer->value],
                        );

                        continue;
                    }

                    $decoded[$name] = $value;
                }

                if ($violations !== []) {
                    throw new DecodeException($violations);
                }

                return $decoded;
            }

            #[Override]
            public function encode(mixed $value): stdClass
            {
                if (!is_array($value)) {
                    throw new InvalidArgumentException('the value must be an array of members');
                }

                return (object) $value;
            }

            #[Override]
            public function schema(): Schema
            {
                return new Schema(type: JsonType::Object, additionalProperties: new Schema(type: JsonType::Integer));
            }
        };
    }

    private static function errorList(): ErrorList
    {
        return new ErrorList(new StatusSelector());
    }

    /**
     * @return list<ErrorDescription>
     */
    private static function problems(ErrorList $errors): array
    {
        $exception = $errors->exception();

        return $exception === null ? [] : $exception->descriptions();
    }

    /**
     * @return list<string>
     */
    private static function codes(ErrorList $errors): array
    {
        return array_map(
            static function (ErrorDescription $problem): string {
                return $problem->code instanceof ErrorCode
                    ? $problem->code->value
                    : $problem->code;
            },
            self::problems($errors),
        );
    }
}
