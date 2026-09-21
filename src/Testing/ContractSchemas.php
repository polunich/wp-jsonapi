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

namespace Polunich\WpJsonApi\Testing;

use InvalidArgumentException;
use JsonException;
use LogicException;
use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Exceptions\SchemaException;
use stdClass;

/**
 * checks a response against the response schema of a committed OpenAPI 3.1 contract.
 * it reads only the contract file and uses no other module,
 * so it works with any copy of the library, prefixed or not.
 *
 * a response is looked up as OpenAPI describes it:
 *
 * - a concrete path wins over a template; two matching templates are refused, not one picked,
 *   since OpenAPI leaves that choice to the tooling.
 *   a template expression matches within one segment.
 *   both sides are compared in the RFC 3986 normal form;
 * - the status picks its exact code, else its range such as `2XX`, else `default`;
 *   a Reference Object is followed within the contract;
 * - a response without content expects an empty body;
 * - the `Content-Type` of the response picks the media type:
 *   "only the most specific key is applicable" (OpenAPI 3.1.2),
 *   a key admitting the field where its type and subtype are the same or `*`,
 *   compared without case, and each of its parameters stands in the field;
 * - the body is read by that media type.
 *   JSON, `application/json` or a `+json` type, is decoded and validated;
 *   it is decoded even without a schema, which still asks for
 *   "arbitrary JSON without constraints beyond being syntactically valid".
 *   any other media type is validated as one JSON string, the text as it came, where it is UTF-8,
 *   and not at all where it has no schema or an empty one,
 *   since "Content transferred in binary (octet-stream) MAY omit schema".
 *
 * the validator is `CompliantValidator`, not the plain `Validator` of opis: the plain one fills
 * missing properties from schema defaults, and the contract writes defaults,
 * so it would report members the response never had.
 * `format` is asserted, which JSON Schema 2020-12 allows: every format the contract writes
 * describes a value the library writes itself, so a broken one is a defect
 */
