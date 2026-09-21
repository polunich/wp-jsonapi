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
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Console\Arguments;
use Polunich\WpJsonApi\Console\OpenApiCommand;
use Polunich\WpJsonApi\Tests\Fixtures\Console\ContractMethods;
use Polunich\WpJsonApi\Tests\Fixtures\TemporaryDirectory;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(OpenApiCommand::class)]
final class OpenApiCommandTest extends TestCase
{
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
     * the command line is public, the class behind it is not
     */
    public function testTheClassIsFinalAndInternal(): void
    {
        $class = new ReflectionClass(OpenApiCommand::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testTheExitStatusesAreThoseOfDiff(): void
    {
        self::assertSame(0, OpenApiCommand::SUCCESS);
        self::assertSame(1, OpenApiCommand::DIFFERENCE);
        self::assertSame(2, OpenApiCommand::TROUBLE);
    }

    /**
     * reflection hands over a value the declared `resource` type refuses, past static analysis
     */
    public function testRefusesAStreamThatIsNone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the command writes its standard error to an open stream, and got string');

        (new ReflectionClass(OpenApiCommand::class))->newInstance(
            self::memory(),
            'php://stderr',
            $this->directory,
            $this->directory,
        );
    }

    public function testRefusesAClosedStream(): void
    {
        $closed = self::memory();
        fclose($closed);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the command writes its standard output to an open stream, and got resource'
            . ' (closed)');

        new OpenApiCommand($closed, self::memory(), $this->directory, $this->directory);
    }

    public function testHelpWritesTheUsageToStandardOutput(): void
    {
        [$status, $output, $error] = $this->command(['wp-jsonapi', '--help']);

        self::assertSame(OpenApiCommand::SUCCESS, $status);
        self::assertSame(Arguments::USAGE, $output);
        self::assertSame('', $error);
    }

    public function testAWrongCommandLineIsTroubleWithTheUsage(): void
    {
        [$status, $output, $error] = $this->command(['wp-jsonapi', 'openapi', 'write']);

        self::assertSame(OpenApiCommand::TROUBLE, $status);
        self::assertSame('', $output);
        self::assertSame("the action \"write\" is none of \"build\" and \"check\"\n\n" . Arguments::USAGE, $error);
    }

    public function testAProjectWithoutComposerJsonIsTrouble(): void
    {
        [$status, $output, $error] = $this->command(['wp-jsonapi', 'openapi', 'check']);

        self::assertSame(OpenApiCommand::TROUBLE, $status);
        self::assertSame('', $output);
        self::assertSame(sprintf(
            "the project has no \"%s/composer.json\", and the command reads the APIs it builds from its"
            . " extra.wp-jsonapi.api\n",
            $this->directory,
        ), $error);
    }

    public function testOneApiIsWrittenBesideComposerJsonOrToTheFileOutputNames(): void
    {
        $this->compose('first');
        mkdir($this->directory . '/docs');

        [$status, $output] = $this->command(['wp-jsonapi', 'openapi', 'build']);

        self::assertSame(OpenApiCommand::SUCCESS, $status);
        self::assertSame(sprintf("wrote \"%s/openapi.json\"\n", $this->directory), $output);
        self::assertSame(ContractMethods::FIRST, file_get_contents($this->directory . '/openapi.json'));

        [$status, $output] = $this->command(['wp-jsonapi', 'openapi', 'build', '--output=docs/api.json']);

        self::assertSame(OpenApiCommand::SUCCESS, $status);
        self::assertSame(sprintf("wrote \"%s/docs/api.json\"\n", $this->directory), $output);
        self::assertSame(ContractMethods::FIRST, file_get_contents($this->directory . '/docs/api.json'));
    }

    public function testBuildWritesEveryContractAndCheckFindsThemEqual(): void
    {
        $this->compose(['a.json' => 'first', 'b.json' => 'second']);

        [$status, $output, $error] = $this->command(['wp-jsonapi', 'openapi', 'build']);

        self::assertSame(OpenApiCommand::SUCCESS, $status);
        self::assertSame(sprintf("wrote \"%1\$s/a.json\"\nwrote \"%1\$s/b.json\"\n", $this->directory), $output);
        self::assertSame('', $error);
        self::assertSame(ContractMethods::FIRST, file_get_contents($this->directory . '/a.json'));
        self::assertSame(ContractMethods::SECOND, file_get_contents($this->directory . '/b.json'));

        [$status, $output, $error] = $this->command(['wp-jsonapi', 'openapi', 'check']);

        self::assertSame(OpenApiCommand::SUCCESS, $status);
        self::assertSame(sprintf(
            "\"%1\$s/a.json\" matches its contract\n\"%1\$s/b.json\" matches its contract\n",
            $this->directory,
        ), $output);
        self::assertSame('', $error);
    }

