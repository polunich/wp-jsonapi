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

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Console\Configuration;
use Polunich\WpJsonApi\Tests\Fixtures\Console\ContractMethods;
use Polunich\WpJsonApi\Tests\Fixtures\TemporaryDirectory;
use ReflectionClass;
use RuntimeException;
use UnexpectedValueException;

/**
 * @internal
 */
#[CoversClass(Configuration::class)]
final class ConfigurationTest extends TestCase
{
    private const METHODS = ContractMethods::class;

    private string $directory;

    #[Override]
    protected function setUp(): void
    {
        $this->directory = TemporaryDirectory::create();
    }

    #[Override]
    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->directory);
    }

    /**
     * the format of `extra.wp-jsonapi.api` is public, the class that reads it is not
     */
    public function testTheClassIsFinalAndInternal(): void
    {
        $class = new ReflectionClass(Configuration::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    /**
     * the failing method proves that loading calls no method
     */
    public function testOneApiGoesToOpenApiJsonBesideComposerJson(): void
    {
        $this->compose(self::METHODS . '::failing');

        $configuration = Configuration::load($this->directory, '/elsewhere', null);

        self::assertSame($this->directory . '/composer.json', $configuration->file);
        self::assertSame([$this->directory . '/openapi.json'], array_keys($configuration->contracts));
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('no declaration');

        ($configuration->contracts[$this->directory . '/openapi.json'])();
    }

    public function testTheContractIsTheJsonOfTheBuiltApi(): void
    {
        $this->compose('\\' . self::METHODS . '::first');

        $contracts = Configuration::load($this->directory, '', null)->contracts;

        self::assertSame(ContractMethods::FIRST, ($contracts[$this->directory . '/openapi.json'])());
    }

    public function testAProjectDirectoryWithATrailingSeparatorFindsItsFilesWithOneSeparator(): void
    {
        $this->compose(self::METHODS . '::first');

        $configuration = Configuration::load($this->directory . '/', '', null);

        self::assertSame($this->directory . '/composer.json', $configuration->file);
        self::assertSame([$this->directory . '/openapi.json'], array_keys($configuration->contracts));
    }

    public function testOutputNamesTheFileOfTheOneApi(): void
    {
        $this->compose(self::METHODS . '::first');

        self::assertSame(
            ['/work/docs/api.json'],
            array_keys(Configuration::load($this->directory, '/work', 'docs/api.json')->contracts),
        );
        self::assertSame(
            ['/srv/api.json'],
            array_keys(Configuration::load($this->directory, '/work', '/srv/api.json')->contracts),
        );
    }

    public function testRefusesARelativeOutputWithoutAWorkingDirectory(): void
    {
        $this->compose(self::METHODS . '::first');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'the contract file "api.json" is named relative to the working directory, which the process'
            . ' cannot tell; name it with an absolute --output',
        );

        Configuration::load($this->directory, '', 'api.json');
    }

    public function testAnObjectMapsEachContractFileOfTheProjectToItsApi(): void
    {
        $this->compose((object) ['b.json' => self::METHODS . '::second', 'docs/a.json' => self::METHODS . '::first']);

        $contracts = Configuration::load($this->directory, '/elsewhere', null)->contracts;

        self::assertSame([$this->directory . '/b.json', $this->directory . '/docs/a.json'], array_keys($contracts));
        self::assertSame(ContractMethods::SECOND, ($contracts[$this->directory . '/b.json'])());
        self::assertSame(ContractMethods::FIRST, ($contracts[$this->directory . '/docs/a.json'])());
    }

    public function testRefusesOutputBesideSeveralApis(): void
    {
        $this->compose((object) ['a.json' => self::METHODS . '::first']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf(
            '--output names one contract file, and "%s/composer.json" maps several APIs to their files in'
            . ' extra.wp-jsonapi.api',
            $this->directory,
        ));

        Configuration::load($this->directory, '/work', 'api.json');
    }

    public function testRefusesAProjectWithoutComposerJson(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf(
            'the project has no "%s/composer.json", and the command reads the APIs it builds from its'
            . ' extra.wp-jsonapi.api',
            $this->directory,
        ));

        Configuration::load($this->directory, '', null);
    }

    public function testRefusesADirectoryInThePlaceOfComposerJson(): void
    {
        mkdir($this->directory . '/composer.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the project has no');

        Configuration::load($this->directory, '', null);
    }

    public function testRefusesComposerJsonThatCannotBeRead(): void
    {
        $this->compose(self::METHODS . '::first');
        chmod($this->directory . '/composer.json', 0o200);

        if (is_readable($this->directory . '/composer.json')) {
            self::markTestSkipped('the process reads a file without the read permission, as root does');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('the file "%s/composer.json" cannot be read', $this->directory));

        Configuration::load($this->directory, '', null);
    }

    #[DataProvider('unusableFiles')]
    public function testRefusesComposerJsonThatNamesNoUsableApi(string $json, string $message): void
    {
        file_put_contents($this->directory . '/composer.json', $json);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf($message, $this->directory . '/composer.json'));

        Configuration::load($this->directory, '', null);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusableFiles(): iterable
    {
        $noApi = '"%s" names no API: add "extra": {"wp-jsonapi": {"api": "Vendor\\\\Package\\\\Api::define"}},'
            . ' a public static method that returns the ApiBuilder of the API';

        yield 'no JSON' => ['{"extra":', 'the file "%s" is no JSON: Syntax error'];
        yield 'a JSON list' => ['[]', 'the file "%s" holds no JSON object'];
        yield 'no extra' => ['{}', $noApi];
        yield 'extra that is no object' => ['{"extra": []}', $noApi];
        yield 'no options of the library' => ['{"extra": {"other": {}}}', $noApi];
        yield 'no api' => ['{"extra": {"wp-jsonapi": {}}}', $noApi];
        yield 'a null api' => ['{"extra": {"wp-jsonapi": {"api": null}}}', $noApi];
        yield 'a number' => [
            '{"extra": {"wp-jsonapi": {"api": 1}}}',
            'extra.wp-jsonapi.api of "%s" is int, and it is one method, written Class::method,'
            . ' or an object that maps the path of each contract file to its method',
        ];
        yield 'a list' => ['{"extra": {"wp-jsonapi": {"api": ["a"]}}}', 'extra.wp-jsonapi.api of "%s" is array'];
        yield 'an empty object' => [
            '{"extra": {"wp-jsonapi": {"api": {}}}}',
            'extra.wp-jsonapi.api of "%s" maps no API, so a build would write nothing and a check would'
            . ' compare nothing',
        ];
        yield 'an absolute path' => [
            '{"extra": {"wp-jsonapi": {"api": {"/srv/api.json": "A::b"}}}}',
            'extra.wp-jsonapi.api of "%s" maps the contract file "/srv/api.json", and a path of composer.json is'
            . ' relative to the project',
        ];
        yield 'an empty path' => [
            '{"extra": {"wp-jsonapi": {"api": {"": "A::b"}}}}',
            'maps the contract file "", and a path of composer.json is relative to the project',
        ];
        yield 'no method for a path' => [
            '{"extra": {"wp-jsonapi": {"api": {"a.json": 1}}}}',
            'extra.wp-jsonapi.api of "%s" maps the contract file "a.json" to int, and a contract is built by a'
            . ' method, written Class::method',
        ];
    }

    /**
     * @param array<string, string> $api
     */
    #[DataProvider('pathsOfOneFile')]
    public function testRefusesTwoPathsOfOneFile(array $api, string $message): void
    {
        $this->compose((object) $api);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf(
            'extra.wp-jsonapi.api of "%s/composer.json" maps %s, which name one file',
            $this->directory,
            $message,
        ));

        Configuration::load($this->directory, '', null);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function pathsOfOneFile(): iterable
    {
        $method = self::METHODS . '::first';

        yield 'a dot segment' => [
            ['api/b.json' => $method, 'api/./b.json' => $method],
            '"api/b.json" and "api/./b.json"',
        ];
        yield 'a double separator' => [
            ['api/b.json' => $method, 'api//b.json' => $method],
            '"api/b.json" and "api//b.json"',
        ];
        yield 'a parent segment' => [
            ['api/b.json' => $method, 'api/c/../b.json' => $method],
            '"api/b.json" and "api/c/../b.json"',
        ];
    }

    public function testDifferentFilesAreKeptApart(): void
    {
        $method = self::METHODS . '::first';
        $this->compose((object) [
            'api/b.json' => $method,
            'api/B.json' => $method,
            'api/c/b.json' => $method,
            'api/b.json/..x' => $method,
        ]);

        self::assertCount(4, Configuration::load($this->directory, '', null)->contracts);
    }

    #[DataProvider('unusableMethods')]
    public function testRefusesAMethodTheCommandCannotCall(string $method, string $message): void
    {
        $this->compose($method);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf($message, $this->directory . '/composer.json'));

        Configuration::load($this->directory, '', null);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusableMethods(): iterable
    {
        $noMethod = 'extra.wp-jsonapi.api of "%%s" names the API "%s", and an API is named by its method, written'
            . ' Class::method';
        $notCallable = 'the API "%s" is no public static method that takes no argument';

        yield 'a class alone' => [self::METHODS, sprintf($noMethod, self::METHODS)];
        yield 'a call' => [self::METHODS . '::first()', sprintf($noMethod, self::METHODS . '::first()')];
        yield 'an empty string' => ['', sprintf($noMethod, '')];
        yield 'a class the autoloader cannot load' => [
            'Acme\Missing::define',
            'the API "Acme\Missing::define" names the class Acme\Missing, which the autoloader of the project'
            . ' cannot load',
        ];
        yield 'a method the class does not declare' => [
            self::METHODS . '::missing',
            sprintf(
                'the API "%s::missing" names a method that %s does not declare',
                self::METHODS,
                self::METHODS,
            ),
        ];
        yield 'a method of an instance' => [
            self::METHODS . '::onAnInstance',
            sprintf($notCallable, self::METHODS . '::onAnInstance'),
        ];
        yield 'a protected method' => [
            self::METHODS . '::hidden',
            sprintf($notCallable, self::METHODS . '::hidden'),
        ];
        yield 'a method that takes an argument' => [
            self::METHODS . '::takesAnArgument',
            sprintf($notCallable, self::METHODS . '::takesAnArgument'),
        ];
    }

    /**
     * the method runs when the contract is asked for, so these fail the closure and not the load
     */
    #[DataProvider('unusableResults')]
    public function testRefusesAMethodThatBuildsNoContract(string $name, string $message): void
    {
        $this->compose(self::METHODS . '::' . $name);
        $contract = Configuration::load($this->directory, '', null)->contracts[$this->directory . '/openapi.json'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf($message, self::METHODS . '::' . $name));

        $contract();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusableResults(): iterable
    {
        yield 'no builder' => [
            'returnsNoBuilder',
            'the API "%s" returns string, and the contract is built from the ApiBuilder it returns',
        ];
        yield 'a builder that builds no API' => [
            'buildsNoApi',
            'the builder of the API "%s" builds stdClass, which writes no contract',
        ];
    }

    /**
     * the drive and backslash forms count only on Windows
     */
    #[DataProvider('paths')]
    public function testAnAbsolutePathStartsAtTheRoot(string $path, bool $absolute): void
    {
        self::assertSame($absolute, Configuration::isAbsolute($path));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function paths(): iterable
    {
        $windows = DIRECTORY_SEPARATOR === '\\';

        yield 'the root' => ['/', true];
        yield 'a file below the root' => ['/srv/api/openapi.json', true];
        yield 'empty' => ['', false];
        yield 'a name' => ['openapi.json', false];
        yield 'a dot path' => ['./openapi.json', false];
        yield 'a URL' => ['file:///openapi.json', false];
        yield 'a drive root' => ['C:\\api\\openapi.json', $windows];
        yield 'a drive root with a slash' => ['C:/api/openapi.json', $windows];
        yield 'a backslash' => ['\\api\\openapi.json', $windows];
        yield 'a drive without its root' => ['C:openapi.json', false];
    }

    public function testQuotesAPathOnOneLine(): void
    {
        self::assertSame('"/a\\nb.json"', Configuration::quote("/a\nb.json"));
    }

    /**
     * writes `composer.json` with `extra.wp-jsonapi.api`
     */
    private function compose(string|object $api): void
    {
        file_put_contents(
            $this->directory . '/composer.json',
            json_encode(['extra' => ['wp-jsonapi' => ['api' => $api]]], JSON_THROW_ON_ERROR),
        );
    }
}
