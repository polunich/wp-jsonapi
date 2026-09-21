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

namespace Polunich\WpJsonApi\Tests\Unit\Codec;

use FilesystemIterator;
use InvalidArgumentException;
use PhpToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Codec\ConstCodec;
use Polunich\WpJsonApi\Codec\DateCodec;
use Polunich\WpJsonApi\Codec\DecodeException;
use Polunich\WpJsonApi\Codec\EnumCodec;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\ListCodec;
use Polunich\WpJsonApi\Codec\MappedCodec;
use Polunich\WpJsonApi\Codec\ObjectCodec;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\RejectedValueException;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Codec\Violation;
use Polunich\WpJsonApi\Codec\ViolationCode;
use Polunich\WpJsonApi\Tests\Fixtures\Codec\Priority;
use Polunich\WpJsonApi\Tests\Fixtures\Codec\Slug;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;
use SplFileInfo;
use stdClass;

/**
 * @internal
 */
#[CoversClass(ViolationCode::class)]
final class ViolationCodeTest extends TestCase
{
    public function testEveryCaseHasItsStableCodeAndItsParameter(): void
    {
        $cases = [];

        foreach (ViolationCode::cases() as $case) {
            $cases[$case->value] = $case->parameter();
        }

        self::assertSame(
            [
                'invalid_type' => 'expected',
                'invalid_value' => 'expected',
                'value_not_allowed' => 'allowed',
                'invalid_format' => 'format',
                'value_too_small' => 'minimum',
                'value_too_large' => 'maximum',
                'value_too_short' => 'minLength',
                'value_too_long' => 'maxLength',
                'pattern_mismatch' => 'pattern',
                'too_few_items' => 'minItems',
                'too_many_items' => 'maxItems',
                'missing_member' => 'member',
                'unexpected_member' => 'member',
                'read_only_member' => 'member',
                'value_rejected' => null,
            ],
            $cases,
        );
    }

    public function testAtBuildsTheViolationWithTheParameterOfTheCode(): void
    {
        $pointer = Pointer::root()->descend('data');

        $violation = ViolationCode::ValueTooLong->at($pointer, 200);

        self::assertSame($pointer, $violation->pointer);
        self::assertSame('value_too_long', $violation->code);
        self::assertSame(['maxLength' => 200], $violation->parameters);
    }

    public function testAtBuildsAViolationWithoutParametersForTheCodeThatNamesNone(): void
    {
        $violation = ViolationCode::ValueRejected->at(Pointer::root());

        self::assertSame('value_rejected', $violation->code);
        self::assertSame([], $violation->parameters);
    }

