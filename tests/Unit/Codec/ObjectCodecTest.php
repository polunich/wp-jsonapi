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

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\DecodeException;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\NullableCodec;
use Polunich\WpJsonApi\Codec\ObjectCodec;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Codec\Violation;

/**
 * @internal
 */
#[CoversClass(ObjectCodec::class)]
final class ObjectCodecTest extends TestCase
{
    public function testTheDeclaredMembersAreDecoded(): void
    {
        $codec = new ObjectCodec(['type' => new StringCodec(), 'id' => new StringCodec()], ['type', 'id']);

        $decoded = $codec->decode(self::wire('{"id":"1","type":"pets"}'), Pointer::root());

        self::assertSame(['type' => 'pets', 'id' => '1'], $decoded);
    }

    public function testTheDecodedMembersFollowTheDeclarationOrder(): void
    {
        $codec = new ObjectCodec(['type' => new StringCodec(), 'id' => new StringCodec()]);

        $decoded = $codec->decode(self::wire('{"id":"1","type":"pets"}'), Pointer::root());

        self::assertSame(['type', 'id'], array_keys($decoded));
    }

    #[DataProvider('valuesThatAreNoJsonObject')]
    public function testAValueThatIsNoJsonObjectIsRefused(mixed $input): void
    {
        $violation = self::violations(new ObjectCodec([]), $input)[0];

        self::assertSame('invalid_type', $violation->code);
        self::assertSame(['expected' => 'object'], $violation->parameters);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function valuesThatAreNoJsonObject(): iterable
    {
        yield 'null' => [null];
        yield 'a string' => ['{}'];
        yield 'an empty list, the wire form of []' => [[]];
        yield 'a list' => [['a']];
        yield 'an associative array' => [['type' => 'pets']];
    }

    public function testAnEmptyObjectIsDecodedAsAnEmptyArray(): void
    {
        self::assertSame([], (new ObjectCodec([]))->decode(self::wire('{}'), Pointer::root()));
    }

    public function testARequiredMemberThatIsAbsentIsReportedAtTheObject(): void
    {
        $codec = new ObjectCodec(['type' => new StringCodec()], ['type']);

        $violation = self::violations($codec, self::wire('{}'), Pointer::root()->descend('data'))[0];

        self::assertSame('missing_member', $violation->code);
        self::assertSame(['member' => 'type'], $violation->parameters);
        self::assertSame('/data', $violation->pointer->toString());
    }

    public function testARequiredMemberWhoseValueIsNullIsPresent(): void
    {
        $codec = new ObjectCodec(['name' => new NullableCodec(new StringCodec())], ['name']);

        $decoded = $codec->decode(self::wire('{"name":null}'), Pointer::root());

        self::assertSame(['name' => null], $decoded);
    }

    public function testAMemberThatIsNotDeclaredIsReportedAtItself(): void
    {
        $violation = self::violations(new ObjectCodec([]), self::wire('{"extra":"x"}'))[0];

        self::assertSame('unexpected_member', $violation->code);
        self::assertSame(['member' => 'extra'], $violation->parameters);
        self::assertSame('/extra', $violation->pointer->toString());
    }

    public function testAMemberNameOfDigitsStaysAMemberName(): void
    {
        $violation = self::violations(new ObjectCodec([]), self::wire('{"0":"x"}'))[0];

        self::assertSame('unexpected_member', $violation->code);
        self::assertSame(['member' => '0'], $violation->parameters);
        self::assertSame('/0', $violation->pointer->toString());
    }

    /**
     * guards the cast of the key: `Pointer::descend()` takes a string,
     * and an int key there fails with a `TypeError`,
     * not the `DecodeException` the interface promises
     */
    public function testAMemberNameOfDigitsIsDecodedByTheCodecThatDeclaresIt(): void
    {
        $codec = new ObjectCodec(['0' => new StringCodec()], ['0']);

        $decoded = $codec->decode(self::wire('{"0":"x"}'), Pointer::root());

        self::assertSame([0], array_keys($decoded));
        self::assertSame(['x'], array_values($decoded));
    }

    public function testARequiredMemberWithANameOfDigitsThatIsAbsentIsReported(): void
    {
        $codec = new ObjectCodec(['0' => new StringCodec()], ['0']);

        $violation = self::violations($codec, self::wire('{}'))[0];

        self::assertSame('missing_member', $violation->code);
        self::assertSame(['member' => '0'], $violation->parameters);
    }

    public function testAViolationOfADeclaredMemberWithANameOfDigitsPointsAtThatMember(): void
    {
        $codec = new ObjectCodec(['0' => new IntegerCodec()]);

        $violation = self::violations($codec, self::wire('{"0":"x"}'))[0];

        self::assertSame('invalid_type', $violation->code);
        self::assertSame('/0', $violation->pointer->toString());
    }

    public function testAnUndeclaredMemberIsKeptWhenAdditionalPropertiesAreAllowed(): void
    {
        $codec = new ObjectCodec(['type' => new StringCodec()], [], true);

        $decoded = $codec->decode(self::wire('{"type":"pets","extra":{"a":1}}'), Pointer::root());

        self::assertSame(['type', 'extra'], array_keys($decoded));
        self::assertEquals((object) ['a' => 1], $decoded['extra']);
    }

    public function testEveryInvalidMemberRaisesItsOwnViolation(): void
    {
        $codec = new ObjectCodec(
            ['type' => new StringCodec(), 'id' => new StringCodec(), 'age' => new IntegerCodec()],
            ['type'],
        );

        $violations = self::violations($codec, self::wire('{"id":1,"age":"x","extra":true}'));

        self::assertSame(
            ['missing_member', 'invalid_type', 'invalid_type', 'unexpected_member'],
            array_column($violations, 'code'),
        );
        self::assertSame(
            ['', '/id', '/age', '/extra'],
            array_map(static function (Violation $violation): string {
                return $violation->pointer->toString();
            }, $violations),
        );
    }

    public function testARequiredMemberMustBeADeclaredProperty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a required member must be a declared property: id');

        new ObjectCodec(['type' => new StringCodec()], ['id']);
    }

