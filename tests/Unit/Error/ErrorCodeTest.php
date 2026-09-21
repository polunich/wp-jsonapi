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

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Error\ErrorCode;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(ErrorCode::class)]
final class ErrorCodeTest extends TestCase
{
    /**
     * the cases that belong to no status
     *
     * @var list<ErrorCode>
     */
    private const SPECIFIC = [
        ErrorCode::UnsupportedMediaTypeParameter,
        ErrorCode::UnsupportedExtension,
        ErrorCode::ExtensionRequired,
        ErrorCode::UnsupportedQueryParameter,
        ErrorCode::InvalidQueryParameter,
        ErrorCode::MissingQueryParameter,
        ErrorCode::MixedPaginationStrategies,
        ErrorCode::MaxPageSizeExceeded,
        ErrorCode::UnsupportedSort,
        ErrorCode::DuplicateSortField,
        ErrorCode::UnsupportedInclude,
        ErrorCode::UnsupportedFilterField,
        ErrorCode::UnsupportedFilterOperator,
        ErrorCode::InvalidJson,
        ErrorCode::ClientIdNotSupported,
        ErrorCode::TypeMismatch,
        ErrorCode::IdMismatch,
        ErrorCode::UnknownField,
        ErrorCode::MissingField,
        ErrorCode::ReadOnlyField,
        ErrorCode::DuplicateLid,
        ErrorCode::UnknownLid,
        ErrorCode::SelfReferencingLid,
        ErrorCode::LidWithId,
        ErrorCode::OperationNotSupported,
        ErrorCode::RelationshipUpdateNotSupported,
        ErrorCode::RelationshipFetchNotSupported,
        ErrorCode::AuthenticationRequired,
        ErrorCode::InvalidPrecondition,
        ErrorCode::RelatedResourceNotFound,
        ErrorCode::ResourceAlreadyExists,
        ErrorCode::ConstraintViolation,
        ErrorCode::UploadIncomplete,
        ErrorCode::MissingFormField,
        ErrorCode::UnknownFormField,
        ErrorCode::InvalidFormFile,
    ];

    public function testEveryCaseKeepsTheValueAClientMatchesOn(): void
    {
        $values = array_map(static function (ErrorCode $code): string {
            return $code->value;
        }, ErrorCode::cases());

        self::assertSame(
            [
                'bad_request',
                'unauthorized',
                'forbidden',
                'not_found',
                'method_not_allowed',
                'not_acceptable',
                'conflict',
                'precondition_failed',
                'content_too_large',
                'unsupported_media_type',
                'unprocessable_content',
                'precondition_required',
                'internal_error',
                'unsupported_media_type_parameter',
                'unsupported_extension',
                'extension_required',
                'unsupported_query_parameter',
                'invalid_query_parameter',
                'missing_query_parameter',
                'mixed_pagination_strategies',
                'max_page_size_exceeded',
                'unsupported_sort',
                'duplicate_sort_field',
                'unsupported_include',
                'unsupported_filter_field',
                'unsupported_filter_operator',
                'invalid_json',
                'client_id_not_supported',
                'type_mismatch',
                'id_mismatch',
                'unknown_field',
                'missing_field',
                'read_only_field',
                'duplicate_lid',
                'unknown_lid',
                'self_referencing_lid',
                'lid_with_id',
                'operation_not_supported',
                'relationship_update_not_supported',
                'relationship_fetch_not_supported',
                'authentication_required',
                'invalid_precondition',
                'related_resource_not_found',
                'resource_already_exists',
                'constraint_violation',
                'upload_incomplete',
                'missing_form_field',
                'unknown_form_field',
                'invalid_form_file',
            ],
            $values,
        );
    }