    public function testAtRefusesAValueAgainstTheParameterOfTheCode(): void
    {
        try {
            ViolationCode::InvalidType->at(Pointer::root());
            self::fail('a violation of invalid_type was built without its parameter');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('names the parameter expected', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the violation value_rejected names no parameter');

        ViolationCode::ValueRejected->at(Pointer::root(), 'closed');
    }

    /**
     * a case no built-in codec raises would be dead
     *
     * @param Codec<mixed> $codec
     */
    #[DataProvider('raisedCodes')]
    public function testEveryCaseIsRaisedByABuiltInCodec(
        ViolationCode $code,
        string|int|null $value,
        Codec $codec,
        mixed $input,
    ): void {
        try {
            $codec->decode($input, Pointer::root());
        } catch (DecodeException $exception) {
            $violation = $exception->violations()[0];
            $parameter = $code->parameter();

            self::assertSame($code->value, $violation->code);
            self::assertSame($parameter === null ? [] : [$parameter => $value], $violation->parameters);

            return;
        }

        self::fail('the codec accepted the value instead of raising ' . $code->value);
    }

    public function testTheRaisedCodesAreEveryCase(): void
    {
        $raised = [];

        foreach (self::raisedCodes() as [$code]) {
            $raised[] = $code;
        }

        self::assertSame(ViolationCode::cases(), $raised);
    }

    /**
     * per case, in case order: the parameter value,
     * and a built-in codec with an input that raises the case
     *
     * @return iterable<string, array{ViolationCode, string|int|null, Codec<mixed>, mixed}>
     */
    public static function raisedCodes(): iterable
    {
        yield 'invalid_type' => [ViolationCode::InvalidType, 'string', new StringCodec(), 1];
        yield 'invalid_value' => [ViolationCode::InvalidValue, 'pets', new ConstCodec('pets'), 'customers'];
        yield 'value_not_allowed' => [ViolationCode::ValueNotAllowed, '1, 2', new EnumCodec(Priority::class), 3];
        yield 'invalid_format' => [ViolationCode::InvalidFormat, 'date', new DateCodec(), 'not a date'];
        yield 'value_too_small' => [ViolationCode::ValueTooSmall, 1, new IntegerCodec(minimum: 1), 0];
        yield 'value_too_large' => [ViolationCode::ValueTooLarge, 1, new IntegerCodec(maximum: 1), 2];
        yield 'value_too_short' => [ViolationCode::ValueTooShort, 2, new StringCodec(minLength: 2), 'a'];
        yield 'value_too_long' => [ViolationCode::ValueTooLong, 1, new StringCodec(maxLength: 1), 'ab'];
        yield 'pattern_mismatch' => [ViolationCode::PatternMismatch, '^a$', new StringCodec(pattern: '^a$'), 'b'];
        yield 'too_few_items' => [ViolationCode::TooFewItems, 1, new ListCodec(new StringCodec(), minItems: 1), []];
        yield 'too_many_items' => [
            ViolationCode::TooManyItems,
            1,
            new ListCodec(new StringCodec(), maxItems: 1),
            ['a', 'b'],
        ];
        yield 'missing_member' => [
            ViolationCode::MissingMember,
            'type',
            new ObjectCodec(['type' => new StringCodec()], ['type']),
            new stdClass(),
        ];
        yield 'unexpected_member' => [
            ViolationCode::UnexpectedMember,
            'extra',
            new ObjectCodec([]),
            (object) ['extra' => 'x'],
        ];
        yield 'read_only_member' => [
            ViolationCode::ReadOnlyMember,
            'version',
            new ObjectCodec(['version' => new StringCodec()], readOnly: ['version']),
            (object) ['version' => '3'],
        ];
        yield 'value_rejected' => [
            ViolationCode::ValueRejected,
            null,
            new MappedCodec(
                new StringCodec(),
                static function (string $text): Slug {
                    return $text === 'a'
                        ? throw new RejectedValueException()
                        : new Slug($text);
                },
                static function (Slug $slug): string {
                    return $slug->text;
                },
            ),
            'a',
        ];
    }

    /**
     * every built-in code comes from a `ViolationCode` case:
     * `new Violation(` may appear only in `ViolationCode::at()`,
     * and in `MappedCodec`, which passes on the code of a `RejectedValueException`
     */
    public function testEveryViolationOfTheCodecsIsBuiltByACase(): void
    {
        $violationCode = (new ReflectionClass(ViolationCode::class))->getFileName();
        $mappedCodec = (new ReflectionClass(MappedCodec::class))->getFileName();
        $constructions = self::violationConstructions();
        $files = [];

        foreach ($constructions as [$file, $line, $code]) {
            $where = 'the violation built in ' . $file . ' on line ' . $line;
            $files[] = $file;

            if ($code === null) {
                self::fail($where . ' names no code');
            }

            if ($file === $violationCode && self::isTheValueOfTheCase($code)) {
                continue;
            }

            if ($file === $mappedCodec && self::passesOnTheViolationCode($code)) {
                continue;
            }

            self::fail($where . ' names its code with ' . self::source($code) . ', and a built-in code is a case of '
                . ViolationCode::class . ', built with at()');
        }

        self::assertSame([$mappedCodec, $violationCode], array_values(array_unique($files)));
    }

    /**
     * each `new Violation(` of the Codec sources: file, line, and the tokens of its code argument,
     * or null without one
     *
     * @return list<array{string, int, list<PhpToken>|null}>
     */
    private static function violationConstructions(): array
    {
        $directory = dirname((string) (new ReflectionClass(Violation::class))->getFileName());
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );
        $paths = [];

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);
        $constructions = [];

        foreach ($paths as $path) {
            $tokens = self::tokens($path);

            foreach ($tokens as $index => $token) {
                $name = $tokens[$index + 1] ?? null;

                if (
                    !$token->is(T_NEW)
                    || $name === null
                    || !self::namesViolation($name)
                    || !($tokens[$index + 2] ?? null)?->is('(')
                ) {
                    continue;
                }

                $constructions[] = [$path, $token->line, self::codeArgument(self::arguments($tokens, $index + 3))];
            }
        }

        return $constructions;
    }

