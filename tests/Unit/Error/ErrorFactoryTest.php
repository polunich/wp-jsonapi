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
use JsonSerializable;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Document\DocumentEncoder;
use Polunich\WpJsonApi\Document\ErrorDocument;
use Polunich\WpJsonApi\Document\ErrorObject;
use Polunich\WpJsonApi\Document\ErrorSource;
use Polunich\WpJsonApi\Document\JsonApiObject;
use Polunich\WpJsonApi\Document\Link;
use Polunich\WpJsonApi\Document\Links;
use Polunich\WpJsonApi\Error\ErrorCatalog;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ErrorFactory;
use Polunich\WpJsonApi\Error\LibraryErrorCatalog;
use Polunich\WpJsonApi\Tests\Fixtures\Error\LegacyLabel;
use Polunich\WpJsonApi\Tests\Fixtures\Error\Severity;
use Polunich\WpJsonApi\Tests\Fixtures\ParameterNames;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

/**
 * @internal
 */
#[CoversClass(ErrorFactory::class)]
final class ErrorFactoryTest extends TestCase
{
    private const REPLACEMENT = "\u{FFFD}";

    public function testTheConstructorTakesTheTwoCatalogsAndCreateTheDescriptionTheStatusAndThePosition(): void
    {
        $constructor = (new ReflectionMethod(ErrorFactory::class, '__construct'))->getParameters();
        $create = (new ReflectionMethod(ErrorFactory::class, 'create'))->getParameters();

        self::assertSame(['libraryCatalog', 'projectCatalog'], ParameterNames::of($constructor));
        self::assertSame(LibraryErrorCatalog::class, (string) $constructor[0]->getType());
        self::assertSame('?' . ErrorCatalog::class, (string) $constructor[1]->getType());
        self::assertNull($constructor[1]->getDefaultValue());
        self::assertSame(['description', 'status', 'operationPosition'], ParameterNames::of($create));
        self::assertSame('?int', (string) $create[2]->getType());
        self::assertNull($create[2]->getDefaultValue());
        self::assertStringContainsString(
            '@internal',
            (string) (new ReflectionClass(ErrorFactory::class))->getDocComment(),
        );
    }

    public function testTheStatusOfTheExceptionIsWrittenAsAString(): void
    {
        $error = self::factory()->create(new ErrorDescription(ErrorCode::Forbidden), 403);

        self::assertSame('403', $error->status);
    }

    public function testTheStatusOfTheDescriptionWinsOverTheStatusOfTheException(): void
    {
        $error = self::factory()->create(new ErrorDescription('invalid_attribute', status: 422), 400);

        self::assertSame('422', $error->status);
    }

    #[DataProvider('statusesOfNoError')]
    public function testAStatusOfNoErrorIsRefused(int $status, ?int $descriptionStatus): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an error object is created for a 4xx or 5xx status code, got ' . $status);

