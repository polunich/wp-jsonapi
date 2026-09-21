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

namespace Polunich\WpJsonApi\Codec;

use InvalidArgumentException;

/**
 * the codes the built-in codecs raise.
 * message texts refer to the value of a code by the name of its parameter
 */
enum ViolationCode: string
{
    case InvalidType = 'invalid_type';

    /** the value is not the `const` of the schema */
    case InvalidValue = 'invalid_value';

    /** the value is none of the `enum` values or `oneOf` discriminators of the schema */
    case ValueNotAllowed = 'value_not_allowed';

    case InvalidFormat = 'invalid_format';

    case ValueTooSmall = 'value_too_small';

    case ValueTooLarge = 'value_too_large';

    case ValueTooShort = 'value_too_short';

    case ValueTooLong = 'value_too_long';

    case PatternMismatch = 'pattern_mismatch';

    case TooFewItems = 'too_few_items';

    case TooManyItems = 'too_many_items';

    case MissingMember = 'missing_member';

    case UnexpectedMember = 'unexpected_member';

    /** a member only a response carries, which a request may not send */
    case ReadOnlyMember = 'read_only_member';

    /** the default code of `RejectedValueException` */
    case ValueRejected = 'value_rejected';

    /**
     * no default arm, so PHPStan reports a case added without its parameter
     */
    public function parameter(): ?string
    {
        return match ($this) {
            self::InvalidType, self::InvalidValue => 'expected',
            self::ValueNotAllowed => 'allowed',
            self::InvalidFormat => 'format',
            self::ValueTooSmall => 'minimum',
            self::ValueTooLarge => 'maximum',
            self::ValueTooShort => 'minLength',
            self::ValueTooLong => 'maxLength',
            self::PatternMismatch => 'pattern',
            self::TooFewItems => 'minItems',
            self::TooManyItems => 'maxItems',
            self::MissingMember, self::UnexpectedMember, self::ReadOnlyMember => 'member',
            self::ValueRejected => null,
        };
    }

    /**
     * `$value` is null exactly when the code names no parameter:
     * a message needs the value its code names and has no place for another
     *
     * @throws InvalidArgumentException when `$value` breaks that rule
     */
    public function at(Pointer $pointer, int|float|string|bool|null $value = null): Violation
    {
        $parameter = $this->parameter();

        if ($parameter === null) {
            if ($value !== null) {
                throw new InvalidArgumentException(sprintf(
                    'the violation %s names no parameter, and a value was given for one',
                    $this->value,
                ));
            }

            return new Violation($pointer, $this->value);
        }

        if ($value === null) {
            throw new InvalidArgumentException(sprintf(
                'the violation %s names the parameter %s, and no value was given for it',
                $this->value,
                $parameter,
            ));
        }

        return new Violation($pointer, $this->value, [$parameter => $value]);
    }
}