    /**
     * a code names what went wrong, never the exception class that reports it,
     * and a refused value keeps the violation code of its codec,
     * so no case is `resource_not_found`, `version_mismatch` or `invalid_attribute`
     */
    public function testTheCodesOfTheDocumentsAreSpelledAsTheyAreWritten(): void
    {
        $values = array_map(static function (ErrorCode $code): string {
            return $code->value;
        }, ErrorCode::cases());

        self::assertContains('invalid_query_parameter', $values);
        self::assertContains('mixed_pagination_strategies', $values);
        self::assertContains('unsupported_sort', $values);
        self::assertContains('read_only_field', $values);
        self::assertContains('authentication_required', $values);
        self::assertContains('operation_not_supported', $values);
        self::assertContains('relationship_update_not_supported', $values);
        self::assertContains('relationship_fetch_not_supported', $values);
        self::assertContains('unsupported_query_parameter', $values);
        self::assertContains('duplicate_lid', $values);
        self::assertContains('unknown_lid', $values);
        self::assertContains('self_referencing_lid', $values);
        self::assertContains('lid_with_id', $values);
        self::assertContains('invalid_precondition', $values);
        self::assertContains('related_resource_not_found', $values);
        self::assertContains('resource_already_exists', $values);
        self::assertContains('constraint_violation', $values);
        self::assertNotContains('resource_not_found', $values);
        self::assertNotContains('version_mismatch', $values);
        self::assertNotContains('invalid_attribute', $values);
    }

    public function testNoSpecificCaseIsTheGenericCaseOfAStatus(): void
    {
        for ($status = 400; $status <= 599; $status++) {
            self::assertNotContains(ErrorCode::ofStatus($status), self::SPECIFIC);
        }
    }

    public function testEveryValueIsLowerSnake(): void
    {
        foreach (ErrorCode::cases() as $code) {
            self::assertMatchesRegularExpression('/^[a-z]+(?:_[a-z]+)*$/D', $code->value);
        }
    }

    public function testEveryCaseIsFoundByItsValue(): void
    {
        foreach (ErrorCode::cases() as $code) {
            self::assertSame($code, ErrorCode::from($code->value));
        }
    }

    /**
     * public because the public constructor of `ErrorDescription` takes it
     */
    public function testTheEnumIsPublicApi(): void
    {
        $declaration = (new ReflectionEnum(ErrorCode::class))->getDocComment();

        self::assertIsString($declaration);
        self::assertStringNotContainsString('@internal', $declaration);
    }

    public function testOfStatusIsAPublicStaticMethodOfTheStatus(): void
    {
        $method = new ReflectionMethod(ErrorCode::class, 'ofStatus');

        self::assertTrue($method->isPublic());
        self::assertTrue($method->isStatic());
        self::assertSame(['status'], array_map(
            static function (ReflectionParameter $parameter): string {
                return $parameter->getName();
            },
            $method->getParameters(),
        ));
    }

    #[DataProvider('statuses')]
    public function testOfStatusReturnsTheGenericCaseOfTheStatus(int $status, ErrorCode $code): void
    {
        self::assertSame($code, ErrorCode::ofStatus($status));
    }

    /**
     * @return array<string, array{int, ErrorCode}>
     */
    public static function statuses(): array
    {
        return [
            'status 400' => [400, ErrorCode::BadRequest],
            'status 401' => [401, ErrorCode::Unauthorized],
            'status 403' => [403, ErrorCode::Forbidden],
            'status 404' => [404, ErrorCode::NotFound],
            'status 405' => [405, ErrorCode::MethodNotAllowed],
            'status 406' => [406, ErrorCode::NotAcceptable],
            'status 409' => [409, ErrorCode::Conflict],
            'status 412' => [412, ErrorCode::PreconditionFailed],
            'status 415' => [415, ErrorCode::UnsupportedMediaType],
            'status 422' => [422, ErrorCode::UnprocessableContent],
            'status 428' => [428, ErrorCode::PreconditionRequired],
            'status 500' => [500, ErrorCode::InternalError],
            'another client error, a teapot' => [418, ErrorCode::BadRequest],
            'the last client error' => [499, ErrorCode::BadRequest],
            'another server error, not implemented' => [501, ErrorCode::InternalError],
            'the last server error' => [599, ErrorCode::InternalError],
        ];
    }

    #[DataProvider('statusesOfNoError')]
    public function testOfStatusRefusesAStatusOfNoError(int $status): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a generic error code is looked up for a 4xx or 5xx status'
            . ' code, got ' . $status);

        ErrorCode::ofStatus($status);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function statusesOfNoError(): array
    {
        return [
            'just below the client errors' => [399],
            'just above the server errors' => [600],
        ];
    }
}
