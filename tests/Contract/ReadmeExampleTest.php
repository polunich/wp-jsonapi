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

namespace Polunich\WpJsonApi\Tests\Contract;

use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\ApiBuilder;
use Polunich\WpJsonApi\Tests\Fixtures\OpenApi\OfficialSchema;
use Polunich\WpJsonApi\Tests\Fixtures\TemporaryDirectory;

/**
 * the first example of `README.md` runs as written: its declaration, records, stores and visitor,
 * then `PetstoreApi::define()`, which instantiates the stores.
 * the registration is left out, since it calls WordPress.
 * the classes come before the call, since PHP declares a class that implements an interface
 * only when the file reaches it
 *
 * @internal
 */
#[CoversNothing]
final class ReadmeExampleTest extends TestCase
{
    private const README = __DIR__ . '/../../README.md';

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

    public function testTheFirstExampleBuildsAValidContract(): void
    {
        $readme = (string) file_get_contents(self::README);

        if (preg_match('~\n## a first API\n(.*?)\n## ~s', $readme, $section) !== 1) {
            self::fail('README.md has no first example');
        }

        preg_match_all('~```php\n(.*?)\n```~s', $section[1], $blocks);
        $classes = array_values(array_filter($blocks[1], static function (string $block): bool {
            return str_starts_with($block, 'namespace ');
        }));

        self::assertNotSame(
            [],
            array_filter($classes, static function (string $block): bool {
                return str_contains($block, 'final class PetstoreApi');
            }),
            'the first example of README.md declares no PetstoreApi',
        );

        $file = $this->directory . '/example.php';
        file_put_contents(
            $file,
            "<?php\n\n" . implode("\n\n", $classes) . "\n\nreturn \\Acme\\Petstore\\PetstoreApi::define();\n",
        );

        $api = (static function (string $example): mixed {
            return require $example;
        })($file);

        self::assertInstanceOf(ApiBuilder::class, $api);

        $errors = OfficialSchema::errors($api->build()->openApiJson());

        self::assertNull($errors, (string) $errors);
    }
}