    public function testBuildLeavesAFileThatMatches(): void
    {
        $this->compose(['a.json' => 'first']);
        file_put_contents($this->directory . '/a.json', ContractMethods::FIRST);
        touch($this->directory . '/a.json', 1_000_000_000);
        clearstatcache();

        [$status, $output] = $this->command(['wp-jsonapi', 'openapi', 'build']);

        clearstatcache();
        self::assertSame(OpenApiCommand::SUCCESS, $status);
        self::assertSame(sprintf("\"%s/a.json\" is up to date\n", $this->directory), $output);
        self::assertSame(1_000_000_000, filemtime($this->directory . '/a.json'));
    }

    /**
     * `names()` proves that no temporary file is left beside the contract
     */
    public function testBuildReplacesAFileAndKeepsItsPermissions(): void
    {
        $this->compose(['a.json' => 'first']);
        file_put_contents($this->directory . '/a.json', "{\"old\": true, \"longer\": \"than the new one\"}\n");
        chmod($this->directory . '/a.json', 0o640);

        [$status] = $this->command(['wp-jsonapi', 'openapi', 'build']);

        clearstatcache();
        self::assertSame(OpenApiCommand::SUCCESS, $status);
        self::assertSame(ContractMethods::FIRST, file_get_contents($this->directory . '/a.json'));
        self::assertSame(0o640, fileperms($this->directory . '/a.json') & 0o7777);
        self::assertSame(['a.json', 'composer.json'], $this->names());
    }

    public function testBuildCreatesANewFileUnderTheUmask(): void
    {
        $this->compose(['a.json' => 'first']);
        $umask = umask(0o027);

        try {
            [$status] = $this->command(['wp-jsonapi', 'openapi', 'build']);
        } finally {
            umask($umask);
        }

        clearstatcache();
        self::assertSame(OpenApiCommand::SUCCESS, $status);
        self::assertSame(0o640, fileperms($this->directory . '/a.json') & 0o7777);
    }

    public function testBuildWritesThroughASymbolicLink(): void
    {
        mkdir($this->directory . '/real');
        file_put_contents($this->directory . '/real/a.json', "old\n");
        symlink('real/a.json', $this->directory . '/a.json');
        $this->compose(['a.json' => 'first']);

        [$status] = $this->command(['wp-jsonapi', 'openapi', 'build']);

        clearstatcache();
        self::assertSame(OpenApiCommand::SUCCESS, $status);
        self::assertTrue(is_link($this->directory . '/a.json'));
        self::assertSame(ContractMethods::FIRST, file_get_contents($this->directory . '/real/a.json'));

        [$status] = $this->command(['wp-jsonapi', 'openapi', 'check']);

        self::assertSame(OpenApiCommand::SUCCESS, $status);
    }

    public function testALoopOfSymbolicLinksIsTrouble(): void
    {
        symlink('b.json', $this->directory . '/a.json');
        symlink('a.json', $this->directory . '/b.json');
        $this->compose(['a.json' => 'first']);

        [$status, $output, $error] = $this->command(['wp-jsonapi', 'openapi', 'build']);

        self::assertSame(OpenApiCommand::TROUBLE, $status);
        self::assertSame('', $output);
        self::assertSame(sprintf(
            "the contract file \"%s/a.json\" leads through more than 40 symbolic links\n",
            $this->directory,
        ), $error);
    }

    /**
     * `link39` reaches the file through 40 links, `link40` through 41
     */
    public function testAChainOfSymbolicLinksIsFollowedUpToTheLimitOfLinux(): void
    {
        file_put_contents($this->directory . '/a.json', "old\n");
        symlink('a.json', $this->directory . '/link0');

        for ($link = 1; $link <= 40; ++$link) {
            symlink('link' . ($link - 1), $this->directory . '/link' . $link);
        }

        $this->compose(['link39' => 'first']);

        [$status, , $error] = $this->command(['wp-jsonapi', 'openapi', 'build']);

        self::assertSame(OpenApiCommand::SUCCESS, $status, $error);
        self::assertSame(ContractMethods::FIRST, file_get_contents($this->directory . '/a.json'));

        $this->compose(['link40' => 'second']);

        [$status, , $error] = $this->command(['wp-jsonapi', 'openapi', 'build']);

        self::assertSame(OpenApiCommand::TROUBLE, $status);
        self::assertStringContainsString('leads through more than 40 symbolic links', $error);
        self::assertSame(ContractMethods::FIRST, file_get_contents($this->directory . '/a.json'));
    }