    /**
     * @return list<PhpToken>
     */
    private static function tokens(string $path): array
    {
        $source = file_get_contents($path);

        if ($source === false) {
            throw new RuntimeException('the file ' . $path . ' cannot be read');
        }

        return array_values(array_filter(
            PhpToken::tokenize($source),
            static function (PhpToken $token): bool {
                return !$token->isIgnorable();
            },
        ));
    }

    private static function namesViolation(PhpToken $token): bool
    {
        $name = (new ReflectionClass(Violation::class))->getShortName();

        if ($token->is(T_STRING)) {
            return $token->text === $name;
        }

        return $token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])
            && str_ends_with($token->text, '\\' . $name);
    }

    /**
     * `$start` is the index of the token after the opening parenthesis
     *
     * @param list<PhpToken> $tokens
     *
     * @return list<list<PhpToken>>
     */
    private static function arguments(array $tokens, int $start): array
    {
        $arguments = [];
        $argument = [];
        $depth = 0;

        for ($index = $start, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];

            if ($depth === 0 && $token->is([')', ','])) {
                if ($argument !== []) {
                    $arguments[] = $argument;
                }

                if ($token->is(')')) {
                    break;
                }

                $argument = [];

                continue;
            }

            if ($token->is(['(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE])) {
                $depth++;
            } elseif ($token->is([')', ']', '}'])) {
                $depth--;
            }

            $argument[] = $token;
        }

        return $arguments;
    }

    /**
     * the argument named `code`, or else the second positional one
     *
     * @param list<list<PhpToken>> $arguments
     *
     * @return list<PhpToken>|null
     */
    private static function codeArgument(array $arguments): ?array
    {
        $positional = [];

        foreach ($arguments as $argument) {
            if (count($argument) > 2 && $argument[0]->is(T_STRING) && $argument[1]->is(':')) {
                if ($argument[0]->text === 'code') {
                    return array_slice($argument, 2);
                }

                continue;
            }

            $positional[] = $argument;
        }

        return $positional[1] ?? null;
    }

    /**
     * @param list<PhpToken> $argument
     */
    private static function isTheValueOfTheCase(array $argument): bool
    {
        return count($argument) === 3
            && $argument[0]->is(T_VARIABLE)
            && $argument[0]->text === '$this'
            && $argument[1]->is(T_OBJECT_OPERATOR)
            && $argument[2]->text === 'value';
    }

    /**
     * @param list<PhpToken> $argument
     */
    private static function passesOnTheViolationCode(array $argument): bool
    {
        return count($argument) === 5
            && $argument[0]->is(T_VARIABLE)
            && $argument[1]->is(T_OBJECT_OPERATOR)
            && $argument[2]->text === 'violationCode'
            && $argument[3]->is('(')
            && $argument[4]->is(')');
    }

    /**
     * @param list<PhpToken> $tokens
     */
    private static function source(array $tokens): string
    {
        return implode(' ', array_map(static function (PhpToken $token): string {
            return $token->text;
        }, $tokens));
    }
}
