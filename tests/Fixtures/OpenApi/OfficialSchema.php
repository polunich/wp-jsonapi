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

namespace Polunich\WpJsonApi\Tests\Fixtures\OpenApi;

use LogicException;
use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use stdClass;

/**
 * validates a document against the iteration of the official OpenAPI 3.1 JSON Schema in `FILE`,
 * committed beside this class with the Apache-2.0 license its redistribution requires.
 *
 * - `CompliantValidator`, not `Validator`: the plain one writes schema defaults into the document
 *   and then refuses them through `unevaluatedProperties`
 * - `format` stays an annotation, as JSON Schema 2020-12 has it by default
 * - `"$dynamicRef": "#meta"` is rewritten to `$defs/schema`, which admits any object or boolean:
 *   the validators resolve it to the root OpenAPI Object and refuse every inline Schema Object
 *
 * @internal
 */
final class OfficialSchema
{
    public const FILE = __DIR__ . '/schema-2026-08-03.json';

    private function __construct() {}

    /**
     * null where the document is valid
     */
    public static function errors(string|stdClass $document): ?string
    {
        $validator = new CompliantValidator();
        $validator->parser()->setOption('allowFormats', false);

        $result = $validator->validate(
            is_string($document) ? json_decode($document, false, 512, JSON_THROW_ON_ERROR) : $document,
            self::schema(),
        );
        $error = $result->error();

        return $error === null
            ? null
            : (string) json_encode(
                (new ErrorFormatter())->format($error, true),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            );
    }

    private static function schema(): stdClass
    {
        $schema = self::withoutDynamicReferences(json_decode(
            (string) file_get_contents(self::FILE),
            false,
            512,
            JSON_THROW_ON_ERROR,
        ));

        if (!$schema instanceof stdClass) {
            throw new LogicException(sprintf('the schema %s is no JSON object', self::FILE));
        }

        return $schema;
    }

    private static function withoutDynamicReferences(mixed $schema): mixed
    {
        if (is_array($schema)) {
            return array_map(static function (mixed $member): mixed {
                return self::withoutDynamicReferences($member);
            }, $schema);
        }

        if (!$schema instanceof stdClass) {
            return $schema;
        }

        $members = [];

        foreach (get_object_vars($schema) as $name => $value) {
            if ($name === '$dynamicRef' && $value === '#meta') {
                $members['$ref'] = '#/$defs/schema';

                continue;
            }

            $members[$name] = self::withoutDynamicReferences($value);
        }

        return (object) $members;
    }
}