    public function testAMissingDirectoryIsTroubleForBuildAndADifferenceForCheck(): void
    {
        $this->compose(['missing/a.json' => 'first']);

        [$status, , $error] = $this->command(['wp-jsonapi', 'openapi', 'build']);

        self::assertSame(OpenApiCommand::TROUBLE, $status);
        self::assertSame(sprintf(
            "the contract file \"%s/missing/a.json\" lies in a directory that does not exist\n",
            $this->directory,
        ), $error);

        [$status, $output, $error] = $this->command(['wp-jsonapi', 'openapi', 'check']);

        self::assertSame(OpenApiCommand::DIFFERENCE, $status);
        self::assertSame(sprintf(
            "\"%s/missing/a.json\" does not exist\nrun wp-jsonapi openapi build to write the contracts\n",
            $this->directory,
        ), $output);
        self::assertSame('', $error);
    }

    public function testADirectoryThatCannotBeWrittenIsTrouble(): void
    {
        mkdir($this->directory . '/locked');
        chmod($this->directory . '/locked', 0o500);
        $this->compose(['locked/a.json' => 'first']);

        if (is_writable($this->directory . '/locked')) {
            self::markTestSkipped('the process writes into a directory without the write permission, as root does');
        }

        [$status, , $error] = $this->command(['wp-jsonapi', 'openapi', 'build']);

        self::assertSame(OpenApiCommand::TROUBLE, $status);
        self::assertStringStartsWith(sprintf(
            'no new file can be created in the directory "%s/locked": fopen(',
            $this->directory,
        ), $error);
        self::assertStringContainsString('Permission denied', $error);
    }

    public function testCheckNamesEveryDifference(): void
    {
        $this->compose([
            'same.json' => 'first',
            'changed.json' => 'second',
            'longer.json' => 'first',
            'missing.json' => 'second',
        ]);
        file_put_contents($this->directory . '/same.json', ContractMethods::FIRST);
        file_put_contents($this->directory . '/changed.json', "{\n    \"a\": 1,\n    \"b\": 3\n}\n");
        file_put_contents($this->directory . '/longer.json', ContractMethods::FIRST . "\n");

        [$status, $output, $error] = $this->command(['wp-jsonapi', 'openapi', 'check']);

        self::assertSame(OpenApiCommand::DIFFERENCE, $status);
        self::assertSame(sprintf(
            "\"%1\$s/same.json\" matches its contract\n"
            . "\"%1\$s/changed.json\" differs from its contract from line 3 on\n"
            . "\"%1\$s/longer.json\" differs from its contract from line 4 on\n"
            . "\"%1\$s/missing.json\" does not exist\n"
            . "run wp-jsonapi openapi build to write the contracts\n",
            $this->directory,
        ), $output);
        self::assertSame('', $error);
    }

    public function testCheckRepeatsOutputInItsHint(): void
    {
        $this->compose('first');

        [$status, $output] = $this->command(['wp-jsonapi', 'openapi', 'check', '--output=docs/api.json']);

        self::assertSame(OpenApiCommand::DIFFERENCE, $status);
        self::assertSame(sprintf(
            "\"%s/docs/api.json\" does not exist\n"
            . "run wp-jsonapi openapi build --output='docs/api.json' to write the contracts\n",
            $this->directory,
        ), $output);
    }

    public function testCheckComparesBytes(): void
    {
        $this->compose(['a.json' => 'first']);
        file_put_contents($this->directory . '/a.json', str_replace("\n", "\r\n", ContractMethods::FIRST));

        [$status, $output] = $this->command(['wp-jsonapi', 'openapi', 'check']);

        self::assertSame(OpenApiCommand::DIFFERENCE, $status);
        self::assertStringContainsString('differs from its contract from line 1 on', $output);
    }

    public function testADirectoryInThePlaceOfAFileIsTrouble(): void
    {
        mkdir($this->directory . '/a.json');
        $this->compose(['a.json' => 'first']);

        foreach (['build', 'check'] as $action) {
            [$status, $output, $error] = $this->command(['wp-jsonapi', 'openapi', $action]);

            self::assertSame(OpenApiCommand::TROUBLE, $status);
            self::assertSame('', $output);
            self::assertSame(sprintf("the contract file \"%s/a.json\" is a directory\n", $this->directory), $error);
        }
    }

    public function testAFileThatCannotBeReadIsTrouble(): void
    {
        $this->compose(['a.json' => 'first']);
        file_put_contents($this->directory . '/a.json', ContractMethods::FIRST);
        chmod($this->directory . '/a.json', 0o200);

        if (is_readable($this->directory . '/a.json')) {
            self::markTestSkipped('the process reads a file without the read permission, as root does');
        }

        [$status, $output, $error] = $this->command(['wp-jsonapi', 'openapi', 'check']);

        self::assertSame(OpenApiCommand::TROUBLE, $status);
        self::assertSame('', $output);
        self::assertStringStartsWith(
            sprintf('the contract file "%s/a.json" cannot be read: ', $this->directory),
            $error,
        );
    }