final readonly class ContractSchemas
{
    /**
     * `.invalid` never resolves (RFC 6761), so a `$ref` can reach nothing outside the contract
     */
    private const BASE_URI = 'https://contract.invalid/openapi.json';

    /** the operation fields of a Path Item */
    private const METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /**
     * the unreserved characters of RFC 3986, which the normal form writes decoded
     */
    private const UNRESERVED = '~^[A-Za-z0-9\-._\~]$~';

    /**
     * @param string $file the path as given, for messages
     */
    private function __construct(
        private string $file,
        private stdClass $contract,
        private stdClass $paths,
    ) {}

    /**
     * @throws InvalidArgumentException when the file cannot be read, holds no JSON,
     *                                  or holds no OpenAPI 3.1 document with paths
     * @throws LogicException           when opis/json-schema is not installed at 2.2 or later
     */
    public static function fromFile(string $openApiFile): self
    {
        if (!class_exists(CompliantValidator::class)) {
            throw new LogicException(
                'the contract assertions validate with the CompliantValidator of opis/json-schema, which that'
                . ' package ships from 2.2.0 on; require opis/json-schema 2.2 or later as a development'
                . ' dependency',
            );
        }

        if (!is_file($openApiFile) || !is_readable($openApiFile)) {
            throw new InvalidArgumentException(sprintf('the contract %s cannot be read', self::quote($openApiFile)));
        }

        $bytes = (string) file_get_contents($openApiFile);

        try {
            $contract = json_decode($bytes, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new InvalidArgumentException(
                sprintf('the contract %s is no JSON: %s', self::quote($openApiFile), $failure->getMessage()),
                0,
                $failure,
            );
        }

        $version = $contract instanceof stdClass ? ($contract->openapi ?? null) : null;
        $paths = $contract instanceof stdClass ? ($contract->paths ?? null) : null;

        if (
            !$contract instanceof stdClass
            || !is_string($version)
            || preg_match('~^3\.1\.\d+~', $version) !== 1
            || !$paths instanceof stdClass
        ) {
            throw new InvalidArgumentException(sprintf(
                'the contract %s is no OpenAPI 3.1 document with paths, the document the library writes',
                self::quote($openApiFile),
            ));
        }

        return new self($openApiFile, $contract, $paths);
    }

    /**
     * @param string  $path        relative to the server URL of the contract, such as `/pets/1`;
     *                             its query and fragment are ignored
     * @param string  $method      in any case, such as `GET`
     * @param ?string $contentType the `Content-Type` of the response, null where it carries none
     * @param string  $body        raw, as sent
     *
     * @return list<string> one line per violation, empty when the response matches;
     *                      a schema violation starts with the JSON pointer of its value
     *
     * @throws InvalidArgumentException when the contract describes no such response,
     *                                  or describes it in a way the check cannot read
     */
    public function violations(string $path, string $method, int $status, ?string $contentType, string $body): array
    {
        $template = $this->template($path);
        $field = strtolower($method);
        $operation = $this->paths->{$template} ?? null;
        $operation = $operation instanceof stdClass ? ($operation->{$field} ?? null) : null;

        if (!in_array($field, self::METHODS, true) || !$operation instanceof stdClass) {
            throw new InvalidArgumentException(sprintf(
                'the contract %s describes no %s on %s',
                self::quote($this->file),
                self::printable(strtoupper($method)),
                self::printable($template),
            ));
        }

        [
            $pointer,
            $response,
        ] = $this->response($operation, ['paths', $template, $field, 'responses'], $status, $template);
        $content = $response->content ?? null;

        if ($content === null || ($content instanceof stdClass && get_object_vars($content) === [])) {
            return $body === ''
                ? []
                : [sprintf(
                    'the contract describes no content for the %d response, and the body holds %d bytes',
                    $status,
                    strlen($body),
                )];
        }

        $mediaTypes = $content instanceof stdClass ? get_object_vars($content) : [];
        $responseType = $contentType === null ? null : self::mediaType($contentType);

        if ($responseType === null) {
            return [sprintf(
                'the contract describes the content of the %d response in %s, and the response names no media type it'
                . ' is in',
                $status,
                implode(', ', array_map('strval', array_keys($mediaTypes))),
            )];
        }

        $key = self::mostSpecific(array_map('strval', array_keys($mediaTypes)), $responseType);

        if ($key === null) {
            return [sprintf(
                'the contract describes the %d response in %s, and none of them is %s',
                $status,
                implode(', ', array_map('strval', array_keys($mediaTypes))),
                self::printable((string) $contentType),
            )];
        }

        $mediaType = $mediaTypes[$key];

        if (!$mediaType instanceof stdClass) {
            throw new InvalidArgumentException(sprintf(
                'the contract %s describes the media type %s of the %d response to %s %s with no Media Type Object',
                self::quote($this->file),
                self::printable($key),
                $status,
                self::printable(strtoupper($method)),
                self::printable($template),
            ));
        }

        $schema = $mediaType->schema ?? null;
        $unconstrained = $schema === null
            || $schema === true
            || ($schema instanceof stdClass && get_object_vars($schema) === []);

        if (!$unconstrained && !$schema instanceof stdClass && !is_bool($schema)) {
            throw new InvalidArgumentException(sprintf(
                'the contract %s describes the %d response to %s %s with a schema the check cannot read',
                self::quote($this->file),
                $status,
                self::printable(strtoupper($method)),
                self::printable($template),
            ));
        }

        if (self::isJson($responseType)) {
            try {
                $document = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $failure) {
                return [sprintf('the body is no JSON: %s', $failure->getMessage())];
            }
        } elseif ($unconstrained) {
            return [];
        } elseif (preg_match('//u', $body) === 1) {
            $document = $body;
        } else {
            return [sprintf('the body of the media type %s is no text in UTF-8', self::printable($key))];
        }

        if ($unconstrained) {
            return [];
        }

        return $this->validate($document, [...$pointer, 'content', $key, 'schema']);
    }

    /**
     * the type, the subtype and the parameters of a media type or a range,
     * all but the parameter values in lower case, a value unquoted; null for any other text
     *
     * @return ?array{string, string, array<string, string>}
     */
    private static function mediaType(string $text): ?array
    {
        $parts = explode(';', $text);
        $essence = strtolower(trim(array_shift($parts)));

        if (preg_match('~^([^/\s]+)/([^/\s]+)$~D', $essence, $matches) !== 1) {
            return null;
        }

        $parameters = [];

        foreach ($parts as $part) {
            if (trim($part) === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
            $value = trim($value);

            if (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
                $value = (string) preg_replace('~\\\\(.)~s', '$1', substr($value, 1, -1));
            }

            $parameters[strtolower(trim($name))] = $value;
        }

        return [$matches[1], $matches[2], $parameters];
    }

    /**
     * the key of highest precedence that admits the media type, the first written among equals
     *
     * @param list<string>                                 $keys
     * @param array{string, string, array<string, string>} $field
     */
    private static function mostSpecific(array $keys, array $field): ?string
    {
        $best = null;
        $rank = -1;

        foreach ($keys as $key) {
            $range = self::mediaType($key);

            if ($range === null) {
                continue;
            }

            [$type, $subtype, $parameters] = $range;

            if (($type !== '*' && $type !== $field[0]) || ($subtype !== '*' && $subtype !== $field[1])) {
                continue;
            }

            foreach ($parameters as $name => $value) {
                if (($field[2][$name] ?? null) !== $value) {
                    continue 2;
                }
            }

            $specificity = ($type === '*' ? 0 : ($subtype === '*' ? 1 : 2)) + count($parameters);

            if ($specificity > $rank) {
                $best = $key;
                $rank = $specificity;
            }
        }

        return $best;
    }

    /**
     * @param array{string, string, array<string, string>} $mediaType
     */
    private static function isJson(array $mediaType): bool
    {
        return $mediaType[0] === 'application' && ($mediaType[1] === 'json' || str_ends_with($mediaType[1], '+json'));
    }

    /**
     * @throws InvalidArgumentException when no path of the contract matches, or two templates match
     */
    private function template(string $path): string
    {
        $path = (string) preg_replace('~[?#].*$~s', '', $path);
        $normal = self::normalize($path);
        $templates = [];

        foreach (array_keys(get_object_vars($this->paths)) as $key) {
            $key = (string) $key;

            if (!str_contains($key, '{')) {
                if (self::normalize($key) === $normal) {
                    return $key;
                }

                continue;
            }

            if (preg_match(self::pattern($key), $normal) === 1) {
                $templates[] = $key;
            }
        }

        if (count($templates) === 1) {
            return $templates[0];
        }

        throw new InvalidArgumentException($templates === []
            ? sprintf('the contract %s describes no path %s', self::quote($this->file), self::printable($path))
            : sprintf(
                'the contract %s matches the path %s to %s, and a path is matched to one',
                self::quote($this->file),
                self::printable($path),
                implode(' and ', array_map(self::printable(...), $templates)),
            ));
    }

    /**
     * @param list<string> $pointer the pointer to the Responses Object
     *
     * @return array{list<string>, stdClass}
     *
     * @throws InvalidArgumentException when the operation describes no response for the status
     */
    private function response(stdClass $operation, array $pointer, int $status, string $template): array
    {
        $responses = $operation->responses ?? null;
        $responses = $responses instanceof stdClass ? get_object_vars($responses) : [];

        foreach ([(string) $status, intdiv($status, 100) . 'XX', 'default'] as $key) {
            if (array_key_exists($key, $responses) && $responses[$key] instanceof stdClass) {
                return $this->dereference([...$pointer, $key], $responses[$key]);
            }
        }

        throw new InvalidArgumentException(sprintf(
            'the contract %s describes no %d response to %s %s',
            self::quote($this->file),
            $status,
            strtoupper($pointer[2]),
            self::printable($template),
        ));
    }

    /**
     * @param list<string> $pointer
     *
     * @return array{list<string>, stdClass}
     *
     * @throws InvalidArgumentException when a reference leaves the contract, loops or names nothing
     */
    private function dereference(array $pointer, stdClass $object): array
    {
        $seen = [];

        while (is_string($object->{'$ref'} ?? null)) {
            $reference = $object->{'$ref'};

            if (!str_starts_with($reference, '#/') || in_array($reference, $seen, true)) {
                throw new InvalidArgumentException(sprintf(
                    'the contract %s refers to %s, and the check follows a reference within the contract that ends',
                    self::quote($this->file),
                    self::printable($reference),
                ));
            }

            $seen[] = $reference;
            $pointer = array_map(
                static function (string $token): string {
                    return strtr(rawurldecode($token), ['~1' => '/', '~0' => '~']);
                },
                explode('/', substr($reference, 2)),
            );
            $object = $this->contract;

            foreach ($pointer as $token) {
                $object = $object instanceof stdClass ? ($object->{$token} ?? null) : null;
            }

            if (!$object instanceof stdClass) {
                throw new InvalidArgumentException(sprintf(
                    'the contract %s refers to %s, which names no object of the contract',
                    self::quote($this->file),
                    self::printable($reference),
                ));
            }
        }

        return [$pointer, $object];
    }

    /**
     * the schema is addressed by a URI fragment: RFC 6901 escapes `~` and `/`,
     * then percent-encoding covers what a fragment forbids, such as the braces of a path template
     *
     * @param list<string> $pointer
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException when the validator cannot read the schema
     * @throws LogicException           when the validator has no resolver to register the contract
     */
    private function validate(mixed $document, array $pointer): array
    {
        $validator = new CompliantValidator();
        $resolver = $validator->resolver();

        if ($resolver === null) {
            throw new LogicException('the validator of opis/json-schema carries no schema resolver to register the'
                . ' contract with');
        }

        $resolver->registerRaw($this->contract, self::BASE_URI);
        $tokens = array_map(static function (string $token): string {
            return strtr($token, ['~' => '~0', '/' => '~1']);
        }, $pointer);

        try {
            $error = $validator->validate(
                $document,
                self::BASE_URI . '#/' . implode('/', array_map(rawurlencode(...), $tokens)),
            )->error();
        } catch (SchemaException $failure) {
            throw new InvalidArgumentException(
                sprintf(
                    'the contract %s holds a schema the validator cannot read at /%s: %s',
                    self::quote($this->file),
                    self::printable(implode('/', $tokens)),
                    $failure->getMessage(),
                ),
                0,
                $failure,
            );
        }

        if ($error === null) {
            return [];
        }

        $violations = [];

        foreach ((new ErrorFormatter())->format($error) as $location => $messages) {
            foreach (is_array($messages) ? $messages : [$messages] as $message) {
                $violations[] = sprintf('%s: %s', $location, is_string($message) ? $message : get_debug_type($message));
            }
        }

        return $violations;
    }

    private static function pattern(string $template): string
    {
        $pattern = '';

        foreach (preg_split('~(\{[^{}]*\})~', $template, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $index => $part) {
            $pattern .= $index % 2 === 1 ? '[^/?#]+' : preg_quote(self::normalize($part), '~');
        }

        return '~^' . $pattern . '$~D';
    }

    /**
     * the RFC 3986 normal form:
     * upper-case hex digits in a percent-encoding, unreserved characters decoded
     */
    private static function normalize(string $path): string
    {
        return (string) preg_replace_callback(
            '~%([0-9A-Fa-f]{2})~',
            static function (array $encoding): string {
                $character = rawurldecode($encoding[0]);

                return preg_match(self::UNRESERVED, $character) === 1 ? $character : strtoupper($encoding[0]);
            },
            $path,
        );
    }

    private static function quote(string $file): string
    {
        return '"' . self::printable($file) . '"';
    }

    /**
     * the one place of the module that makes a value printable for a message.
     * `Testing` depends on no other module, so it keeps this copy of the rule of `Text\Printable`
     *
     * @internal
     */
    public static function printable(string $text): string
    {
        return addcslashes($text, "\0..\37\177");
    }
}
