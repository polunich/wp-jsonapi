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

namespace Polunich\WpJsonApi\Tests\Unit\Console;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Console\Arguments;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(Arguments::class)]
final class ArgumentsTest extends TestCase
{
    /**
     * the command line is public, the class that reads it is not
     */
    public function testTheClassIsFinalAndInternal(): void
    {
        $class = new ReflectionClass(Arguments::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    /**
     * @param list<string> $argv
     */
    #[DataProvider('commandLines')]
    public function testReadsTheCommandLinesItDefines(array $argv, string $action, ?string $output): void
    {
        $arguments = Arguments::parse($argv);

        self::assertFalse($arguments->help);
        self::assertSame($action, $arguments->action);
        self::assertSame($output, $arguments->output);
    }

    /**
     * @return iterable<string, array{list<string>, string, ?string}>
     */
    public static function commandLines(): iterable
    {
        yield 'build' => [['wp-jsonapi', 'openapi', 'build'], Arguments::BUILD, null];
        yield 'check' => [['wp-jsonapi', 'openapi', 'check'], Arguments::CHECK, null];
        yield 'the option last' => [
            ['wp-jsonapi', 'openapi', 'check', '--output=docs/api.json'],
            Arguments::CHECK,
            'docs/api.json',
        ];
        yield 'the option first' => [
            ['wp-jsonapi', '--output=/abs/api.json', 'openapi', 'build'],
            Arguments::BUILD,
            '/abs/api.json',
        ];
        yield 'the option between' => [
            ['wp-jsonapi', 'openapi', '--output=api.json', 'build'],
            Arguments::BUILD,
            'api.json',
        ];
        yield 'a value with "="' => [
            ['wp-jsonapi', 'openapi', 'build', '--output=a=b.json'],
            Arguments::BUILD,
            'a=b.json',
        ];
        yield 'the operands after --' => [
            ['wp-jsonapi', '--output=api.json', '--', 'openapi', 'check'],
            Arguments::CHECK,
            'api.json',
        ];
        yield 'the action after --' => [['wp-jsonapi', 'openapi', '--', 'build'], Arguments::BUILD, null];
    }

    /**
     * @param list<string> $argv
     */
    #[DataProvider('helpLines')]
    public function testHelpIgnoresEverythingElse(array $argv): void
    {
        $arguments = Arguments::parse($argv);

        self::assertTrue($arguments->help);
        self::assertNull($arguments->action);
        self::assertNull($arguments->output);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function helpLines(): iterable
    {
        yield 'alone' => [['wp-jsonapi', '--help']];
        yield 'after a command' => [['wp-jsonapi', 'openapi', 'build', '--help']];
        yield 'beside an unknown option' => [['wp-jsonapi', '--verbose', '--help']];
        yield 'beside an unknown command' => [['wp-jsonapi', 'nonsense', '--help']];
    }

    public function testHelpAfterTheEndOfOptionsIsAnOperand(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the argument "--help" follows the action');

        Arguments::parse(['wp-jsonapi', 'openapi', 'check', '--', '--help']);
    }

    /**
     * @param list<string> $argv
     */
    #[DataProvider('refusedLines')]
    public function testRefusesALineItDoesNotDefine(array $argv, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Arguments::parse($argv);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function refusedLines(): iterable
    {
        yield 'nothing' => [['wp-jsonapi'], 'no command is given'];
        yield 'no script name either' => [[], 'no command is given'];
        yield 'an unknown command' => [
            ['wp-jsonapi', 'contract', 'build'],
            'the command "contract" is none the command line knows',
        ];
        yield 'a command in another case' => [['wp-jsonapi', 'OpenApi', 'build'], 'the command "OpenApi" is none'];
        yield 'no action' => [['wp-jsonapi', 'openapi'], 'the command "openapi" is given without its action'];
        yield 'an unknown action' => [
            ['wp-jsonapi', 'openapi', 'write'],
            'the action "write" is none of "build" and "check"',
        ];
        yield 'a lone hyphen as the action' => [['wp-jsonapi', 'openapi', '-'], 'the action "-" is none of'];
        yield 'an operand too many' => [
            ['wp-jsonapi', 'openapi', 'build', 'openapi.json'],
            'the argument "openapi.json" follows the action',
        ];
        yield 'an unknown long option' => [
            ['wp-jsonapi', 'openapi', 'build', '--verbose'],
            'the option "--verbose" is none',
        ];
        yield 'a short option' => [['wp-jsonapi', 'openapi', 'build', '-c'], 'the option "-c" is none'];
        yield 'the option without "="' => [
            ['wp-jsonapi', 'openapi', 'build', '--output', 'api.json'],
            'the option "--output" is none',
        ];
        yield 'the option with an empty value' => [
            ['wp-jsonapi', 'openapi', 'build', '--output='],
            '--output names no file',
        ];
        yield 'the option twice' => [
            ['wp-jsonapi', '--output=a.json', 'openapi', 'build', '--output=b.json'],
            '--output is given twice, as "a.json" and as "b.json"',
        ];
        yield 'the option after --' => [
            ['wp-jsonapi', 'openapi', 'build', '--', '--output=a.json'],
            'the argument "--output=a.json" follows the action',
        ];
        yield 'an unknown option with a value' => [
            ['wp-jsonapi', 'openapi', 'build', '--level=2'],
            'the option "--level=2" is none',
        ];
        yield 'a control character' => [['wp-jsonapi', "open\napi", 'build'], 'the command "open\\napi" is none'];
    }

    /**
     * the usage is written out as it stands, so it carries its own final newline
     */
    public function testTheUsageNamesBothActionsAndTheOption(): void
    {
        self::assertStringStartsWith("usage: wp-jsonapi openapi build [--output=<path>]\n", Arguments::USAGE);
        self::assertStringContainsString('wp-jsonapi openapi check [--output=<path>]', Arguments::USAGE);
        self::assertStringContainsString('wp-jsonapi --help', Arguments::USAGE);
        self::assertStringEndsWith("the way\n", Arguments::USAGE);
    }
}
