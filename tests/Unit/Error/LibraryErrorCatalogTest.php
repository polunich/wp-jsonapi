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

namespace Polunich\WpJsonApi\Tests\Unit\Error;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\ViolationCode;
use Polunich\WpJsonApi\Error\ErrorCatalog;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\LibraryErrorCatalog;
use Polunich\WpJsonApi\Tests\Unit\Codec\ViolationCodeTest;
use ReflectionClass;
use ReflectionMethod;

/**
 * @internal
 */
#[CoversClass(LibraryErrorCatalog::class)]
final class LibraryErrorCatalogTest extends TestCase
{
    /**
     * the values the detail of each case names; a case not listed names none
     *
     * @var array<string, array<string, scalar>>
     */
    private const CASE_PARAMETERS = [
        'precondition_failed' => ['field' => 'If-Match'],
        'unsupported_media_type_parameter' => ['name' => 'charset'],
        'unsupported_extension' => ['extension' => 'https://jsonapi.org/ext/version'],
        'extension_required' => ['extension' => 'https://jsonapi.org/ext/atomic'],
        'unsupported_query_parameter' => ['name' => 'fields[customers]'],
        'invalid_query_parameter' => ['name' => 'page[size]'],
        'missing_query_parameter' => ['name' => 'createdAfter'],
        'mixed_pagination_strategies' => ['name' => 'page[offset]'],
        'max_page_size_exceeded' => ['size' => 200, 'maxSize' => 100],
        'unsupported_sort' => ['field' => 'owner.name'],
        'duplicate_sort_field' => ['field' => 'name'],
        'unsupported_include' => ['path' => 'orders.customer'],
        'unsupported_filter_field' => ['type' => 'pets', 'field' => 'city'],
        'unsupported_filter_operator' => ['field' => 'city', 'operator' => '@contains'],
        'client_id_not_supported' => ['type' => 'pets'],
        'type_mismatch' => ['type' => 'stores'],
        'id_mismatch' => ['id' => '7', 'target' => '3'],
        'unknown_field' => ['type' => 'pets', 'field' => 'colour'],
        'missing_field' => ['type' => 'pets', 'field' => 'name'],
        'read_only_field' => ['type' => 'pets', 'field' => 'createdAt'],
        'duplicate_lid' => ['lid' => 'first-pet', 'type' => 'pets'],
        'unknown_lid' => ['lid' => 'first-pet', 'type' => 'pets'],
        'self_referencing_lid' => ['lid' => 'first-pet'],
        'lid_with_id' => ['id' => '7', 'lid' => 'first-pet'],
        'operation_not_supported' => ['type' => 'pets', 'operation' => 'add_to_relationship'],
        'relationship_update_not_supported' => [
            'relationship' => 'orders',
            'type' => 'pets',
            'operation' => 'add_to_relationship',
        ],
        'relationship_fetch_not_supported' => [
            'relationship' => 'owner',
            'type' => 'pets',
            'operation' => 'fetch_related',
        ],
        'related_resource_not_found' => ['relationship' => 'store', 'type' => 'stores', 'id' => '7'],
        'resource_already_exists' => ['type' => 'pets', 'id' => '7'],
        'constraint_violation' => ['constraint' => 'unique_slug'],
        'upload_incomplete' => ['field' => 'photo'],
        'missing_form_field' => ['field' => 'caption'],
        'unknown_form_field' => ['field' => 'colour'],
        'invalid_form_file' => ['field' => 'photo'],
    ];