        self::factory()->create(
            new ErrorDescription('invalid_attribute', title: 'invalid', status: $descriptionStatus),
            $status,
        );
    }

    /**
     * @return array<string, array{int, int|null}>
     */
    public static function statusesOfNoError(): array
    {
        return [
            'just below the client errors' => [399, null],
            'just above the server errors' => [600, null],
            'a success under a description with a status' => [200, 422],
        ];
    }

    #[DataProvider('codes')]
    public function testTheCodeIsWrittenAndLookedUpAsAString(string|ErrorCode $code, string $written): void
    {
        $project = self::projectCatalog([$written => 'the title'], [$written => 'the detail']);

        $error = self::factory($project)->create(new ErrorDescription($code), 400);

        self::assertSame($written, $error->code);
        self::assertSame([$written], $project->titles);
        self::assertSame([[$written, []]], $project->details);
    }

    /**
     * @return array<string, array{string|ErrorCode, string}>
     */
    public static function codes(): array
    {
        return [
            'a case of the library' => [ErrorCode::PreconditionRequired, 'precondition_required'],
            'a code of the project' => ['store_closed', 'store_closed'],
            'a code of WordPress' => ['rest_no_route', 'rest_no_route'],
        ];
    }

    /**
     * the project catalog is asked only as far as the chain goes, and an empty text ends it
     *
     * @param array<string, string|null> $projectTitles
     * @param list<string>               $asked
     */
    #[DataProvider('titleChains')]
    public function testTheTitleIsTheFirstTextOfTheChain(
        ErrorDescription $description,
        int $status,
        array $projectTitles,
        string $title,
        array $asked,
    ): void {
        $project = self::projectCatalog($projectTitles, []);

        $error = self::factory($project)->create($description, $status);

        self::assertSame($title, $error->title);
        self::assertSame($asked, $project->titles);
    }

    /**
     * @return array<string, array{
     *     ErrorDescription,
     *     int,
     *     array<string, string|null>,
     *     string,
     *     list<string>,
     * }>
     */
    public static function titleChains(): array
    {
        return [
            'the title of the description' => [
                new ErrorDescription(ErrorCode::NotFound, title: 'no route'),
                404,
                ['not_found' => 'the project title'],
                'no route',
                [],
            ],
            'an empty title of the description' => [
                new ErrorDescription(ErrorCode::NotFound, title: ''),
                404,
                ['not_found' => 'the project title'],
                '',
                [],
            ],
            'the title of the code by the project' => [
                new ErrorDescription(ErrorCode::NotFound),
                404,
                ['not_found' => 'nothing is here'],
                'nothing is here',
                ['not_found'],
            ],
            'an empty title of the code by the project' => [
                new ErrorDescription('store_closed'),
                404,
                ['store_closed' => '', 'not_found' => 'nothing is here'],
                '',
                ['store_closed'],
            ],
            'the title of the code by the library before the generic title by the project' => [
                new ErrorDescription(ErrorCode::Conflict),
                400,
                ['bad_request' => 'the project title of bad requests', 'conflict' => null],
                'conflict',
                ['conflict'],
            ],
            'the title of a violation code by the library' => [
                new ErrorDescription('missing_member', pointer: '/data'),
                422,
                ['unprocessable_content' => 'the project title of 422'],
                'missing member',
                ['missing_member'],
            ],
            'the generic title by the project' => [
                new ErrorDescription('store_closed'),
                404,
                ['not_found' => 'nothing is here'],
                'nothing is here',
                ['store_closed', 'not_found'],
            ],
            'the generic title by the library' => [
                new ErrorDescription('store_closed'),
                404,
                [],
                'not found',
                ['store_closed', 'not_found'],
            ],
            'a code of WordPress takes the title of not_found' => [
                new ErrorDescription(
                    'rest_no_route',
                    detail: 'No route was found matching the URL and request method.',
                ),
                404,
                [],
                'not found',
                ['rest_no_route', 'not_found'],
            ],
            'the generic title of a stamped 422 in a 400' => [
                new ErrorDescription('store_closed', status: 422),
                400,
                [],
                'unprocessable content',
                ['store_closed', 'unprocessable_content'],
            ],
            'the generic title of a 4xx without a case of its own' => [
                new ErrorDescription('rest_no_route'),
                410,
                [],
                'bad request',
                ['rest_no_route', 'bad_request'],
            ],
            'the generic title of a 405, which has a case of its own' => [
                new ErrorDescription('rest_no_route'),
                405,
                [],
                'method not allowed',
                ['rest_no_route', 'method_not_allowed'],
            ],
            'the generic title of a 5xx without a case of its own' => [
                new ErrorDescription('database_unavailable'),
                503,
                [],
                'internal server error',
                ['database_unavailable', 'internal_error'],
            ],
        ];
    }

    public function testWithoutACatalogOfTheProjectTheLibraryWordsTheTitle(): void
    {
        $factory = new ErrorFactory(new LibraryErrorCatalog());

        self::assertSame('conflict', $factory->create(new ErrorDescription(ErrorCode::Conflict), 409)->title);
        self::assertSame('not found', $factory->create(new ErrorDescription('store_closed'), 404)->title);
    }

    /**
     * @param array<string, string|null>                 $projectDetails
     * @param list<array{string, array<string, scalar>}> $asked
     */
    #[DataProvider('detailChains')]
    public function testTheDetailIsTheFirstTextOfTheChain(
        ErrorDescription $description,
        array $projectDetails,
        ?string $detail,
        array $asked,
    ): void {
        $project = self::projectCatalog([], $projectDetails);

        $error = self::factory($project)->create($description, 422);

        self::assertSame($detail, $error->detail);
        self::assertSame($asked, $project->details);
    }

    /**
     * @return array<string, array{
     *     ErrorDescription,
     *     array<string, string|null>,
     *     string|null,
     *     list<array{string, array<string, scalar>}>,
     * }>
     */
    public static function detailChains(): array
    {
        return [
            'the detail of the description' => [
                new ErrorDescription('missing_member', ['member' => 'name'], detail: 'the name is missing'),
                ['missing_member' => 'the project detail'],
                'the name is missing',
                [],
            ],
            'an empty detail of the description' => [
                new ErrorDescription('missing_member', ['member' => 'name'], detail: ''),
                ['missing_member' => 'the project detail'],
                '',
                [],
            ],
            'the detail of the code by the project' => [
                new ErrorDescription('missing_member', ['member' => 'name']),
                ['missing_member' => 'the project detail'],
                'the project detail',
                [['missing_member', ['member' => 'name']]],
            ],
            'an empty detail of the code by the project' => [
                new ErrorDescription('missing_member', ['member' => 'name']),
                ['missing_member' => ''],
                '',
                [['missing_member', ['member' => 'name']]],
            ],
            'the detail of the code by the library' => [
                new ErrorDescription('missing_member', ['member' => 'name']),
                [],
                'the object lacks the member name, which is required',
                [['missing_member', ['member' => 'name']]],
            ],
            'no detail for a code no catalog words' => [
                new ErrorDescription('store_closed', ['store' => 7]),
                ['unprocessable_content' => 'a generic detail'],
                null,
                [['store_closed', ['store' => 7]]],
            ],
        ];
    }

    public function testWithoutACatalogOfTheProjectTheLibraryWordsTheDetail(): void
    {
        $factory = new ErrorFactory(new LibraryErrorCatalog());

        self::assertSame(
            'the value is not of the JSON type string',
            $factory->create(new ErrorDescription('invalid_type', ['expected' => 'string']), 422)->detail,
        );
        self::assertNull($factory->create(new ErrorDescription('store_closed'), 422)->detail);
    }

    public function testAnErrorWithoutADetailHasNoDetailMember(): void
    {
        $error = (new ErrorFactory(new LibraryErrorCatalog()))->create(new ErrorDescription('store_closed'), 409);

        self::assertSame(
            '{"jsonapi":{"version":"1.1"},"errors":[{"status":"409","code":"store_closed","title":"conflict"}]}',
            self::encoded($error),
        );
    }

    #[DataProvider('sources')]
    public function testTheSourceHoldsTheMemberTheDescriptionNames(
        ErrorDescription $description,
        ?string $pointer,
        ?string $parameter,
        ?string $header,
    ): void {
        $source = self::factory()->create($description, 400)->source;

        self::assertNotNull($source);
        self::assertSame($pointer, $source->pointer);
        self::assertSame($parameter, $source->parameter);
        self::assertSame($header, $source->header);
    }

    /**
     * @return array<string, array{ErrorDescription, string|null, string|null, string|null}>
     */
    public static function sources(): array
    {
        return [
            'a pointer' => [
                new ErrorDescription('invalid_attribute', pointer: '/data/attributes/name'),
                '/data/attributes/name',
                null,
                null,
            ],
            'the pointer to the whole document' => [
                new ErrorDescription('invalid_document', pointer: ''),
                '',
                null,
                null,
            ],
            'a parameter' => [new ErrorDescription('unsupported_sort', parameter: 'sort'), null, 'sort', null],
            'a header' => [
                new ErrorDescription(ErrorCode::PreconditionFailed, header: 'If-Match'),
                null,
                null,
                'If-Match',
            ],
        ];
    }

    public function testThereIsNoSourceWhenTheDescriptionNamesNone(): void
    {
        self::assertNull(self::factory()->create(new ErrorDescription(ErrorCode::Forbidden), 403)->source);
        self::assertNull(self::factory()->create(new ErrorDescription(ErrorCode::Forbidden), 403, null)->source);
    }

    #[DataProvider('atomicSources')]
    public function testTheSourceOfAFailedAtomicOperationPointsIntoIt(
        ErrorDescription $description,
        int $position,
        string $pointer,
    ): void {
        $source = self::factory()->create($description, 400, $position)->source;

        self::assertNotNull($source);
        self::assertSame($pointer, $source->pointer);
        self::assertNull($source->parameter);
        self::assertNull($source->header);
    }

    /**
     * @return array<string, array{ErrorDescription, int, string}>
     */
    public static function atomicSources(): array
    {
        return [
            'a pointer' => [
                new ErrorDescription('invalid_type', pointer: '/data/attributes/name'),
                2,
                '/atomic:operations/2/data/attributes/name',
            ],
            'a pointer below ref' => [
                new ErrorDescription('invalid_type', pointer: '/ref/lid'),
                0,
                '/atomic:operations/0/ref/lid',
            ],
            'the pointer to the whole operation' => [
                new ErrorDescription('invalid_type', pointer: ''),
                1,
                '/atomic:operations/1',
            ],
            'the pointer to the empty member' => [
                new ErrorDescription('invalid_type', pointer: '/'),
                1,
                '/atomic:operations/1/',
            ],
            'a parameter' => [
                new ErrorDescription('unsupported_sort', parameter: 'include'),
                3,
                '/atomic:operations/3',
            ],
            'a header' => [
                new ErrorDescription(ErrorCode::PreconditionRequired, header: 'If-Match'),
                12,
                '/atomic:operations/12',
            ],
            'no source' => [new ErrorDescription(ErrorCode::Forbidden), 0, '/atomic:operations/0'],
        ];
    }

    public function testAFailedAtomicOperationChangesNoOtherMember(): void
    {
        $description = new ErrorDescription(
            ErrorCode::BadRequest,
            ['maxSize' => 100],
            parameter: 'page[size]',
            meta: ['page' => ['maxSize' => 100]],
            typeLink: 'https://jsonapi.org/profiles/ethanresnick/cursor-pagination/max-size-exceeded',
            detail: 'the page is too large',
            status: 400,
        );

        $single = self::factory()->create($description, 400);
        $atomic = self::factory()->create($description, 400, 4);

        self::assertSame($single->status, $atomic->status);
        self::assertSame($single->code, $atomic->code);
        self::assertSame($single->title, $atomic->title);
        self::assertSame($single->detail, $atomic->detail);
        self::assertEquals($single->links, $atomic->links);
        self::assertSame($single->meta, $atomic->meta);
    }

    public function testANegativePositionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the position of an atomic operation is zero or more, got -1');

        self::factory()->create(new ErrorDescription(ErrorCode::Forbidden), 403, -1);
    }

    public function testTheTypeLinkIsTheTypeMemberOfTheLinks(): void
    {
        $typeLink = 'https://jsonapi.org/profiles/ethanresnick/cursor-pagination/unsupported-sort';

        $links = self::factory()->create(new ErrorDescription('unsupported_sort', typeLink: $typeLink), 400)->links;

        self::assertNotNull($links);
        self::assertEquals(['type' => new Link($typeLink)], $links->all());
        self::assertNull(self::factory()->create(new ErrorDescription('unsupported_sort'), 400)->links);
    }

    public function testMetaIsWrittenOnlyWhenTheDescriptionCarriesIt(): void
    {
        $meta = ['page' => ['maxSize' => 100], 'ratio' => 1.0, 'open' => false, 'note' => null, 7 => 'seven'];

        self::assertSame(
            $meta,
            self::factory()->create(new ErrorDescription(ErrorCode::BadRequest, meta: $meta), 400)->meta,
        );
        self::assertNull(self::factory()->create(new ErrorDescription(ErrorCode::BadRequest), 400)->meta);
    }

    /**
     * the error the cursor pagination profile defines for a page size above the maximum:
     * source `page[size]`, `meta.page.maxSize` and its type link
     */
    public function testTheMaxSizeExceededErrorOfTheCursorProfileIsWrittenInFull(): void
    {
        $description = new ErrorDescription(
            ErrorCode::BadRequest,
            ['maxSize' => 100, 'requested' => 200],
            parameter: 'page[size]',
            meta: ['page' => ['maxSize' => 100]],
            typeLink: 'https://jsonapi.org/profiles/ethanresnick/cursor-pagination/max-size-exceeded',
        );
        $project = self::projectCatalog(
            ['bad_request' => 'the page is too large'],
            ['bad_request' => 'the page size is at most 100'],
        );

        $error = self::factory($project)->create($description, 400);

        self::assertSame(
            '{"jsonapi":{"version":"1.1"},"errors":[{"status":"400","code":"bad_request","title":"the page is too'
            . ' large","detail":"the page size is at most 100","source":{"parameter":"page[size]"},'
            . '"links":{"type":"https://jsonapi.org/profiles/ethanresnick/cursor-pagination/max-size-exceeded"},'
            . '"meta":{"page":{"maxSize":100}}}]}',
            self::encoded($error),
        );
        self::assertSame([['bad_request', ['maxSize' => 100, 'requested' => 200]]], $project->details);
    }

    #[DataProvider('illFormedTexts')]
    public function testEveryTextIsWellFormedUtf8(
        ErrorDescription $description,
        ?int $position,
        ErrorObject $expected,
    ): void {
        $project = self::projectCatalog(["project\xfftitle" => "a\xffb\xe9"], ["project\xfftitle" => "c\xc3"]);

        $error = self::factory($project)->create($description, 422, $position);

        self::assertEquals($expected, $error);
        self::assertJson(self::encoded($error));
    }

    /**
     * @return array<string, array{ErrorDescription, int|null, ErrorObject}>
     */
    public static function illFormedTexts(): array
    {
        $replacement = self::REPLACEMENT;

        return [
            'the texts of the description' => [
                new ErrorDescription("store\xffclosed", title: "a\xffb\xe9", detail: "the store \xf0\x9f is closed"),
                null,
                new ErrorObject(
                    '422',
                    'store' . $replacement . 'closed',
                    'a' . $replacement . 'b' . $replacement,
                    'the store ' . $replacement . ' is closed',
                ),
            ],
            'the texts of the catalog of the project' => [
                new ErrorDescription("project\xfftitle"),
                null,
                new ErrorObject(
                    '422',
                    'project' . $replacement . 'title',
                    'a' . $replacement . 'b' . $replacement,
                    'c' . $replacement,
                ),
            ],
            'a value of the client in a detail of the library' => [
                new ErrorDescription('missing_member', ['member' => "na\xffme"], pointer: "/data/attributes/na\xffme"),
                null,
                new ErrorObject(
                    '422',
                    'missing_member',
                    'missing member',
                    'the object lacks the member na' . $replacement . 'me, which is required',
                    ErrorSource::pointer('/data/attributes/na' . $replacement . 'me'),
                ),
            ],
            'a parameter a client named, as parse_str() reads page%FF' => [
                new ErrorDescription(ErrorCode::BadRequest, parameter: "page\xff", title: 'bad', detail: 'bad'),
                null,
                new ErrorObject('422', 'bad_request', 'bad', 'bad', ErrorSource::parameter('page' . $replacement)),
            ],
            'a pointer below a failed atomic operation' => [
                new ErrorDescription('invalid_type', pointer: "/data/\xfe", title: 'bad', detail: 'bad'),
                5,
                new ErrorObject(
                    '422',
                    'invalid_type',
                    'bad',
                    'bad',
                    ErrorSource::pointer('/atomic:operations/5/data/' . $replacement),
                ),
            ],
            'a type link' => [
                new ErrorDescription(
                    'invalid_type',
                    typeLink: "https://example.com/errors/\xff",
                    title: 'bad',
                    detail: 'bad',
                ),
                null,
                new ErrorObject(
                    '422',
                    'invalid_type',
                    'bad',
                    'bad',
                    null,
                    new Links(['type' => 'https://example.com/errors/' . $replacement]),
                ),
            ],
        ];
    }

    public function testAWellFormedTextStaysByteForByte(): void
    {
        $text = "caf\u{e9} \u{2028} \u{1F600} \0 \"/\\ \x7f";

        $error = self::factory()->create(
            new ErrorDescription(
                $text,
                pointer: '/data/' . $text,
                meta: [$text => [$text => $text]],
                typeLink: $text,
                title: $text,
                detail: $text,
            ),
            422,
        );

        self::assertSame($text, $error->code);
        self::assertSame($text, $error->title);
        self::assertSame($text, $error->detail);
        self::assertSame('/data/' . $text, $error->source?->pointer);
        self::assertSame([$text => [$text => $text]], $error->meta);
        self::assertEquals(new Link($text), $error->links?->all()['type']);
    }

    public function testMetaIsWalkedAtAnyDepth(): void
    {
        $replacement = self::REPLACEMENT;
        $nested = new stdClass();
        $nested->{"key\xff"} = ["a\xfe", 7, 1.0, true, null];
        $object = new stdClass();
        $object->{"inner\xff"} = $nested;
        $deep = "leaf\xff";

        for ($level = 0; $level < 600; $level++) {
            $deep = ['level' => $deep];
        }

        $description = new ErrorDescription(
            'invalid_type',
            meta: ["list\xff" => ["x\xff", ['y' => "z\xc3"]], 'object' => $object, 'deep' => $deep],
        );

        $meta = self::factory()->create($description, 422)->meta;

        $expectedNested = new stdClass();
        $expectedNested->{'key' . $replacement} = ['a' . $replacement, 7, 1.0, true, null];
        $expectedObject = new stdClass();
        $expectedObject->{'inner' . $replacement} = $expectedNested;
        $expectedDeep = 'leaf' . $replacement;

        for ($level = 0; $level < 600; $level++) {
            $expectedDeep = ['level' => $expectedDeep];
        }

        self::assertIsArray($meta);
        self::assertSame(['list' . $replacement, 'object', 'deep'], array_keys($meta));
        self::assertSame(['x' . $replacement, ['y' => 'z' . $replacement]], $meta['list' . $replacement]);
        self::assertEquals($expectedObject, $meta['object']);
        self::assertSame($expectedDeep, $meta['deep']);
        self::assertNotSame($object, $meta['object']);
        self::assertSame($nested, $object->{"inner\xff"});
        self::assertSame(["a\xfe", 7, 1.0, true, null], $nested->{"key\xff"});
    }

    /**
     * `shared`: an object met twice without a cycle is walked both times
     */
    public function testAValueThatHoldsItselfStaysWhereTheWalkWouldEnterItAgain(): void
    {
        $replacement = self::REPLACEMENT;
        $object = new stdClass();
        $object->name = "a\xff";
        $object->self = $object;
        $array = ['name' => "b\xff"];
        $array['self'] = &$array;
        $shared = new stdClass();
        $shared->name = "c\xff";

        $meta = self::factory()->create(
            new ErrorDescription(
                'invalid_type',
                meta: ['object' => $object, 'array' => $array, 'shared' => [$shared, $shared]],
            ),
            422,
        )->meta;

        self::assertIsArray($meta);
        self::assertInstanceOf(stdClass::class, $meta['object']);
        self::assertNotSame($object, $meta['object']);
        self::assertSame('a' . $replacement, $meta['object']->name);
        self::assertSame($object, $meta['object']->self);
        self::assertIsArray($meta['array']);
        self::assertSame('b' . $replacement, $meta['array']['name']);
        self::assertIsArray($meta['array']['self']);
        self::assertSame('b' . $replacement, $meta['array']['self']['name']);
        self::assertIsArray($meta['array']['self']['self']);
        self::assertSame("b\xff", $meta['array']['self']['self']['name']);
        self::assertEquals([(object) ['name' => 'c'
            . $replacement], (object) ['name' => 'c' . $replacement]], $meta['shared']);
    }

    public function testKeysThatBecomeEqualKeepTheLaterValue(): void
    {
        $replacement = self::REPLACEMENT;
        $object = new stdClass();
        $object->{"a\xff"} = 'first';
        $object->{"a\xfe"} = 'second';

        $meta = self::factory()->create(
            new ErrorDescription('invalid_type', meta: ["a\xff" => 'first', "a\xfe" => 'second', 'object' => $object]),
            422,
        )->meta;

        self::assertIsArray($meta);
        self::assertSame(['a' . $replacement, 'object'], array_keys($meta));
        self::assertSame('second', $meta['a' . $replacement]);
        self::assertEquals((object) ['a' . $replacement => 'second'], $meta['object']);
    }

    /**
     * the deepest value `json_encode()` writes, 512 levels, must read back
     */
    #[DataProvider('encodedObjects')]
    public function testAnObjectIsReplacedByWhatJsonEncodeWritesForIt(mixed $value, mixed $expected): void
    {
        $meta = self::factory()->create(new ErrorDescription('invalid_type', meta: ['value' => $value]), 422)->meta;

        self::assertIsArray($meta);
        self::assertEquals($expected, $meta['value']);
        self::assertSame(get_debug_type($expected), get_debug_type($meta['value']));
    }

    /**
     * @return array<string, array{mixed, mixed}>
     */
    public static function encodedObjects(): array
    {
        $replacement = self::REPLACEMENT;
        $deep = 'x' . $replacement;

        for ($level = 0; $level < 512; $level++) {
            $deep = [$deep];
        }

        return [
            'an object that serializes itself' => [
                new class implements JsonSerializable {
                    #[Override]
                    public function jsonSerialize(): mixed
                    {
                        return ['name' => "caf\xe9", 'size' => 1.0, 'tags' => ["a\xff"]];
                    }
                },
                (object) ['name' => 'caf' . $replacement, 'size' => 1.0, 'tags' => ['a' . $replacement]],
            ],
            'an object with public properties' => [
                new class {
                    public string $name = "caf\xe9";

                    public int $count = 2;
                },
                (object) ['name' => 'caf' . $replacement, 'count' => 2],
            ],
            'a subclass of the standard object that serializes itself' => [
                new class extends stdClass implements JsonSerializable {
                    public string $hidden = 'never written';

                    #[Override]
                    public function jsonSerialize(): mixed
                    {
                        return "shown\xff";
                    }
                },
                'shown' . $replacement,
            ],
            'a case of a backed enum' => [LegacyLabel::Cafe, 'caf' . $replacement],
            'the deepest value json_encode() writes' => [
                new class implements JsonSerializable {
                    #[Override]
                    public function jsonSerialize(): mixed
                    {
                        $value = "x\xff";

                        for ($level = 0; $level < 512; $level++) {
                            $value = [$value];
                        }

                        return $value;
                    }
                },
                $deep,
            ],
        ];
    }

    public function testAFloatAnObjectWritesKeepsItsZeroFraction(): void
    {
        $value = new class implements JsonSerializable {
            #[Override]
            public function jsonSerialize(): mixed
            {
                return ['ratio' => 1.0];
            }
        };

        $meta = self::factory()->create(new ErrorDescription('invalid_type', meta: ['value' => $value]), 422)->meta;

        self::assertIsArray($meta);
        self::assertInstanceOf(stdClass::class, $meta['value']);
        self::assertSame(1.0, $meta['value']->ratio);
    }

    #[DataProvider('refusedObjects')]
    public function testAnObjectJsonEncodeRefusesStays(object $value): void
    {
        $meta = self::factory()->create(new ErrorDescription('invalid_type', meta: ['value' => $value]), 422)->meta;

        self::assertIsArray($meta);
        self::assertSame($value, $meta['value']);
    }

    /**
     * @return array<string, array{object}>
     */
    public static function refusedObjects(): array
    {
        return [
            'a case of an enum without a backing type' => [Severity::High],
            'an object that writes NAN' => [
                new class implements JsonSerializable {
                    #[Override]
                    public function jsonSerialize(): mixed
                    {
                        return NAN;
                    }
                },
            ],
            'an object nested deeper than json_encode() writes' => [
                new class implements JsonSerializable {
                    #[Override]
                    public function jsonSerialize(): mixed
                    {
                        $value = 'x';

                        for ($level = 0; $level < 513; $level++) {
                            $value = [$value];
                        }

                        return $value;
                    }
                },
            ],
        ];
    }

    public function testAnErrorWithBadBytesEverywhereEncodes(): void
    {
        $object = new stdClass();
        $object->{"k\xff"} = "v\xff";
        $description = new ErrorDescription(
            "code\xff",
            ['member' => "m\xff"],
            pointer: "/data/\xff",
            meta: ["k\xff" => ["v\xff", $object, LegacyLabel::Cafe]],
            typeLink: "https://example.com/\xff",
            title: "title\xff",
            detail: "detail\xff",
        );

        $encoded = self::encoded(self::factory()->create($description, 422));

        self::assertStringContainsString('"code":"code\\ufffd"', $encoded);
        self::assertStringContainsString(
            '"meta":{"k\\ufffd":["v\\ufffd",{"k\\ufffd":"v\\ufffd"},"caf\\ufffd"]}',
            $encoded,
        );
    }

    private static function factory(?ErrorCatalog $projectCatalog = null): ErrorFactory
    {
        return new ErrorFactory(new LibraryErrorCatalog(), $projectCatalog);
    }

    private static function encoded(ErrorObject $error): string
    {
        return json_encode(
            (new DocumentEncoder())->encode(new ErrorDocument([$error], new JsonApiObject())),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * words the texts it is given, null for any other code, and records every code it is asked for
     *
     * @param array<string, string|null> $titles
     * @param array<string, string|null> $details
     *
     * @return ErrorCatalog&object{
     *     titles: list<string>,
     *     details: list<array{string, array<string, scalar>}>,
     * }
     */
    private static function projectCatalog(array $titles, array $details): ErrorCatalog
    {
        return new class ($titles, $details) implements ErrorCatalog {
            /**
             * @var list<string>
             */
            public array $titles = [];

            /**
             * @var list<array{string, array<string, scalar>}>
             */
            public array $details = [];

            /**
             * @param array<string, string|null> $titleTexts
             * @param array<string, string|null> $detailTexts
             */
            public function __construct(private readonly array $titleTexts, private readonly array $detailTexts) {}

            #[Override]
            public function title(string $code): ?string
            {
                $this->titles[] = $code;

                return $this->titleTexts[$code] ?? null;
            }

            /**
             * @param array<string, scalar> $parameters
             */
            #[Override]
            public function detail(string $code, array $parameters): ?string
            {
                $this->details[] = [$code, $parameters];

                return $this->detailTexts[$code] ?? null;
            }
        };
    }
}