    public function testTheSchemaCarriesThePropertiesTheRequiredMembersAndAdditionalProperties(): void
    {
        $codec = new ObjectCodec(
            ['type' => new StringCodec(), 'age' => new IntegerCodec(minimum: 0, maximum: 130)],
            ['type'],
        );

        self::assertSame(
            '{"type":"object","properties":{"type":{"type":"string"},'
            . '"age":{"type":"integer","minimum":0,"maximum":130}},'
            . '"required":["type"],"additionalProperties":false}',
            json_encode($codec->schema()->toObject()),
        );
    }

    public function testTheSchemaWritesAMemberNameOfDigitsAsAMemberName(): void
    {
        $codec = new ObjectCodec(['0' => new StringCodec()], ['0']);

        self::assertSame(
            '{"type":"object","properties":{"0":{"type":"string"}},'
            . '"required":["0"],"additionalProperties":false}',
            json_encode($codec->schema()->toObject()),
        );
    }

    public function testEncodeReturnsAStdClass(): void
    {
        $encoded = (new ObjectCodec(['type' => new StringCodec()]))->encode(['type' => 'pets']);

        self::assertEquals((object) ['type' => 'pets'], $encoded);
        self::assertSame('{"type":"pets"}', json_encode($encoded));
    }

    public function testEncodeWritesAnEmptyObjectAsAnEmptyObject(): void
    {
        self::assertSame('{}', json_encode((new ObjectCodec([]))->encode([])));
    }

    public function testEncodeRefusesAValueThatIsNoArrayOfMembers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the value must be an array of members');