    public function testTheCatalogIsAnInternalFinalReadonlyCatalog(): void
    {
        $reflection = new ReflectionClass(LibraryErrorCatalog::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertTrue($reflection->implementsInterface(ErrorCatalog::class));
        self::assertStringContainsString('@internal', (string) $reflection->getDocComment());
    }

    /**
     * @param array<string, scalar> $parameters
     */
    #[DataProvider('codesOfTheLibrary')]
    public function testEveryCodeOfTheLibraryHasATitleAndADetail(string $code, array $parameters): void
    {
        $catalog = new LibraryErrorCatalog();

        $title = $catalog->title($code);
        $detail = $catalog->detail($code, $parameters);

        self::assertIsString($title);
        self::assertIsString($detail);
        self::assertNotSame('', $title);
        self::assertNotSame('', $detail);
        self::assertDoesNotMatchRegularExpression('/^[A-Z]/', $title);
        self::assertDoesNotMatchRegularExpression('/^[A-Z]/', $detail);

        foreach ($parameters as $value) {
            self::assertStringContainsString(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, $detail);
        }
    }

    /**
     * the value of a violation comes from `ViolationCodeTest::raisedCodes()`,
     * the one a built-in codec gives
     *
     * @return iterable<string, array{string, array<string, scalar>}>
     */
    public static function codesOfTheLibrary(): iterable
    {
        foreach (ErrorCode::cases() as $case) {
            yield 'the case ' . $case->value => [$case->value, self::CASE_PARAMETERS[$case->value] ?? []];
        }

        foreach (ViolationCodeTest::raisedCodes() as $name => [$code, $value]) {
            $parameter = $code->parameter();

            yield 'the violation ' . $name => [
                $code->value,
                $parameter === null || $value === null ? [] : [$parameter => $value],
            ];
        }
    }

    #[DataProvider('cases')]
    public function testTitleOfIsTheTitleOfTheCase(ErrorCode $case): void
    {
        $catalog = new LibraryErrorCatalog();

        self::assertNotSame('', $catalog->titleOf($case));
        self::assertSame($catalog->title($case->value), $catalog->titleOf($case));
    }

    /**
     * @return iterable<string, array{ErrorCode}>
     */
    public static function cases(): iterable
    {
        foreach (ErrorCode::cases() as $case) {
            yield $case->value => [$case];
        }
    }

    public function testTheCatalogWordsTheGenericCodesAndThePreconditions(): void
    {
        $catalog = new LibraryErrorCatalog();

        self::assertSame('bad request', $catalog->titleOf(ErrorCode::BadRequest));
        self::assertSame('not found', $catalog->titleOf(ErrorCode::NotFound));
        self::assertSame('internal server error', $catalog->titleOf(ErrorCode::InternalError));
        self::assertSame('precondition failed', $catalog->titleOf(ErrorCode::PreconditionFailed));
        self::assertSame('precondition required', $catalog->titleOf(ErrorCode::PreconditionRequired));
        self::assertSame(
            'the If-None-Match precondition does not hold for the current representation of the target resource.'
            . ' an entity tag holds for the media type and the language of the response that carried it, and a'
            . ' response to a request with include carries none',
            $catalog->detail('precondition_failed', ['field' => 'If-None-Match']),
        );
        self::assertSame(
            'this resource is changed only by a conditional request. send If-Match with the entity tag of the'
            . ' resource, as a single request, since an operation of an atomic request carries no precondition',
            $catalog->detail('precondition_required', []),
        );
    }

    public function testTheCatalogWordsTheCodesOfParsingAndAccess(): void
    {
        $catalog = new LibraryErrorCatalog();

        self::assertSame('operation not supported', $catalog->titleOf(ErrorCode::OperationNotSupported));
        self::assertSame(
            'the resource type pets does not support the operation add_to_relationship',
            $catalog->detail('operation_not_supported', ['type' => 'pets', 'operation' => 'add_to_relationship']),
        );
        self::assertSame(
            'relationship update not supported',
            $catalog->titleOf(ErrorCode::RelationshipUpdateNotSupported),
        );
        self::assertSame(
            'the relationship orders of the resource type pets does not accept the operation'
            . ' add_to_relationship',
            $catalog->detail(
                'relationship_update_not_supported',
                ['relationship' => 'orders', 'type' => 'pets', 'operation' => 'add_to_relationship'],
            ),
        );
        self::assertSame('missing query parameter', $catalog->titleOf(ErrorCode::MissingQueryParameter));
        self::assertSame(
            'this operation requires the query parameter createdAfter, which the request does not carry; an'
            . ' operation of an atomic request carries no query parameter',
            $catalog->detail('missing_query_parameter', ['name' => 'createdAfter']),
        );
        self::assertSame(
            'relationship fetch not supported',
            $catalog->titleOf(ErrorCode::RelationshipFetchNotSupported),
        );
        self::assertSame(
            'the relationship owner of the resource type pets does not offer the operation fetch_related',
            $catalog->detail(
                'relationship_fetch_not_supported',
                ['relationship' => 'owner', 'type' => 'pets', 'operation' => 'fetch_related'],
            ),
        );
        self::assertSame('authentication required', $catalog->titleOf(ErrorCode::AuthenticationRequired));
        self::assertSame(
            'the request carries no identity this server accepts, and no authentication scheme applies to it.'
            . ' the contract of the API names the schemes it accepts',
            $catalog->detail('authentication_required', []),
        );
        self::assertSame('max page size exceeded', $catalog->titleOf(ErrorCode::MaxPageSizeExceeded));
        self::assertSame(
            'the requested page size 200 is larger than 100, the maximum of this endpoint',
            $catalog->detail('max_page_size_exceeded', ['size' => 200, 'maxSize' => 100]),
        );
        self::assertSame(
            'the resource object to create carries the id 7 beside the lid first-pet, and a lid'
            . ' stands where an id is omitted',
            $catalog->detail('lid_with_id', ['id' => '7', 'lid' => 'first-pet']),
        );
        self::assertSame(
            'the request names the extension https://jsonapi.org/ext/version in its media type, and this server'
            . ' does not apply it',
            $catalog->detail('unsupported_extension', ['extension' => 'https://jsonapi.org/ext/version']),
        );
        self::assertSame('extension required', $catalog->titleOf(ErrorCode::ExtensionRequired));
        self::assertSame(
            'this endpoint reads a document with the extension https://jsonapi.org/ext/atomic applied, and the'
            . ' media type of the content of the request does not name it in its ext parameter',
            $catalog->detail('extension_required', ['extension' => 'https://jsonapi.org/ext/atomic']),
        );
    }

    /**
     * `invalid_precondition` is worded without parameters: the field value alone is the problem
     */
    public function testTheCatalogWordsTheCodesOfExecution(): void
    {
        $catalog = new LibraryErrorCatalog();

        self::assertSame('invalid precondition', $catalog->titleOf(ErrorCode::InvalidPrecondition));
        self::assertSame(
            'the If-Match field value is neither * nor a list of entity tags, so the precondition does not hold',
            $catalog->detail('invalid_precondition', []),
        );
        self::assertSame('related resource not found', $catalog->titleOf(ErrorCode::RelatedResourceNotFound));
        self::assertSame(
            'the relationship store names the stores resource 7, which does not exist',
            $catalog->detail(
                'related_resource_not_found',
                ['relationship' => 'store', 'type' => 'stores', 'id' => '7'],
            ),
        );
        self::assertSame('resource already exists', $catalog->titleOf(ErrorCode::ResourceAlreadyExists));
        self::assertSame(
            'the id 7 already identifies a pets resource, and a create names an id no resource of the type'
            . ' carries',
            $catalog->detail('resource_already_exists', ['type' => 'pets', 'id' => '7']),
        );
        self::assertSame('constraint violation', $catalog->titleOf(ErrorCode::ConstraintViolation));
        self::assertSame(
            'the write breaks the constraint unique_slug this server enforces',
            $catalog->detail('constraint_violation', ['constraint' => 'unique_slug']),
        );
    }

    /**
     * a client that reads the title must read the problem the code names
     */
    public function testNoTwoCasesShareATitle(): void
    {
        $catalog = new LibraryErrorCatalog();
        $titles = array_map(static function (ErrorCode $case) use ($catalog): string {
            return $catalog->titleOf($case);
        }, ErrorCode::cases());

        self::assertSame(count($titles), count(array_unique($titles)));
    }

    /**
     * a text copied from a neighbouring case would explain a problem the code does not name.
     * the texts are compared before placeholders are filled,
     * so two cases sharing one text cannot pass as different through their parameters
     */
    public function testNoTwoCasesShareADetail(): void
    {
        $detailOf = new ReflectionMethod(LibraryErrorCatalog::class, 'detailOf');
        $details = [];

        foreach (ErrorCode::cases() as $case) {
            $detail = $detailOf->invoke(null, $case);

            self::assertIsString($detail);

            $details[] = $detail;
        }

        self::assertSame(count($details), count(array_unique($details)));
    }

    /**
     * a code matches exactly: no case folding, no trimming
     */
    #[DataProvider('unknownCodes')]
    public function testACodeTheCatalogDoesNotWordHasNoText(string $code): void
    {
        $catalog = new LibraryErrorCatalog();

        self::assertNull($catalog->title($code));
        self::assertNull($catalog->detail($code, []));
        self::assertNull($catalog->detail($code, ['member' => 'name']));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownCodes(): array
    {
        return [
            'a code of WordPress' => ['rest_no_route'],
            'a code of a project' => ['store_closed'],
            'the empty code' => [''],
            'a case in upper case' => ['NOT_FOUND'],
            'a violation code with a trailing space' => ['missing_member '],
        ];
    }

    /**
     * @param array<string, scalar> $parameters
     */
    #[DataProvider('occurrences')]
    public function testAValueReplacesItsPlaceholder(string $code, array $parameters, string $detail): void
    {
        self::assertSame($detail, (new LibraryErrorCatalog())->detail($code, $parameters));
    }

    /**
     * @return array<string, array{string, array<string, scalar>, string}>
     */
    public static function occurrences(): array
    {
        return [
            'a string' => [
                'invalid_value',
                ['expected' => 'pets'],
                'the value is not pets, the only value allowed here',
            ],
            'an integer' => ['invalid_value', ['expected' => 7], 'the value is not 7, the only value allowed here'],
            'a float' => ['invalid_value', ['expected' => 1.5], 'the value is not 1.5, the only value allowed here'],
            'true' => ['invalid_value', ['expected' => true], 'the value is not true, the only value allowed here'],
            'false' => ['invalid_value', ['expected' => false], 'the value is not false, the only value allowed here'],
            'a value holding braces' => [
                'pattern_mismatch',
                ['pattern' => '^a{2}$'],
                'the string does not match the pattern ^a{2}$',
            ],
            'a parameter no placeholder names' => [
                'value_too_long',
                ['maxLength' => 3, 'requested' => 4],
                'the string is longer than 3 characters, the maximum',
            ],
            'a text without placeholders' => [
                'value_rejected',
                ['reason' => 'closed'],
                'the value has the declared type and is not accepted',
            ],
        ];
    }

    /**
     * @param array<string, scalar> $parameters
     */
    #[DataProvider('missingParameters')]
    public function testADetailWithoutTheValueItNamesIsNotWorded(string $code, array $parameters): void
    {
        $catalog = new LibraryErrorCatalog();

        self::assertNull($catalog->detail($code, $parameters));
        self::assertIsString($catalog->title($code));
    }

    /**
     * @return array<string, array{string, array<string, scalar>}>
     */
    public static function missingParameters(): array
    {
        return [
            'a violation without parameters' => ['value_too_long', []],
            'a violation with another parameter' => ['missing_member', ['name' => 'name']],
            'a case of the library without its parameter' => ['precondition_failed', []],
            'a case of the library missing one of three parameters' => [
                'related_resource_not_found',
                ['relationship' => 'store', 'type' => 'stores'],
            ],
            'a case of the library with a parameter of another name' => [
                'constraint_violation',
                ['constraint_name' => 'unique_slug'],
            ],
        ];
    }

    #[DataProvider('violationCodes')]
    public function testTheDetailOfAViolationNamesTheParameterOfItsCase(ViolationCode $code): void
    {
        $catalog = new LibraryErrorCatalog();
        $parameter = $code->parameter();

        if ($parameter === null) {
            self::assertIsString($catalog->detail($code->value, []));

            return;
        }

        self::assertNull($catalog->detail($code->value, []));
        self::assertStringContainsString(
            '<the value>',
            (string) $catalog->detail($code->value, [$parameter => '<the value>']),
        );
    }

    /**
     * @return iterable<string, array{ViolationCode}>
     */
    public static function violationCodes(): iterable
    {
        foreach (ViolationCode::cases() as $case) {
            yield $case->value => [$case];
        }
    }

    /**
     * the catalog tries `ErrorCode` first,
     * so a violation code that were also a case of it would be worded as that case
     */
    public function testNoViolationCodeIsACaseOfErrorCode(): void
    {
        self::assertSame(
            [],
            array_values(array_intersect(
                array_column(ViolationCode::cases(), 'value'),
                array_column(ErrorCode::cases(), 'value'),
            )),
        );
    }
}
