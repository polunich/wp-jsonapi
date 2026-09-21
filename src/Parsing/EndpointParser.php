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

namespace Polunich\WpJsonApi\Parsing;

use LogicException;
use OutOfBoundsException;
use Polunich\WpJsonApi\Codec\DecodeException;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Document\Member;
use Polunich\WpJsonApi\Endpoint\BytesBody;
use Polunich\WpJsonApi\Endpoint\FormBody;
use Polunich\WpJsonApi\Endpoint\JsonBody;
use Polunich\WpJsonApi\Endpoint\MetaBody;
use Polunich\WpJsonApi\Endpoint\ResourceBody as EndpointResourceBody;
use Polunich\WpJsonApi\Error\BadRequestException;
use Polunich\WpJsonApi\Error\ContentTooLargeException;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Error\NotFoundException;
use Polunich\WpJsonApi\Error\StatusSelector;
use Polunich\WpJsonApi\Error\UnprocessableContentException;
use Polunich\WpJsonApi\Http\FieldName;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Http\UploadedFile;
use Polunich\WpJsonApi\Metadata\Cardinality;
use Polunich\WpJsonApi\Metadata\Endpoint\BodyMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\BodyShapeKind;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaBodyMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaBodyShapeKind;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\RouteQuery;
use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\ParameterFamily;
use Polunich\WpJsonApi\Query\QueryText;
use Polunich\WpJsonApi\Text\Printable;
use RuntimeException;
use stdClass;

/**
 * reads the request of an endpoint in phases, called in this order;
 * the split lets the preconditions be evaluated after the query and before the body:
 *
 * 1. `negotiate()`: 406, then 415; an endpoint applies no extension, pages only a page response,
 *    responds in the representations it declares and reads the bodies it declares;
 * 2. `pathValues()`: each parameter of the path decoded by its codec;
 *    a segment the codec refuses addresses nothing, so it is 404 like a missing record;
 * 3. `query()`: the declared parameters, `include` and `fields`,
 *    and `filter`, `sort` and `page` of a page response, every mistake collected in one 400;
 * 4. `body()`: the declared body that the `Content-Type` picks
 *
 * @internal
 */
