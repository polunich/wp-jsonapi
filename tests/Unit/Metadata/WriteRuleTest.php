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

namespace Polunich\WpJsonApi\Tests\Unit\Metadata;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Metadata\WriteRule;
use ReflectionClass;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(WriteRule::class)]
final class WriteRuleTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(WriteRule::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testTheThreeFlagsAndReadOnlyKeepTheirSignatures(): void
    {
        $class = new ReflectionClass(WriteRule::class);

        self::assertSame(
            [['creatable', 'bool'], ['updatable', 'bool'], ['required', 'bool']],
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                $class->getConstructor()?->getParameters() ?? [],
            ),
        );
        self::assertTrue($class->getMethod('readOnly')->isStatic());
        self::assertSame([], $class->getMethod('readOnly')->getParameters());
    }

    #[DataProvider('rules')]
    public function testCarriesWhatTheFieldDeclares(bool $creatable, bool $updatable, bool $required): void
    {
        $rule = new WriteRule($creatable, $updatable, $required);

        self::assertSame([$creatable, $updatable, $required], [$rule->creatable, $rule->updatable, $rule->required]);
    }

    /**
     * @return iterable<string, array{bool, bool, bool}>
     */
    public static function rules(): iterable
    {
        yield 'read only' => [false, false, false];
        yield 'creatable only' => [true, false, false];
        yield 'updatable only' => [false, true, false];
        yield 'creatable and updatable' => [true, true, false];
        yield 'required on create' => [true, false, true];
        yield 'required on create and updatable' => [true, true, true];
    }

    #[DataProvider('requiredWithoutCreatable')]
    public function testRefusesARequiredFieldThatIsNotCreatable(bool $updatable): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a field required on create is creatable');

        new WriteRule(false, $updatable, true);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function requiredWithoutCreatable(): iterable
    {
        yield 'not updatable' => [false];
        yield 'updatable' => [true];
    }

    public function testTheReadOnlyRuleWritesNothing(): void
    {
        $rule = WriteRule::readOnly();

        self::assertFalse($rule->creatable);
        self::assertFalse($rule->updatable);
        self::assertFalse($rule->required);
    }
}
