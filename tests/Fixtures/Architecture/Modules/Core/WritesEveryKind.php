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

namespace Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Core;

use Closure;
use Countable;
use DateTimeImmutable;
use LogicException;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Allowed\AllowedValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Allowed\Withheld;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\AbsentValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\Borrowed;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\FailureException;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\ForbiddenValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\IntersectionValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\Marker;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\MethodTagParameterValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\MethodTagValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\MixinValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\ParamTagValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\PropertyReadTagValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\PropertyTagValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\PropertyWriteTagValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\ReturnTagValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\ReturnValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\TemplateBoundValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\UnionValue;

/**
 * a trait of `Core` no class uses that names a `Forbidden` class in every place the rule reads.
 * each name is reported on its line, a tag on the line of the trait or method it documents;
 * a declaration starts at its attribute where it carries one.
 *
 * the names include each member of a union and an intersection type,
 * the template bound of `bound()`, which its `@param` and `@var` tags name,
 * and `AbsentValue`, which no file declares and so matches no target.
 *
 * `unnamed()` names the trait itself, the using class through `self` and `static`, its parent,
 * classes of `Core` and `Allowed` and a built-in class, none of which is reported;
 * it also names `Withheld`, which is reported: the rule excludes it from its targets.
 * nothing is read inside the class `nested()` declares, nor the first-class callable
 * and the closure and arrow function attributes of `unread()`: PHPat skips them for a class too
 *
 * @method MethodTagValue described(MethodTagParameterValue $parameter)
 * @mixin MixinValue
 * @property PropertyTagValue $tagged
 * @property-read PropertyReadTagValue $readable
 * @property-write PropertyWriteTagValue $writable
 */
#[Marker]
trait WritesEveryKind
{
    use Borrowed;

    #[Marker]
    public const LIMIT = ForbiddenValue::LIMIT;

    #[Marker]
    public ?ForbiddenValue $value = null;

    public int $limit = ForbiddenValue::LIMIT;

    /**
     * @param ParamTagValue $tagged
     *
     * @return ReturnTagValue
     *
     * @throws FailureException
     */
    #[Marker]
    public function everyKind(
        #[Marker]
        ?ForbiddenValue $typed,
        mixed $tagged,
        UnionValue|int $union,
        IntersectionValue&Countable $intersection,
        ?AbsentValue $absent,
        int $limit = ForbiddenValue::LIMIT,
    ): ReturnValue {
        /** @var ForbiddenValue $described */
        $described = $tagged;
        $created = new ForbiddenValue();
        $made = ForbiddenValue::make();
        $fetched = ForbiddenValue::LIMIT;
        $check = static fn(#[Marker] ForbiddenValue $argument): bool => $argument === $created;
        try {
            $check($typed ?? $made);
        } catch (FailureException $failure) {
            $described = $failure;
        }
        if ($described instanceof ForbiddenValue && $fetched === $limit) {
            $check($described);
        }

        throw new LogicException('a fixture that is never called');
    }

    /**
     * @template T of TemplateBoundValue
     *
     * @param T $bound
     */
    public function bound(mixed $bound): void
    {
        /** @var T $copy */
        $copy = $bound;
        unset($copy);
    }

    public function unnamed(): string
    {
        return self::class . static::class . parent::class . WritesEveryKind::class . CoreValue::class
            . AllowedValue::class . DateTimeImmutable::class . Withheld::class;
    }

    public function nested(): object
    {
        return new class {
            public ?ForbiddenValue $hidden = null;
        };
    }

    public function unread(): Closure
    {
        $arrow = #[Marker] static fn(): int => 1;
        $closure = #[Marker(ForbiddenValue::LIMIT)] static function (): int {
            return 2;
        };
        unset($arrow, $closure);

        return ForbiddenValue::make(...);
    }
}