final readonly class EndpointParser
{
    /** the member of an error's meta that names the form field the error is about */
    private const FIELD = 'field';

    public function __construct(
        private ResourceRegistry $resources,
        private Negotiator $negotiator,
        private QueryParameterGuard $guard,
        private IncludeParser $includes,
        private FieldsetParser $fieldsets,
        private CollectionReadingParser $collections,
        private JsonBodyDecoder $json,
        private DocumentParser $documents,
        private StatusSelector $statuses,
    ) {}

    /**
     * @throws JsonApiException 406 when `Accept` admits no representation,
     *                          415 for content the endpoint does not read
     */
    public function negotiate(ServerRequest $request, EndpointMetadata $endpoint): Negotiation
    {
        return $this->negotiator->negotiate(
            $request,
            [],
            $endpoint->pageType() !== null,
            $endpoint->readableMediaTypes(),
            $endpoint->representations(),
        );
    }

    /**
     * @return array<string, string> every parameter of the path, by name
     *
     * @throws NotFoundException when a codec refuses a segment or decodes it to the empty string
     * @throws LogicException    when the request's path holds no value of a template parameter:
     *                           a request routed by the template always holds every one
     */
    public function pathValues(EndpointMetadata $endpoint, ServerRequest $request): array
    {
        $values = [];

        foreach ($endpoint->template->parameters() as $parameter) {
            $segment = $request->path[$parameter->name] ?? throw new LogicException(sprintf(
                'the endpoint "%s" names the parameter "%s", and the request carries no such route parameter',
                $endpoint->name,
                $parameter->name,
            ));

            try {
                $value = $parameter->codec->decode($segment, Pointer::root());
            } catch (DecodeException $refusal) {
                throw new NotFoundException([new ErrorDescription(ErrorCode::NotFound)], $refusal);
            }

            if ($value === '') {
                throw new NotFoundException([new ErrorDescription(ErrorCode::NotFound)]);
            }

            $values[$parameter->name] = $value;
        }

        return $values;
    }

    /**
     * @param Negotiation $negotiation what `negotiate()` returned:
     *                                 its requested profiles decide the default page strategy
     *
     * @throws JsonApiException     400 with every problem of the query
     * @throws OutOfBoundsException when the root of the include paths or the type of the page
     *                              is not declared
     */
    public function query(EndpointMetadata $endpoint, ServerRequest $request, Negotiation $negotiation): EndpointQuery
    {
        $errors = new ErrorList($this->statuses);
        $route = RouteQuery::ofEndpoint($endpoint);

        $this->guard->check($request->query, $route, $errors);

        $queryParameters = QueryParameterReader::read($request->query, $route->declared, $errors);

        $root = $endpoint->includeRoot();
        $include = $root !== null && in_array(ParameterFamily::Include, $route->families, true)
            ? $this->includes->parse(ParameterFamily::Include->valueIn($request->query), $root[0], $root[1], $errors)
            : null;
        $fields = in_array(ParameterFamily::Fields, $route->families, true)
            ? $this->fieldsets->parse(ParameterFamily::Fields->valueIn($request->query), $errors)
            : new SparseFieldsets([]);
        $type = $endpoint->pageType();
        $reading = $type === null
            ? null
            : $this->collections->parse(
                $request->query,
                $type,
                $this->resources->endpointPaginationPolicy($endpoint),
                $this->resources->endpointDefaultSort($endpoint),
                $negotiation->defaultPageStrategy(),
                $errors,
            );

        $errors->throwIfAny();

        return new EndpointQuery($queryParameters, $include, $fields, $reading);
    }

    /**
     * the body the request's `Content-Type` picks among the declared ones:
     * the JSON:API shape for JSON:API content,
     * else the body whose media type the content's media type carries.
     * a request without content is read as the empty content of the first declared body,
     * the JSON:API one first: a JSON body or a JSON:API document is then refused as no JSON,
     * a form holds no field and bytes are empty
     *
     * @param array<string, string> $path what `pathValues()` returned
     *
     * @throws JsonApiException 400 for content that is no JSON, breaks the shape or arrived cut,
     *                          409 for a type or an id the shape does not admit,
     *                          413 for a form or a file larger than PHP takes,
     *                          422 for a value refused or a form field missing or unknown
     * @throws RuntimeException when PHP could not store an uploaded file, a fault of the site
     */
    public function body(EndpointMetadata $endpoint, ServerRequest $request, array $path): ParsedBody
    {
        if (!$request->hasContent()) {
            if ($endpoint->body !== null) {
                return new ParsedBody(
                    $this->jsonApiBody($endpoint, $endpoint->body, $request, $path),
                    MediaType::JSON_API,
                );
            }

            $first = $endpoint->mediaBodies[0] ?? null;

            return $first === null
                ? new ParsedBody(null, null)
                : new ParsedBody($this->mediaBody($first, $request), $first->mediaType->toHeaderValue());
        }

        $line = $request->headers->line(FieldName::CONTENT_TYPE);
        $mediaType = $line === null ? null : MediaType::parse($line);

        if ($mediaType !== null && $mediaType->isJsonApi() && $endpoint->body !== null) {
            return new ParsedBody($this->jsonApiBody($endpoint, $endpoint->body, $request, $path), MediaType::JSON_API);
        }

        $body = $mediaType === null || $mediaType->isJsonApi() ? null : $endpoint->mediaBody($mediaType);

        if ($body === null) {
            throw new LogicException(sprintf(
                'the endpoint "%s" reads no body of the content of the request, which the negotiation refuses with'
                . ' 415',
                $endpoint->name,
            ));
        }

        return new ParsedBody($this->mediaBody($body, $request), $body->mediaType->toHeaderValue());
    }

    /**
     * @throws JsonApiException
     * @throws RuntimeException
     */
    private function mediaBody(MediaBodyMetadata $body, ServerRequest $request): JsonBody|FormBody|BytesBody
    {
        return match ($body->kind) {
            MediaBodyShapeKind::Json => $this->json($body, $request),
            MediaBodyShapeKind::Form => $this->form($body, $request),
            MediaBodyShapeKind::Bytes => new BytesBody($request->body),
        };
    }

    /**
     * @param array<string, string> $path
     *
     * @throws JsonApiException
     */
    private function jsonApiBody(
        EndpointMetadata $endpoint,
        BodyMetadata $body,
        ServerRequest $request,
        array $path,
    ): EndpointResourceBody|Linkage|MetaBody {
        $document = $this->json->decode($request->body);
        $errors = new ErrorList($this->statuses);
        $root = Pointer::root();

        $read = match ($body->kind) {
            BodyShapeKind::Resource => self::endpointResourceBody($this->documents->parseCreateResource(
                $document,
                $root,
                [self::typeOf($body)],
                $errors,
            )),
            BodyShapeKind::Record => self::endpointResourceBody($this->documents->parseUpdateResource(
                $document,
                $root,
                self::typeOf($body),
                $path[(string) $body->parameter] ?? throw new LogicException(sprintf(
                    'the body of the endpoint "%s" names the parameter "%s", and its path holds no value of it',
                    $endpoint->name,
                    (string) $body->parameter,
                )),
                null,
                $errors,
            )),
            BodyShapeKind::ToOne => $this->documents->parseLinkage(
                $document,
                $root,
                self::typeOf($body),
                Cardinality::ToOne,
                $errors,
            )?->toLinkage(),
            BodyShapeKind::ToMany => $this->documents->parseLinkage(
                $document,
                $root,
                self::typeOf($body),
                Cardinality::ToMany,
                $errors,
            )?->toLinkage(),
            BodyShapeKind::Meta => self::meta($document, $body, $errors),
        };

        $errors->throwIfAny();

        return $read ?? throw new LogicException(
            'Parsing\DocumentParser refused the body of an endpoint and named no problem, and it names one for every'
            . ' refusal',
        );
    }

    /**
     * @throws JsonApiException 400 for no JSON,
     *                          422 for each value the codec refuses, with its pointer
     */
    private function json(MediaBodyMetadata $body, ServerRequest $request): JsonBody
    {
        $value = $this->json->decode($request->body);
        $codec = $body->codec ?? throw new LogicException('a JSON body carries its codec');

        try {
            return new JsonBody($codec->decode($value, Pointer::root()));
        } catch (DecodeException $refusal) {
            throw new UnprocessableContentException(ViolationMapper::describe($refusal), $refusal);
        }
    }

    /**
     * every problem of the form in one response, each naming its field in the error's meta:
     * a form is no JSON document and its fields are no query parameters,
     * so no member of `source` names one.
     *
     * - a form PHP dropped as larger than `post_max_size` is 413;
     * - a field is read like a query value by its codec, 422 for each value refused;
     *   a required field the request lacks, and a field or file the form does not declare, are 422;
     * - a file PHP refused as larger than its limits is 413, one it received cut is 400,
     *   one it could not store is a fault of the site;
     *   no file is an absent file, and several under one name are 422
     *
     * @throws JsonApiException
     * @throws RuntimeException when PHP could not store a file
     */
    private function form(MediaBodyMetadata $body, ServerRequest $request): FormBody
    {
        if ($request->formDiscarded) {
            throw new ContentTooLargeException([new ErrorDescription(
                ErrorCode::ContentTooLarge,
                header: FieldName::CONTENT_LENGTH,
            )]);
        }

        $errors = new ErrorList($this->statuses);
        $fields = [];
        $declared = [];

        foreach ($body->fields as $field) {
            $declared[] = $field->name;

            if (!array_key_exists($field->name, $request->form)) {
                if ($field->required) {
                    $errors->add(new UnprocessableContentException([self::fieldDescription(
                        ErrorCode::MissingFormField,
                        $field->name,
                    )]));
                }

                continue;
            }

            try {
                $fields[$field->name] = $field->codec->decode(
                    QueryText::toWire($request->form[$field->name], $field->codec->schema()),
                    Pointer::root(),
                );
            } catch (DecodeException $refusal) {
                foreach ($refusal->violations() as $violation) {
                    $errors->add(new UnprocessableContentException([new ErrorDescription(
                        $violation->code,
                        $violation->parameters,
                        meta: [self::FIELD => $field->name . QueryText::brackets($violation->pointer)],
                    )]));
                }
            }
        }

        $files = [];

        foreach ($body->files as $name => $required) {
            $name = (string) $name;
            $declared[] = $name;
            $file = $request->files[$name] ?? null;

            if (is_array($file)) {
                $errors->add(new UnprocessableContentException([self::fieldDescription(
                    ErrorCode::InvalidFormFile,
                    $name,
                )]));

                continue;
            }

            if (!$file instanceof UploadedFile || $file->error === UPLOAD_ERR_NO_FILE) {
                if ($required) {
                    $errors->add(new UnprocessableContentException([self::fieldDescription(
                        ErrorCode::MissingFormField,
                        $name,
                    )]));
                }

                continue;
            }

            match ($file->error) {
                UPLOAD_ERR_OK => $files[$name] = $file,
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $errors->add(
                    new ContentTooLargeException([self::fieldDescription(ErrorCode::ContentTooLarge, $name)]),
                ),
                UPLOAD_ERR_PARTIAL => $errors->add(new BadRequestException([self::fieldDescription(
                    ErrorCode::UploadIncomplete,
                    $name,
                )])),
                default => throw new RuntimeException(sprintf(
                    'PHP could not store the file of the form field "%s", error %d: %s',
                    Printable::of($name),
                    $file->error,
                    match ($file->error) {
                        UPLOAD_ERR_NO_TMP_DIR => 'the temporary directory is missing',
                        UPLOAD_ERR_CANT_WRITE => 'the file could not be written to disk',
                        default => 'an extension stopped the upload',
                    },
                )),
            };
        }

        foreach ([...array_keys($request->form), ...array_keys($request->files)] as $name) {
            if (!in_array((string) $name, $declared, true)) {
                $errors->add(new UnprocessableContentException([self::fieldDescription(
                    ErrorCode::UnknownFormField,
                    (string) $name,
                )]));
            }
        }

        $errors->throwIfAny();

        return new FormBody(new Values($fields), $files);
    }

    private static function fieldDescription(ErrorCode $code, string $field): ErrorDescription
    {
        return new ErrorDescription($code, ['field' => $field], meta: [self::FIELD => $field]);
    }

    private static function endpointResourceBody(?ResourceBody $resource): ?EndpointResourceBody
    {
        return $resource === null
            ? null
            : new EndpointResourceBody(
                $resource->type,
                $resource->id,
                $resource->attributes,
                $resource->relationshipsWithIds(),
            );
    }

    /**
     * a document of top-level `meta` alone:
     * a `data` member names primary data the endpoint does not read
     */
    private static function meta(mixed $document, BodyMetadata $body, ErrorList $errors): ?MetaBody
    {
        $root = Pointer::root();

        if (!$document instanceof stdClass || !JsonDocument::has($document, Member::META)) {
            JsonDocument::malformed($errors, $root);

            return null;
        }

        if (JsonDocument::has($document, Member::DATA)) {
            JsonDocument::malformed($errors, $root->descend(Member::DATA));

            return null;
        }

        $codec = $body->codec ?? throw new LogicException('a meta body carries the codec of its meta');

        try {
            return new MetaBody($codec->decode(
                JsonDocument::member($document, Member::META),
                $root->descend(Member::META),
            ));
        } catch (DecodeException $refusal) {
            $errors->add(new UnprocessableContentException(ViolationMapper::describe($refusal)));

            return null;
        }
    }

    private static function typeOf(BodyMetadata $body): string
    {
        return $body->type ?? throw new LogicException(sprintf('%s names its type', $body->kind->phrase()));
    }

}
