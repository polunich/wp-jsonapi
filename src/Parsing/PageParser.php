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

use InvalidArgumentException;
use Polunich\WpJsonApi\Error\BadRequestException;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Query\Pagination\Cursor;
use Polunich\WpJsonApi\Query\Pagination\CursorText;
use Polunich\WpJsonApi\Query\Pagination\PageMeta;
use Polunich\WpJsonApi\Query\Pagination\PageParameter;
use Polunich\WpJsonApi\Query\Pagination\PageRequest;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use Polunich\WpJsonApi\Query\ParameterFamily;
use Throwable;

/**
 * the parameters the client sent select the strategy, as `Query\Pagination\Strategy` lays out.
 * the errors take the shapes of the cursor pagination profile under every strategy,
 * max page size exceeded included, even where the response applies no profile.
 * a member is read, and named in an error, as the naming policy spells it
 *
 * @internal
 */
final readonly class PageParser
{
    public function __construct(private NamingPolicy $naming) {}

    /**
     * the strategy `parse()` would select, for a caller that holds no parsed page;
     * a `page` value that is no map selects nothing.
     * every selector is one word, spelled alike under each naming policy, so this needs none
     *
     * @param array<array-key, mixed> $query
     */
    public static function strategy(array $query, Strategy $default): Strategy
    {
        $page = ParameterFamily::Page->valueIn($query);

        return self::select(is_array($page) ? $page : [], $default);
    }

    /**
     * a refused parameter is left out, so the result is always a valid request;
     * the error list decides whether it runs
     *
     * @param mixed            $page    a map of names to text, as the client bracketed them
     * @param PaginationPolicy $policy  its maximum bounds the size
     * @param Strategy         $default for a request that selects none
     */
    public function parse(
        mixed $page,
        PaginationPolicy $policy,
        ErrorList $errors,
        Strategy $default = Strategy::PageNumber,
    ): PageRequest {
        $values = $this->read($page, $errors);
        $strategy = self::select($values, $default);
        $values = $this->keepOf($values, $strategy, $errors);

        return match ($strategy) {
            Strategy::PageNumber => PageRequest::pageNumber(
                $this->integer($values, PageParameter::Number, true, $errors),
                $this->size($values, $strategy->sizeParameter(), $policy, $errors),
                $this->flag($values, $errors),
            ),
            Strategy::Offset => PageRequest::offset(
                $this->integer($values, PageParameter::Offset, false, $errors),
                $this->size($values, $strategy->sizeParameter(), $policy, $errors),
                $this->flag($values, $errors),
            ),
            Strategy::Cursor => PageRequest::cursor(
                $this->cursor($values, PageParameter::After, $errors),
                $this->cursor($values, PageParameter::Before, $errors),
                $this->size($values, $strategy->sizeParameter(), $policy, $errors),
                $this->flag($values, $errors),
            ),
        };
    }

    /**
     * a name no strategy carries is refused as `unsupported_query_parameter` and left out;
     * a `page` value that is no map is the guard's to refuse and reads as no page parameter here
     *
     * @return array<string, mixed> keyed by `PageParameter::$value`
     */
    private function read(mixed $page, ErrorList $errors): array
    {
        if (!is_array($page)) {
            return [];
        }

        $values = [];

        foreach ($page as $name => $value) {
            // no page parameter is named with digits, so an int key is always unknown
            $name = (string) $name;
            $parameter = PageParameter::named($name, $this->naming);

            if ($parameter === null) {
                $errors->add(self::unsupported($name));

                continue;
            }

            $values[$parameter->value] = $value;
        }

        return $values;
    }

    /**
     * the first strategy, in the order of `Strategy::cases()`, that a parameter selects.
     * no selector is named with digits, so an unread map with int keys selects what a read one does
     *
     * @param array<array-key, mixed> $values
     */
    private static function select(array $values, Strategy $default): Strategy
    {
        foreach (Strategy::cases() as $strategy) {
            foreach ($strategy->selectors() as $selector) {
                if (array_key_exists($selector->value, $values)) {
                    return $strategy;
                }
            }
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function keepOf(array $values, Strategy $strategy, ErrorList $errors): array
    {
        $kept = [];

        foreach ($values as $name => $value) {
            $parameter = PageParameter::from($name);

            if (!in_array($parameter, $strategy->parameters(), true)) {
                $errors->add(self::conflicting($parameter->nameIn($this->naming)));

                continue;
            }

            $kept[$name] = $value;
        }

        return $kept;
    }

    /**
     * @param array<string, mixed> $values
     * @param bool                 $positive whether the smallest value is 1 rather than 0
     */
    private function integer(array $values, PageParameter $parameter, bool $positive, ErrorList $errors): ?int
    {
        if (!array_key_exists($parameter->value, $values)) {
            return null;
        }

        $name = $parameter->nameIn($this->naming);
        $digits = self::digits($values[$parameter->value]);

        if ($digits === null || ($positive && $digits === '0') || self::exceeds($digits, PHP_INT_MAX)) {
            $errors->add(self::invalid($name));

            return null;
        }

        return (int) $digits;
    }

    /**
     * only this checks the maximum: `PaginationPolicy::usedSize()` resolves the size without it.
     * sizes compare as text, so one beyond `PHP_INT_MAX` is refused as too large, not as unreadable
     *
     * @param array<string, mixed> $values
     */
    private function size(array $values, PageParameter $parameter, PaginationPolicy $policy, ErrorList $errors): ?int
    {
        if (!array_key_exists($parameter->value, $values)) {
            return null;
        }

        $name = $parameter->nameIn($this->naming);
        $digits = self::digits($values[$parameter->value]);

        if ($digits === null || $digits === '0') {
            $errors->add(self::invalid($name));

            return null;
        }

        if (self::exceeds($digits, $policy->maxSize)) {
            $errors->add($this->tooLarge($name, $digits, $policy->maxSize));

            return null;
        }

        return (int) $digits;
    }

    /**
     * `true` or `false`, as JSON spells a boolean; null leaves the default to the strategy
     *
     * @param array<string, mixed> $values
     */
    private function flag(array $values, ErrorList $errors): ?bool
    {
        $parameter = PageParameter::WithCount;

        if (!array_key_exists($parameter->value, $values)) {
            return null;
        }

        $value = $values[$parameter->value];

        if ($value === 'true' || $value === 'false') {
            return $value === 'true';
        }

        $errors->add(self::invalid($parameter->nameIn($this->naming)));

        return null;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function cursor(array $values, PageParameter $parameter, ErrorList $errors): ?Cursor
    {
        if (!array_key_exists($parameter->value, $values)) {
            return null;
        }

        $name = $parameter->nameIn($this->naming);
        $value = $values[$parameter->value];

        if (!is_string($value)) {
            $errors->add(self::invalid($name));

            return null;
        }

        try {
            return CursorText::decode($value);
        } catch (InvalidArgumentException $refusal) {
            $errors->add(self::invalid($name, $refusal));

            return null;
        }
    }

    /**
     * the digits of a `^[0-9]+$` text, the integer syntax of the cursor profile,
     * without leading zeros; null for any other value
     */
    private static function digits(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || strspn($value, '0123456789') !== strlen($value)) {
            return null;
        }

        $digits = ltrim($value, '0');

        return $digits === '' ? '0' : $digits;
    }

    /**
     * compares as text, so digits of any length are safe;
     * it relies on `digits()` having stripped the leading zeros
     */
    private static function exceeds(string $digits, int $bound): bool
    {
        $written = (string) $bound;

        return strlen($digits) > strlen($written)
            || (strlen($digits) === strlen($written) && strcmp($digits, $written) > 0);
    }

    private static function parameter(string $name): string
    {
        return ParameterFamily::Page->parameter($name);
    }

    private static function unsupported(string $name): BadRequestException
    {
        $parameter = self::parameter($name);

        return new BadRequestException([
            new ErrorDescription(ErrorCode::UnsupportedQueryParameter, ['name' => $parameter], parameter: $parameter),
        ]);
    }

    private static function conflicting(string $name): BadRequestException
    {
        $parameter = self::parameter($name);

        return new BadRequestException([
            new ErrorDescription(ErrorCode::MixedPaginationStrategies, ['name' => $parameter], parameter: $parameter),
        ]);
    }

    private static function invalid(string $name, ?Throwable $previous = null): BadRequestException
    {
        $parameter = self::parameter($name);

        return new BadRequestException(
            [new ErrorDescription(ErrorCode::InvalidQueryParameter, ['name' => $parameter], parameter: $parameter)],
            $previous,
        );
    }

    /**
     * the size reaches the detail as text, since it may exceed `PHP_INT_MAX`
     */
    private function tooLarge(string $name, string $size, int $maxSize): BadRequestException
    {
        $parameter = self::parameter($name);

        return new BadRequestException([
            new ErrorDescription(
                ErrorCode::MaxPageSizeExceeded,
                ['size' => $size, 'maxSize' => $maxSize],
                parameter: $parameter,
                meta: [PageMeta::MEMBER => [PageMeta::MaxSize->nameIn($this->naming) => $maxSize]],
                typeLink: CursorProfile::MAX_SIZE_EXCEEDED,
            ),
        ]);
    }
}
