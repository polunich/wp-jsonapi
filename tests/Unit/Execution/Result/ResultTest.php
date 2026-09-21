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

namespace Polunich\WpJsonApi\Tests\Unit\Execution\Result;

use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Execution\Result\AcceptedResult;
use Polunich\WpJsonApi\Execution\Result\AtomicResult;
use Polunich\WpJsonApi\Execution\Result\CollectionResult;
use Polunich\WpJsonApi\Execution\Result\ContentResult;
use Polunich\WpJsonApi\Execution\Result\MetaResult;
use Polunich\WpJsonApi\Execution\Result\NoContentResult;
use Polunich\WpJsonApi\Execution\Result\NotModifiedResult;
use Polunich\WpJsonApi\Execution\Result\Page;
use Polunich\WpJsonApi\Execution\Result\RecordGraph;
use Polunich\WpJsonApi\Execution\Result\RecordLinkage;
use Polunich\WpJsonApi\Execution\Result\RelationshipResult;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Execution\Result\ResourceResult;
use Polunich\WpJsonApi\Execution\Result\Result;
use Polunich\WpJsonApi\Http\Content;
use Polunich\WpJsonApi\Http\EntityTag;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use ReflectionClass;
use ReflectionMethod;

/**
 * `CoversClass` on an interface warns once coverage is collected:
 * php-code-coverage maps no interface as a class target
 *
 * @internal
 */
#[CoversNothing]
final class ResultTest extends TestCase
{
    public function testTheInterfaceIsInternal(): void
    {
        $interface = new ReflectionClass(Result::class);

        self::assertTrue($interface->isInterface());
        self::assertStringContainsString('@internal', (string) $interface->getDocComment());
    }

    public function testDeclaresTheStatusAndTheEntityTagAndNoOtherMember(): void
    {
        $methods = (new ReflectionClass(Result::class))->getMethods();

        self::assertSame(
            ['status' => 'int', 'entityTag' => '?' . EntityTag::class],
            array_combine(
                array_map(static function (ReflectionMethod $method): string {
                    return $method->getName();
                }, $methods),
                array_map(static function (ReflectionMethod $method): string {
                    return (string) $method->getReturnType();
                }, $methods),
            ),
        );
    }

    public function testTheProviderListsEveryResultOfTheNamespace(): void
    {
        $declared = [];
        foreach ((array) glob(dirname((string) (new ReflectionClass(
            Result::class,
        ))->getFileName()) . '/*.php') as $file) {
            $class = 'Polunich\\WpJsonApi\\Execution\\Result\\' . basename((string) $file, '.php');
            if (class_exists($class) && (new ReflectionClass($class))->implementsInterface(Result::class)) {
                $declared[] = $class;
            }
        }

        $listed = array_map(static function (array $result): string {
            return $result[0];
        }, iterator_to_array(self::results(), false));
        sort($declared);
        sort($listed);

        self::assertSame($declared, $listed);
    }

    /**
     * @param class-string<Result> $class
     */
    #[DataProvider('results')]
    public function testEveryResultReturnsTheStatusOfItsKind(string $class, int $status): void
    {
        self::assertTrue((new ReflectionClass($class))->implementsInterface(Result::class));
        self::assertSame($status, self::build($class)->status());
    }

    /**
     * @param class-string<Result> $class
     */
    #[DataProvider('resultClasses')]
    public function testEveryResultMarksBothMethodsAsAnOverride(string $class): void
    {
        foreach (['status', 'entityTag'] as $name) {
            self::assertNotSame(
                [],
                (new ReflectionMethod($class, $name))->getAttributes(Override::class),
                sprintf('%s::%s() is no override of the interface', $class, $name),
            );
        }
    }

    /**
     * @return iterable<string, array{class-string<Result>, int}>
     */
    public static function results(): iterable
    {
        yield 'a resource document' => [ResourceResult::class, 200];
        yield 'a collection document' => [CollectionResult::class, 200];
        yield 'a relationship document' => [RelationshipResult::class, 200];
        yield 'a write that returned nothing' => [NoContentResult::class, 204];
        yield 'a condition that did not hold' => [NotModifiedResult::class, 304];
        yield 'a write a store accepted' => [AcceptedResult::class, 202];
        yield 'an atomic response' => [AtomicResult::class, 200];
        yield 'a document of meta alone' => [MetaResult::class, 200];
        yield 'a media response' => [ContentResult::class, 200];
    }

    /**
     * @return iterable<string, array{class-string<Result>}>
     */
    public static function resultClasses(): iterable
    {
        foreach (self::results() as $name => $result) {
            yield $name => [$result[0]];
        }
    }

    /**
     * @param class-string<Result> $class
     */
    private static function build(string $class): Result
    {
        $record = new ResourceRecord('pets', '1', new Pet('1', 'Rex'));
        $fields = new SparseFieldsets([]);
        $graph = new RecordGraph(false);

        return match ($class) {
            ResourceResult::class => new ResourceResult('pets', $record, $graph, $fields, 200, null),
            CollectionResult::class => CollectionResult::paged('pets', self::page([$record]), $graph, $fields),
            RelationshipResult::class => new RelationshipResult(
                'pets',
                '1',
                'owner',
                'customers',
                RecordLinkage::toOne(null),
                $graph,
                $fields,
                null,
            ),
            NoContentResult::class => new NoContentResult(),
            NotModifiedResult::class => new NotModifiedResult(new EntityTag('1')),
            AcceptedResult::class => new AcceptedResult($record, $graph, $fields),
            AtomicResult::class => new AtomicResult([
                new ResourceResult('pets', $record, $graph, $fields, 201, null),
            ]),
            MetaResult::class => new MetaResult(['total' => 1]),
            ContentResult::class => new ContentResult(Content::bytes('Rex'), new MediaType('text', 'plain'), null),
            default => self::fail(sprintf('the test builds no %s', $class)),
        };
    }

    /**
     * @param list<ResourceRecord> $records
     */
    private static function page(array $records): Page
    {
        return new Page($records, Strategy::PageNumber, 10, 1, null, false, false, false, null, null, null, null);
    }
}
