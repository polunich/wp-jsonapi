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

namespace Polunich\WpJsonApi\Error;

use InvalidArgumentException;
use Polunich\WpJsonApi\Http\FieldValueGrammar;
use Polunich\WpJsonApi\Text\Printable;

/**
 * one problem of a failed request, described but not yet worded.
 *
 * a project builds descriptions without touching the internal document model,
 * and the title and the detail come from the catalogs when the response is built.
 * what the spec pins is checked here, so every description renders a conforming error object
 */
final readonly class ErrorDescription
{
    /**
     * @param array<string, scalar>   $parameters the values the catalog puts into the detail
     * @param string|null             $pointer    a JSON pointer into the request document
     * @param string|null             $parameter  the query parameter that caused the problem
     * @param string|null             $header     the request header that caused the problem
     * @param array<array-key, mixed> $meta       a member name of digits becomes an int key
     * @param string|null             $typeLink   the `links.type` member
     * @param string|null             $title      replaces the title of the catalog
     * @param string|null             $detail     replaces the detail of the catalog
     * @param int|null                $status     the status of this problem,
     *                                            where it differs from the exception's
     *
     * @throws InvalidArgumentException when more than one source is given,
     *                                  the pointer is no JSON pointer, the header is no field name,
     *                                  or the status is not 4xx or 5xx
     */
    public function __construct(
        public string|ErrorCode $code,
        public array $parameters = [],
        public ?string $pointer = null,
        public ?string $parameter = null,
        public ?string $header = null,
        public array $meta = [],
        public ?string $typeLink = null,
        public ?string $title = null,
        public ?string $detail = null,
        public ?int $status = null,
    ) {
        $sources = array_filter(
            ['pointer' => $pointer, 'parameter' => $parameter, 'header' => $header],
            static function (?string $source): bool {
                return $source !== null;
            },
        );

        if (count($sources) > 1) {
            $named = [];

            foreach ($sources as $member => $source) {
                $named[] = $member . ' "' . Printable::of($source) . '"';
            }

            throw new InvalidArgumentException(
                'an error description names at most one source, got ' . implode(' and ', $named),
            );
        }

        if ($pointer !== null && !self::isPointer($pointer)) {
            throw new InvalidArgumentException(
                'the pointer of an error description must be a JSON pointer (RFC 6901), got "'
                . Printable::of($pointer) . '"',
            );
        }

        if ($header !== null && !FieldValueGrammar::isToken($header)) {
            throw new InvalidArgumentException(
                'the header of an error description must be a field name, which is a token (RFC 9110 5.1), got "'
                . Printable::of($header) . '"',
            );
        }

        if ($status !== null && ($status < 400 || $status > 599)) {
            throw new InvalidArgumentException(
                'the status of an error description must be a 4xx or 5xx status code, got ' . $status,
            );
        }
    }

    /**
     * @throws InvalidArgumentException when the status is not 4xx or 5xx
     */
    public function withStatus(int $status): self
    {
        return new self(
            $this->code,
            $this->parameters,
            $this->pointer,
            $this->parameter,
            $this->header,
            $this->meta,
            $this->typeLink,
            $this->title,
            $this->detail,
            $status,
        );
    }

    /**
     * RFC 6901 3: a pointer is empty or starts with `/`, and every `~` starts `~0` or `~1`;
     * any other byte, an LF included, is legal.
     * `strtr()` removes both escapes in one pass without rescanning what it replaced,
     * so a `~` left over escapes nothing: `~~01` keeps one.
     *
     * no regular expression: on a valid pointer of a few megabytes
     * `preg_match()` hits the backtrack limit and returns false
     */
    private static function isPointer(string $pointer): bool
    {
        return ($pointer === '' || str_starts_with($pointer, '/'))
            && !str_contains(strtr($pointer, ['~0' => '', '~1' => '']), '~');
    }
}