    public function testAContractThatFailsWritesNothing(): void
    {
        $this->compose(['a.json' => 'first', 'b.json' => 'failing']);

        [$status, $output, $error] = $this->command(['wp-jsonapi', 'openapi', 'build']);

        self::assertSame(OpenApiCommand::TROUBLE, $status);
        self::assertSame('', $output);
        self::assertSame(sprintf(
            "the contract of \"%s/b.json\" failed with UnexpectedValueException: no declaration\n",
            $this->directory,
        ), $error);
        self::assertFileDoesNotExist($this->directory . '/a.json');
    }

    public function testAContractThatIsNoStringIsTrouble(): void
    {
        $this->compose(['a.json' => 'writesNoString']);

        [$status, , $error] = $this->command(['wp-jsonapi', 'openapi', 'check']);

        self::assertSame(OpenApiCommand::TROUBLE, $status);
        self::assertSame(sprintf(
            "the contract of \"%s/a.json\" is array, and an API writes its contract as JSON text\n",
            $this->directory,
        ), $error);
    }

    /**
     * the binary reads the `composer.json` of the root package, this repository,
     * whichever directory it runs in
     */
    public function testTheBinaryRunsTheCommand(): void
    {
        [$status, $output, $error] = self::process(['--help'], $this->directory);

        self::assertSame(OpenApiCommand::SUCCESS, $status, $error);
        self::assertSame(Arguments::USAGE, $output);

        [$status, $output, $error] = self::process(['openapi'], $this->directory);

        self::assertSame(OpenApiCommand::TROUBLE, $status);
        self::assertSame('', $output);
        self::assertStringStartsWith('the command "openapi" is given without its action', $error);

        [$status, $output, $error] = self::process(['openapi', 'check', '--output=api.json'], $this->directory);

        self::assertSame(OpenApiCommand::TROUBLE, $status);
        self::assertSame('', $output);
        self::assertSame(sprintf(
            "--output names one contract file, and \"%s/composer.json\" maps several APIs to their files in"
            . " extra.wp-jsonapi.api\n",
            dirname(__DIR__, 3),
        ), $error);
    }

    /**
     * fails when `tests/Fixtures/openapi.json` drifts from the fixture API;
     * rewrite it with `bin/wp-jsonapi openapi build`
     */
    public function testTheBinaryChecksTheCommittedContract(): void
    {
        $root = dirname(__DIR__, 3);

        foreach ([$root, $this->directory] as $workingDirectory) {
            [$status, $output, $error] = self::process(['openapi', 'check'], $workingDirectory);

            self::assertSame(OpenApiCommand::SUCCESS, $status, $error);
            self::assertSame(sprintf("\"%s/tests/Fixtures/openapi.json\" matches its contract\n", $root), $output);
        }
    }

    /**
     * runs the command in this process,
     * with the directory of the test as both the project and the working directory
     *
     * @param list<string> $argv
     *
     * @return array{int, string, string} the exit status, standard output and standard error
     */
    private function command(array $argv): array
    {
        $stdout = self::memory();
        $stderr = self::memory();
        $status = (new OpenApiCommand($stdout, $stderr, $this->directory, $this->directory))->run($argv);

        return [$status, self::contents($stdout), self::contents($stderr)];
    }

    /**
     * writes `composer.json` with `extra.wp-jsonapi.api`,
     * one method of `ContractMethods` or the path of each contract file mapped to one
     *
     * @param string|array<string, string> $api
     */
    private function compose(string|array $api): void
    {
        $method = static function (string $name): string {
            return ContractMethods::class . '::' . $name;
        };

        file_put_contents($this->directory . '/composer.json', json_encode(
            ['extra' => ['wp-jsonapi' => ['api' => is_string($api) ? $method($api) : array_map($method, $api)]]],
            JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        $names = array_values(array_diff(scandir($this->directory) ?: [], ['.', '..']));
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * runs `bin/wp-jsonapi` in a process of its own, with the PHP of this one
     *
     * @param list<string> $arguments
     *
     * @return array{int, string, string} the exit status, standard output and standard error
     */
    private static function process(array $arguments, string $directory): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/bin/wp-jsonapi', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $directory,
        );

        if (!is_resource($process)) {
            throw new LogicException('the binary cannot be started');
        }

        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $error];
    }

    /**
     * @return resource
     */
    private static function memory()
    {
        $stream = fopen('php://memory', 'w+');

        if ($stream === false) {
            throw new LogicException('php://memory cannot be opened');
        }

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private static function contents($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
