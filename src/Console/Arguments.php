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

namespace Polunich\WpJsonApi\Console;

use InvalidArgumentException;

/**
 * reads `openapi build|check [--output=<path>]` and `--help`
 * by the GNU and POSIX conventions for program arguments:
 *
 * - `--output` takes its path only as `--output=<path>`;
 *   an empty path or a second `--output` is refused, since the option names one file;
 * - options may stand before or after the operands, `--` ends them, and `-` is an operand;
 * - `--help` before `--` wins over whatever else the line holds
 *
 * @internal
 */
final readonly class Arguments
{
    public const COMMAND = 'openapi';

    public const BUILD = 'build';

    public const CHECK = 'check';

    private const OUTPUT = '--output=';

    private const HELP = '--help';

    private const END_OF_OPTIONS = '--';

    public const USAGE = <<<'USAGE'
        usage: wp-jsonapi openapi build [--output=<path>]
               wp-jsonapi openapi check [--output=<path>]
               wp-jsonapi --help

        build writes the contract of every API the composer.json of the project names under
        extra.wp-jsonapi.api, and check compares each contract with its file byte for byte.
        one API, written "Vendor\\Package\\Api::define", goes to openapi.json beside composer.json,
        or to the file --output names; an object that maps paths to methods names several

        exit status: 0 when every contract was written or matches its file, 1 when check finds a
        contract that differs from its file, 2 when the command line, composer.json or a file is in
        the way

        USAGE;

    /**
     * @param self::BUILD|self::CHECK|null $action null with `--help`
     * @param ?non-empty-string            $output the unresolved `--output` path
     */
    private function __construct(
        public bool $help,
        public ?string $action,
        public ?string $output,
    ) {}

    /**
     * @param list<string> $argv the arguments of the process, the script name first
     *
     * @throws InvalidArgumentException when the line is no valid command
     */
    public static function parse(array $argv): self
    {
        $arguments = array_slice($argv, 1);
        $end = array_search(self::END_OF_OPTIONS, $arguments, true);
        $options = $end === false ? $arguments : array_slice($arguments, 0, $end);

        if (in_array(self::HELP, $options, true)) {
            return new self(true, null, null);
        }

        $operands = [];
        $output = null;

        foreach ($options as $argument) {
            if ($argument === '-' || !str_starts_with($argument, '-')) {
                $operands[] = $argument;

                continue;
            }

            if (!str_starts_with($argument, self::OUTPUT)) {
                throw new InvalidArgumentException(sprintf(
                    'the option "%s" is none the command line knows',
                    Configuration::printable($argument),
                ));
            }

            $path = substr($argument, strlen(self::OUTPUT));

            if ($path === '') {
                throw new InvalidArgumentException('--output names no file: write --output=<path>');
            }

            if ($output !== null) {
                throw new InvalidArgumentException(sprintf(
                    '--output is given twice, as "%s" and as "%s", and names one file',
                    Configuration::printable($output),
                    Configuration::printable($path),
                ));
            }

            $output = $path;
        }

        if ($end !== false) {
            $operands = [...$operands, ...array_slice($arguments, $end + 1)];
        }

        if ($operands === []) {
            throw new InvalidArgumentException('no command is given');
        }

        if ($operands[0] !== self::COMMAND) {
            throw new InvalidArgumentException(sprintf(
                'the command "%s" is none the command line knows, which knows "%s" alone',
                Configuration::printable($operands[0]),
                self::COMMAND,
            ));
        }

        if (count($operands) === 1) {
            throw new InvalidArgumentException(sprintf(
                'the command "%s" is given without its action, "%s" or "%s"',
                self::COMMAND,
                self::BUILD,
                self::CHECK,
            ));
        }

        $action = $operands[1];

        if ($action !== self::BUILD && $action !== self::CHECK) {
            throw new InvalidArgumentException(sprintf(
                'the action "%s" is none of "%s" and "%s"',
                Configuration::printable($action),
                self::BUILD,
                self::CHECK,
            ));
        }

        if (count($operands) > 2) {
            throw new InvalidArgumentException(sprintf(
                'the argument "%s" follows the action, and the command line takes none there',
                Configuration::printable($operands[2]),
            ));
        }

        return new self(false, $action, $output);
    }
}
