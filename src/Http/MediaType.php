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

namespace Polunich\WpJsonApi\Http;

use InvalidArgumentException;
use Polunich\WpJsonApi\Text\Printable;

/**
 * a media type with its parameters (RFC 9110 8.3.1), weighted as a media range (12.4.2).
 *
 * type, subtype and parameter names are case-insensitive and held in lower case;
 * parameter values keep their case, and parameters keep their given order,
 * so a rendered value is deterministic.
 *
 * `q` is the weight, never a parameter: the media type registry disallows that name (12.5.1).
 * it lives in `$weight` alone, so a weighted JSON:API media type
 * does not read as one modified by a parameter other than ext or profile
 *
 * @internal
 */
final readonly class MediaType
{
    public const JSON_API = self::APPLICATION . '/' . self::JSON_API_SUBTYPE;

    public const FORM_URLENCODED = 'application/x-www-form-urlencoded';

    public const MULTIPART_FORM_DATA = 'multipart/form-data';

    public const EXT = 'ext';

    public const PROFILE = 'profile';

    public const ATOMIC_EXTENSION = 'https://jsonapi.org/ext/atomic';

    /**
     * the top-level type of JSON and of JSON:API (RFC 6838 4.2.5)
     */
    private const APPLICATION = 'application';

    private const JSON_API_SUBTYPE = 'vnd.api+json';

    private const JSON_SUBTYPE = 'json';

    /**
     * the structured syntax suffix of a JSON-based media type (RFC 6839 3.1)
     */
    private const JSON_SUFFIX = '+json';

    private const URI_SEPARATOR = ' ';

    private const WILDCARD = '*';

    public string $type;

    public string $subtype;

    /**
     * keyed by array-key, not string:
     * a parameter name of digits is a token, and PHP turns such a key into an int
     *
     * @var array<array-key, string> lower-case name => value, in render order
     */
    public array $parameters;

    /**
     * 0 means not acceptable (RFC 9110 12.4.2). an unweighted media type carries the default,
     * so comparing weights needs no case for a missing one
     */
    public float $weight;

    /**
     * @param array<array-key, string> $parameters without the weight
     *
     * @throws InvalidArgumentException when a name is no token or is `q`, a name repeats,
     *                                  a value holds a character no field value may carry,
     *                                  or the weight is no qvalue
     */
    public function __construct(
        string $type,
        string $subtype,
        array $parameters = [],
        float $weight = FieldValueGrammar::DEFAULT_WEIGHT,
    ) {
        if (!FieldValueGrammar::isToken($type) || !FieldValueGrammar::isToken($subtype)) {
            throw new InvalidArgumentException(
                'a media type and its subtype must be tokens, got "' . Printable::of($type . '/' . $subtype) . '"',
            );
        }

        $normalized = [];

        foreach ($parameters as $key => $value) {
            $name = (string) $key;

            if (!FieldValueGrammar::isToken($name)) {
                throw new InvalidArgumentException(
                    'a media type parameter name must be a token, got "' . Printable::of($name) . '"',
                );
            }

            $lowered = strtolower($name);

            if ($lowered === FieldValueGrammar::WEIGHT_PARAMETER) {
                throw new InvalidArgumentException(
                    'q is the weight of a media range and no media type parameter: the media type'
                    . ' registry disallows a parameter named "q" (RFC 9110 12.5.1)',
                );
            }

            if (!FieldValueGrammar::isText($value)) {
                throw new InvalidArgumentException(
                    'the media type parameter "' . $name . '" holds a value that no header field can carry',
                );
            }

            if (isset($normalized[$lowered])) {
                throw new InvalidArgumentException(
                    'the media type parameter "' . $lowered . '" is given twice',
                );
            }

            $normalized[$lowered] = $value;
        }

        if (!FieldValueGrammar::isWeight($weight)) {
            // var_export(): from PHP 8.5 casting NAN to a string warns
            throw new InvalidArgumentException(
                'a weight must lie between 0 and 1 and carry at most three digits after the decimal'
                . ' point, got ' . var_export($weight, true),
            );
        }

        $this->type = strtolower($type);
        $this->subtype = strtolower($subtype);
        $this->parameters = $normalized;
        $this->weight = $weight;
    }

    /**
     * reads a whole field value as one media type (RFC 9110 8.3.1), the form of Content-Type,
     * or null for anything else: a list, or a value with a `q` parameter
     */
    public static function parse(string $value): ?self
    {
        $position = 0;
        $range = self::readRange($value, $position);

        if ($range === null || $position !== strlen($value)) {
            return null;
        }

        [$type, $subtype, $parameters] = $range;

        if (isset($parameters[FieldValueGrammar::WEIGHT_PARAMETER])) {
            return null;
        }

        return new self($type, $subtype, $parameters);
    }

    /**
     * reads `#( media-range [ weight ] )`, the form of Accept (RFC 9110 12.5.1),
     * or null when an element is malformed, a `q` that is no qvalue included.
     *
     * not split on commas: a comma is qdtext, so one inside a quoted value belongs to the value;
     * `readRange()` stops on the comma that ends an element instead.
     * empty elements are skipped (5.6.1.2).
     * `q` becomes the weight wherever it stands,
     * and a parameter after it still belongs to the media range, since 12.5.1 dropped accept-ext
     *
     * @return list<self>|null
     */
    public static function parseList(string $fieldValue): ?array
    {
        $length = strlen($fieldValue);
        $position = 0;
        $mediaTypes = [];

        while (true) {
            $position += strspn($fieldValue, " \t,", $position);

            if ($position >= $length) {
                break;
            }

            $range = self::readRange($fieldValue, $position);

            if ($range === null) {
                return null;
            }

            [$type, $subtype, $parameters] = $range;
            $weight = FieldValueGrammar::DEFAULT_WEIGHT;

            if (isset($parameters[FieldValueGrammar::WEIGHT_PARAMETER])) {
                $read = FieldValueGrammar::weight($parameters[FieldValueGrammar::WEIGHT_PARAMETER]);

                if ($read === null) {
                    return null;
                }

                $weight = $read;
                unset($parameters[FieldValueGrammar::WEIGHT_PARAMETER]);
            }

            $mediaTypes[] = new self($type, $subtype, $parameters, $weight);
        }

        return $mediaTypes;
    }

    /**
     * @param list<string> $ext
     * @param list<string> $profile
     *
     * @throws InvalidArgumentException when a URI is empty, holds the space that separates URIs,
     *                                  or holds a character no field value may carry
     */
    public static function jsonApi(array $ext = [], array $profile = []): self
    {
        $parameters = [];

        if ($ext !== []) {
            $parameters[self::EXT] = self::joinUris($ext);
        }

        if ($profile !== []) {
            $parameters[self::PROFILE] = self::joinUris($profile);
        }

        return new self(self::APPLICATION, self::JSON_API_SUBTYPE, $parameters);
    }

    public function isJsonApi(): bool
    {
        return $this->type === self::APPLICATION && $this->subtype === self::JSON_API_SUBTYPE;
    }

    /**
     * `application/json` or `application/*+json` (RFC 6839 3.1), the JSON:API media type included
     */
    public function isJson(): bool
    {
        return $this->type === self::APPLICATION
            && ($this->subtype === self::JSON_SUBTYPE || str_ends_with($this->subtype, self::JSON_SUFFIX));
    }

    /**
     * the type and the subtype without parameters, such as `text/csv`
     */
    public function essence(): string
    {
        return $this->type . '/' . $this->subtype;
    }

    /**
     * whether this media range admits the media type: its type and subtype are the same or `*`,
     * and each of its parameters stands in the media type with the same value (RFC 9110 12.5.1);
     * the weight takes no part
     */
    public function admits(self $mediaType): bool
    {
        if ($this->type !== self::WILDCARD && $this->type !== $mediaType->type) {
            return false;
        }

        if ($this->subtype !== self::WILDCARD && $this->subtype !== $mediaType->subtype) {
            return false;
        }

        foreach ($this->parameters as $name => $value) {
            if (($mediaType->parameters[$name] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * the precedence of a media range among those admitting one type (RFC 9110 12.5.1):
     * the full wildcard below `text/*` below `text/plain`,
     * and each parameter one step more specific
     */
    public function specificity(): int
    {
        if ($this->type === self::WILDCARD) {
            return 0;
        }

        return ($this->subtype === self::WILDCARD ? 1 : 2) + count($this->parameters);
    }

    public function isRange(): bool
    {
        return $this->type === self::WILDCARD || $this->subtype === self::WILDCARD;
    }

    /**
     * @return list<string>
     */
    public function ext(): array
    {
        return self::splitUris($this->parameters[self::EXT] ?? '');
    }

    /**
     * @return list<string>
     */
    public function profile(): array
    {
        return self::splitUris($this->parameters[self::PROFILE] ?? '');
    }

    /**
     * ext and profile are always quoted, as the JSON:API specification asks;
     * any other value only when it is no token.
     * a weight other than the default goes last, as RFC 9110 12.5.1 asks
     */
    public function toHeaderValue(): string
    {
        $value = $this->type . '/' . $this->subtype;

        foreach ($this->parameters as $name => $parameterValue) {
            $alwaysQuoted = $name === self::EXT || $name === self::PROFILE;
            $written = $alwaysQuoted
                ? FieldValueGrammar::quote($parameterValue)
                : FieldValueGrammar::render($parameterValue);
            $value .= ';' . $name . '=' . $written;
        }

        if ($this->weight !== FieldValueGrammar::DEFAULT_WEIGHT) {
            $value .= ';' . FieldValueGrammar::WEIGHT_PARAMETER . '=' . self::renderWeight($this->weight);
        }

        return $value;
    }

    /**
     * @param list<string> $uris
     */
    private static function joinUris(array $uris): string
    {
        foreach ($uris as $uri) {
            if ($uri === '' || str_contains($uri, self::URI_SEPARATOR) || !FieldValueGrammar::isText($uri)) {
                throw new InvalidArgumentException(
                    'an extension or profile URI must not be empty, must hold no space and must fit'
                    . ' in a header field, got "' . Printable::of($uri) . '"',
                );
            }
        }

        return implode(self::URI_SEPARATOR, $uris);
    }

    /**
     * @return list<string>
     */
    private static function splitUris(string $value): array
    {
        $uris = [];

        foreach (explode(self::URI_SEPARATOR, $value) as $uri) {
            if ($uri !== '') {
                $uris[] = $uri;
            }
        }

        return $uris;
    }

    /**
     * at most three decimals, the qvalue limit of RFC 9110 12.4.2, without trailing zeros
     */
    private static function renderWeight(float $weight): string
    {
        return rtrim(rtrim(number_format($weight, 3, '.', ''), '0'), '.');
    }

    private static function skipWhitespace(string $value, int &$position): void
    {
        $position += strspn($value, " \t", $position);
    }

    /**
     * reads one media range at `$position` and leaves `$position` on the comma that ends it,
     * or at the end of the value.
     *
     * a repeated parameter name makes the range ambiguous and reads as null.
     * empty parameters are skipped, as `*( OWS ";" OWS [ parameter ] )` allows (RFC 9110 5.6.6).
     * `q` stays among the parameters: `parse()` refuses it, `parseList()` takes it as the weight
     *
     * @return array{string, string, array<array-key, string>}|null type, subtype and parameters
     */
    private static function readRange(string $value, int &$position): ?array
    {
        $length = strlen($value);

        self::skipWhitespace($value, $position);

        $type = self::readToken($value, $position);

        if ($type === null || $position >= $length || $value[$position] !== '/') {
            return null;
        }

        ++$position;
        $subtype = self::readToken($value, $position);

        if ($subtype === null) {
            return null;
        }

        $parameters = [];

        while (true) {
            self::skipWhitespace($value, $position);

            if ($position >= $length || $value[$position] === ',') {
                break;
            }

            if ($value[$position] !== ';') {
                return null;
            }

            ++$position;
            self::skipWhitespace($value, $position);

            if ($position >= $length || $value[$position] === ',') {
                break;
            }

            if ($value[$position] === ';') {
                continue;
            }

            $name = self::readToken($value, $position);

            if ($name === null || $position >= $length || $value[$position] !== '=') {
                return null;
            }

            ++$position;
            $parameterValue = self::readParameterValue($value, $position);

            if ($parameterValue === null) {
                return null;
            }

            $name = strtolower($name);

            if (isset($parameters[$name])) {
                return null;
            }

            $parameters[$name] = $parameterValue;
        }

        return [$type, $subtype, $parameters];
    }

    private static function readToken(string $value, int &$position): ?string
    {
        $length = FieldValueGrammar::tokenLength($value, $position);

        if ($length === 0) {
            return null;
        }

        $token = substr($value, $position, $length);
        $position += $length;

        return $token;
    }

    /**
     * a token, or a quoted-string with its quoted-pairs unescaped (RFC 9110 5.6.4)
     */
    private static function readParameterValue(string $value, int &$position): ?string
    {
        if ($position < strlen($value) && $value[$position] !== '"') {
            return self::readToken($value, $position);
        }

        $length = strlen($value);
        $read = '';
        ++$position;

        while ($position < $length) {
            $character = $value[$position];
            ++$position;

            if ($character === '"') {
                return $read;
            }

            if ($character === '\\') {
                if ($position >= $length) {
                    return null;
                }

                $character = $value[$position];
                ++$position;
            }

            if (!FieldValueGrammar::isText($character)) {
                return null;
            }

            $read .= $character;
        }

        return null;
    }
}
