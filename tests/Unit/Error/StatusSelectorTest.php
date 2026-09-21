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
use Polunich\WpJsonApi\Error\StatusSelector;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(StatusSelector::class)]
final class StatusSelectorTest extends TestCase
{
    /**
     * @param list<int> $statuses
     */
    #[DataProvider('oneStatus')]
    public function testProblemsOfOneStatusKeepIt(array $statuses, int $selected): void
    {
        self::assertSame($selected, (new StatusSelector())->select($statuses));
    }

    /**
     * @return array<string, array{list<int>, int}>
     */
    public static function oneStatus(): array
    {
        return [
            'one problem' => [[422], 422],
            'three problems of one status' => [[404, 404, 404], 404],
            'a status without a class of the library' => [[418, 418], 418],
            'a 400 alone' => [[400], 400],
        ];
    }

    /**
     * @param list<int> $statuses
     */
    #[DataProvider('severalStatuses')]
    public function testProblemsOfSeveralClientErrorStatusesGive400(array $statuses): void
    {
        self::assertSame(400, (new StatusSelector())->select($statuses));
    }

    /**
     * @return array<string, array{list<int>}>
     */
    public static function severalStatuses(): array
    {
        return [
            'an invalid value and a missing related resource' => [[422, 404]],
            'a 400 among them' => [[400, 422]],
            'repeats of two statuses' => [[403, 409, 403]],
            'the bounds of the class' => [[400, 499]],
            'one repeated status and another at the end' => [[422, 422, 422, 415]],
        ];
    }

    public function testNoStatusIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a status is selected among the statuses of at least one problem');

        (new StatusSelector())->select([]);
    }

    /**
     * @param list<int> $statuses
     */
    #[DataProvider('statusesOutsideTheClientErrors')]
    public function testAStatusOutsideTheClientErrorsIsRefused(array $statuses, int $refused): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a status is selected among client errors, 4xx, got ' . $refused);

        (new StatusSelector())->select($statuses);
    }

    /**
     * @return array<string, array{list<int>, int}>
     */
    public static function statusesOutsideTheClientErrors(): array
    {
        return [
            'a server error alone' => [[500], 500],
            'a server error among client errors' => [[422, 500], 500],
            'just below the class' => [[399], 399],
            'just above the class' => [[404, 500, 404], 500],
            'a success' => [[200], 200],
        ];
    }

    /**
     * so that one instance serves every request
     */
    public function testTheSelectorHoldsNoState(): void
    {
        $reflection = new ReflectionClass(StatusSelector::class);

        self::assertSame([], $reflection->getProperties());
        self::assertTrue($reflection->isReadOnly());
        self::assertStringContainsString('@internal', (string) $reflection->getDocComment());
    }
}