        (new ObjectCodec([]))->encode('pets');
    }

    public function testEncodeRefusesAMissingRequiredMember(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a required member is missing: type');

        (new ObjectCodec(['type' => new StringCodec()], ['type']))->encode([]);
    }

    public function testEncodeRefusesAMissingRequiredMemberWithANameOfDigits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a required member is missing: 0');

        (new ObjectCodec(['0' => new StringCodec()], ['0']))->encode([]);
    }

    public function testEncodeWritesAMemberNameOfDigitsAsAMemberName(): void
    {
        $encoded = (new ObjectCodec(['0' => new StringCodec()]))->encode(['0' => 'x']);

        self::assertSame('{"0":"x"}', json_encode($encoded));
    }

    public function testEncodeRefusesAnUndeclaredMember(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the member is not declared: extra');

        (new ObjectCodec([]))->encode(['extra' => 'x']);
    }

    public function testEncodeKeepsAnUndeclaredMemberWhenAdditionalPropertiesAreAllowed(): void
    {
        $encoded = (new ObjectCodec([], [], true))->encode(['extra' => 'x']);

        self::assertEquals((object) ['extra' => 'x'], $encoded);
    }

    private static function wire(string $json): mixed
    {
        return json_decode($json, false, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return non-empty-list<Violation>
     */
    private static function violations(ObjectCodec $codec, mixed $input, ?Pointer $pointer = null): array
    {
        try {
            $codec->decode($input, $pointer ?? Pointer::root());
        } catch (DecodeException $exception) {
            return $exception->violations();
        }

        self::fail('the codec accepted the value');
    }

    public function testARequiredNameNoPropertyDeclaresIsWrittenEscaped(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a required member must be a declared property: a\\nb');

        new ObjectCodec(['name' => new StringCodec()], ["a\nb"]);
    }

    public function testAMissingRequiredMemberIsNamedEscapedWhereAValueIsEncoded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a required member is missing: a\\nb');

        (new ObjectCodec(["a\nb" => new StringCodec()], ["a\nb"]))->encode([]);
    }

    public function testAnUndeclaredMemberIsNamedEscapedWhereAValueIsEncoded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the member is not declared: a\\nb');

        (new ObjectCodec(['name' => new StringCodec()]))->encode(["a\nb" => 'x']);
    }

    public function testAReadOnlyMemberARequestSendsIsRefusedAtItself(): void
    {
        $codec = new ObjectCodec(['version' => new IntegerCodec(), 'day' => new StringCodec()], readOnly: ['version']);

        $violations = self::violations(
            $codec,
            self::wire('{"version":3,"day":"mon"}'),
            Pointer::root()->descend('schedule'),
        );

        self::assertCount(1, $violations);
        self::assertSame('read_only_member', $violations[0]->code);
        self::assertSame(['member' => 'version'], $violations[0]->parameters);
        self::assertSame('/schedule/version', $violations[0]->pointer->toString());
    }

    public function testARequiredReadOnlyMemberIsNoMemberARequestLacks(): void
    {
        $codec = new ObjectCodec(
            ['version' => new IntegerCodec(), 'day' => new StringCodec()],
            ['version', 'day'],
            readOnly: ['version'],
        );

        self::assertSame(['day' => 'mon'], $codec->decode(self::wire('{"day":"mon"}'), Pointer::root()));
    }

    public function testEncodeWritesAReadOnlyMemberAndRequiresARequiredOne(): void
    {
        $codec = new ObjectCodec(['version' => new IntegerCodec()], ['version'], readOnly: ['version']);

        self::assertSame('{"version":3}', json_encode($codec->encode(['version' => 3])));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a required member is missing: version');

        $codec->encode([]);
    }

    public function testAWriteOnlyMemberIsDecodedAndARequiredOneIsRequiredOfARequest(): void
    {
        $codec = new ObjectCodec(['secret' => new StringCodec()], ['secret'], writeOnly: ['secret']);

        self::assertSame(['secret' => 'x'], $codec->decode(self::wire('{"secret":"x"}'), Pointer::root()));

        $violation = self::violations($codec, self::wire('{}'))[0];

        self::assertSame('missing_member', $violation->code);
        self::assertSame(['member' => 'secret'], $violation->parameters);
    }

    /**
     * a decoded value holds its write-only members, so encoding it again must not refuse them
     */
    public function testEncodeNeverWritesAWriteOnlyMember(): void
    {
        $codec = new ObjectCodec(
            ['secret' => new StringCodec(), 'day' => new StringCodec()],
            ['secret'],
            writeOnly: ['secret'],
        );

        self::assertSame('{"day":"mon"}', json_encode($codec->encode(['secret' => 'x', 'day' => 'mon'])));
        self::assertSame('{}', json_encode($codec->encode([])));
    }

    public function testTheSchemaMarksTheMembersOfOneDirection(): void
    {
        $codec = new ObjectCodec(
            [
                'version' => new IntegerCodec(minimum: 1, maximum: 9),
                'secret' => new StringCodec(),
                'day' => new StringCodec(),
            ],
            ['version', 'secret', 'day'],
            readOnly: ['version'],
            writeOnly: ['secret'],
        );

        self::assertSame(
            '{"type":"object","properties":{"version":{"type":"integer","minimum":1,"maximum":9,"readOnly":true},'
            . '"secret":{"type":"string","writeOnly":true},"day":{"type":"string"}},'
            . '"required":["version","secret","day"],"additionalProperties":false}',
            json_encode($codec->schema()->toObject()),
        );
        self::assertSame(
            '{"type":"object","properties":{"secret":{"type":"string","writeOnly":true},"day":{"type":"string"}},'
            . '"required":["secret","day"],"additionalProperties":false}',
            json_encode($codec->schema()->forRequest()->toObject()),
        );
        self::assertSame(
            '{"type":"object","properties":{"version":{"type":"integer","minimum":1,"maximum":9,"readOnly":true},'
            . '"day":{"type":"string"}},"required":["version","day"],"additionalProperties":false}',
            json_encode($codec->schema()->forResponse()->toObject()),
        );
    }

    /**
     * @param array{list<string>, list<string>} $directions read-only and write-only names
     */
    #[DataProvider('undeclaredDirections')]
    public function testAMemberOfOneDirectionMustBeADeclaredProperty(array $directions, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ObjectCodec(['day' => new StringCodec()], readOnly: $directions[0], writeOnly: $directions[1]);
    }

    /**
     * @return iterable<string, array{array{list<string>, list<string>}, string}>
     */
    public static function undeclaredDirections(): iterable
    {
        yield 'read-only' => [[['version'], []], 'a read-only member must be a declared property: version'];
        yield 'write-only' => [[[], ['secret']], 'a write-only member must be a declared property: secret'];
    }

    public function testAMemberIsNeverBothReadOnlyAndWriteOnly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a member travels in one direction, and day is both read-only and write-only');

        new ObjectCodec(['day' => new StringCodec()], readOnly: ['day'], writeOnly: ['day']);
    }
}
