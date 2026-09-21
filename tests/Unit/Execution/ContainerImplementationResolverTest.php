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

namespace Polunich\WpJsonApi\Tests\Unit\Execution;

use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Execution\ContainerImplementationResolver;
use Polunich\WpJsonApi\Metadata\ImplementationReference;
use Polunich\WpJsonApi\Metadata\ImplementationResolver;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Greeter;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\PoliteGreeter;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use RuntimeException;
use stdClass;
use Throwable;
use UnexpectedValueException;

/**
 * @internal
 */
#[CoversClass(ContainerImplementationResolver::class)]
final class ContainerImplementationResolverTest extends TestCase
{
    private const ENTRY = 'project.greeter';

    public function testAnInstanceReferenceResolvesToThatVeryImplementation(): void
    {
        $implementation = new PoliteGreeter();

        $resolved = self::resolver()->resolve(ImplementationReference::instance($implementation), Greeter::class);

        self::assertSame($implementation, $resolved);
    }

    public function testAFactoryReferenceResolvesToWhatTheClosureCreatesFromNoArgument(): void
    {
        $arguments = [];
        $implementation = new PoliteGreeter('good morning');

        $resolved = self::resolver()->resolve(
            ImplementationReference::factory(static function () use (&$arguments, $implementation): Greeter {
                $arguments[] = func_get_args();

                return $implementation;
            }),
            Greeter::class,
        );

        self::assertSame($implementation, $resolved);
        self::assertSame([[]], $arguments);
    }

    public function testAFactoryIsCalledOnEveryResolution(): void
    {
        $calls = 0;
        $reference = ImplementationReference::factory(static function () use (&$calls): Greeter {
            ++$calls;

            return new PoliteGreeter();
        });
        $resolver = self::resolver();

        $first = $resolver->resolve($reference, Greeter::class);
        $second = $resolver->resolve($reference, Greeter::class);

        self::assertSame(2, $calls);
        self::assertNotSame($first, $second);
    }

    public function testAContainerEntryIsFetchedUnderItsEntryId(): void
    {
        $implementation = new PoliteGreeter();
        $container = self::container([self::ENTRY => $implementation]);

        $resolved = self::resolver($container)->resolve(ImplementationReference::service(self::ENTRY), Greeter::class);

        self::assertSame($implementation, $resolved);
        self::assertSame([self::ENTRY], $container->asked);
    }

    public function testTheContainerIsAskedOnEveryResolution(): void
    {
        $container = self::container([self::ENTRY => new PoliteGreeter()]);
        $resolver = self::resolver($container);
        $reference = ImplementationReference::service(self::ENTRY);

        $resolver->resolve($reference, Greeter::class);
        $resolver->resolve($reference, Greeter::class);

        self::assertSame([self::ENTRY, self::ENTRY], $container->asked);
    }

    #[DataProvider('referencesThatNameNoContainerEntry')]
    public function testAReferenceThatNamesNoContainerEntryDoesNotAskTheContainer(
        ImplementationReference $reference,
    ): void {
        $container = self::container([]);

        self::resolver($container)->resolve($reference, Greeter::class);

        self::assertSame([], $container->asked);
    }

    /**
     * @return iterable<string, array{ImplementationReference}>
     */
    public static function referencesThatNameNoContainerEntry(): iterable
    {
        yield 'an instance' => [ImplementationReference::instance(new PoliteGreeter())];
        yield 'a factory closure' => [ImplementationReference::factory(static function (): Greeter {
            return new PoliteGreeter();
        })];
    }

    public function testAContainerEntryWithoutAContainerIsRefusedAsACallOutOfOrder(): void
    {
        try {
            (new ContainerImplementationResolver())->resolve(
                ImplementationReference::service(self::ENTRY),
                Greeter::class,
            );
            self::fail('the resolver looked up an entry id without a container');
        } catch (LogicException $refusal) {
            self::assertStringContainsString(self::ENTRY, $refusal->getMessage());
        }
    }

    #[DataProvider('referencesToAnImplementationOfAnotherClass')]
    public function testAnImplementationThatIsNoInstanceOfThePortIsRefused(ImplementationReference $reference): void
    {
        try {
            self::resolver(self::container([self::ENTRY => new stdClass()]))->resolve($reference, Greeter::class);
            self::fail('the resolver returned an implementation that is no instance of the port');
        } catch (UnexpectedValueException $refusal) {
            self::assertStringContainsString(Greeter::class, $refusal->getMessage());
            self::assertStringContainsString(stdClass::class, $refusal->getMessage());
        }
    }

    /**
     * @return iterable<string, array{ImplementationReference}>
     */
    public static function referencesToAnImplementationOfAnotherClass(): iterable
    {
        yield 'an instance' => [ImplementationReference::instance(new stdClass())];
        yield 'a factory closure' => [ImplementationReference::factory(static function (): object {
            return new stdClass();
        })];
        yield 'a container entry id' => [ImplementationReference::service(self::ENTRY)];
    }

    public function testAContainerEntryThatIsNoObjectIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);

        self::resolver(self::container([self::ENTRY => 'a greeting']))
            ->resolve(ImplementationReference::service(self::ENTRY), Greeter::class);
    }

    public function testAnExceptionOfTheContainerIsNotCaught(): void
    {
        $failure = self::containerFailure();

        try {
            self::resolver(self::failingContainer($failure))
                ->resolve(ImplementationReference::service(self::ENTRY), Greeter::class);
            self::fail('the resolver swallowed an exception of the container');
        } catch (Throwable $thrown) {
            self::assertSame($failure, $thrown);
        }
    }

    public function testTheResolverIsTheImplementationResolverOfMetadataAndIsInternalAndImmutable(): void
    {
        $resolver = new ReflectionClass(ContainerImplementationResolver::class);

        self::assertTrue($resolver->implementsInterface(ImplementationResolver::class));
        self::assertStringContainsString('@internal', (string) $resolver->getDocComment());
        self::assertTrue($resolver->isFinal());
        self::assertTrue($resolver->isReadOnly());
    }

    private static function resolver(?ContainerInterface $container = null): ContainerImplementationResolver
    {
        return new ContainerImplementationResolver($container);
    }

    /**
     * @param array<string, mixed> $entries
     *
     * @return ContainerInterface&object{asked: list<string>}
     */
    private static function container(array $entries): ContainerInterface
    {
        return new class ($entries) implements ContainerInterface {
            /**
             * @var list<string>
             */
            public array $asked = [];

            /**
             * @param array<string, mixed> $entries
             */
            public function __construct(private readonly array $entries) {}

            #[Override]
            public function get(string $id): mixed
            {
                $this->asked[] = $id;

                if (!array_key_exists($id, $this->entries)) {
                    throw ContainerImplementationResolverTest::containerFailure();
                }

                return $this->entries[$id];
            }

            #[Override]
            public function has(string $id): bool
            {
                return array_key_exists($id, $this->entries);
            }
        };
    }

    private static function failingContainer(Throwable $failure): ContainerInterface
    {
        return new class ($failure) implements ContainerInterface {
            public function __construct(private readonly Throwable $failure) {}

            #[Override]
            public function get(string $id): mixed
            {
                throw $this->failure;
            }

            #[Override]
            public function has(string $id): bool
            {
                return false;
            }
        };
    }

    /**
     * the `NotFoundExceptionInterface` PSR-11 requires for a missing entry;
     * public because the anonymous container calls it
     */
    public static function containerFailure(): NotFoundExceptionInterface
    {
        $message = 'the container holds no such entry';

        return new class ($message) extends RuntimeException implements NotFoundExceptionInterface {};
    }
}
