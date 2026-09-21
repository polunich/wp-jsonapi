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

namespace Polunich\WpJsonApi\Tests\Unit\WordPress;

use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Tests\Fixtures\WordPress\CoreFiles;
use Polunich\WpJsonApi\Tests\Fixtures\WordPress\RecordingHooks;
use Polunich\WpJsonApi\WordPress\DeferredRegistration;
use Polunich\WpJsonApi\WordPress\HookRegistrar;
use ReflectionClass;
use WP_Hook;

/**
 * @internal
 */
#[CoversClass(DeferredRegistration::class)]
final class DeferredRegistrationTest extends TestCase
{
    /** the pattern of the resource route of the pets, whose ids are digits */
    private const RESOURCE_PATTERN = '/pets/(?P<id>(?=(?:0|[1-9][0-9]*)(?:/|\\z))[^/]+)';

    private RecordingHooks $recording;

    private int $builds = 0;

    #[Override]
    protected function setUp(): void
    {
        CoreFiles::resetHooks();
        $this->recording = new RecordingHooks();
    }

    #[Override]
    protected function tearDown(): void
    {
        CoreFiles::resetHooks();
    }

    public function testTheClassIsFinalInternalAndKeepsWhatItBuilt(): void
    {
        $class = new ReflectionClass(DeferredRegistration::class);

        self::assertTrue($class->isFinal());
        self::assertFalse($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testTheRegistrationAddsOneCallbackAndBuildsNothing(): void
    {
        $this->registration()->register();

        self::assertSame(0, $this->builds);
        self::assertSame(['rest_api_init'], array_keys(self::hooks()));
        self::assertSame(1, self::callbacks('rest_api_init'));
    }

    public function testTheFirstRestApiInitBuildsOnceAndEveryOneRegistersTheRoutes(): void
    {
        $this->registration()->register();

        do_action('rest_api_init');
        do_action('rest_api_init');

        self::assertSame(1, $this->builds);
        self::assertSame(
            ['/pets', self::RESOURCE_PATTERN, '/pets', self::RESOURCE_PATTERN],
            $this->recording->registered,
        );
        self::assertSame(1, self::callbacks('rest_pre_dispatch'));
        self::assertSame(2, self::callbacks('rest_pre_serve_request'));
    }

    public function testABuildThatThrowsIsTriedAgainOnTheNextRestApiInit(): void
    {
        $failures = 1;
        (new DeferredRegistration(function () use (&$failures): HookRegistrar {
            if ($failures-- > 0) {
                throw new LogicException('the declaration is refused');
            }

            return $this->recording->hookRegistrar();
        }))->register();

        try {
            do_action('rest_api_init');
            self::fail('the failed build was swallowed');
        } catch (LogicException $failure) {
            self::assertSame('the declaration is refused', $failure->getMessage());
        }

        self::assertSame([], $this->recording->registered);
        self::assertSame(0, self::callbacks('rest_pre_dispatch'));

        do_action('rest_api_init');

        self::assertSame(['/pets', self::RESOURCE_PATTERN], $this->recording->registered);
        self::assertSame(1, self::callbacks('rest_pre_dispatch'));
    }

    private function registration(): DeferredRegistration
    {
        return new DeferredRegistration(function (): HookRegistrar {
            ++$this->builds;

            return $this->recording->hookRegistrar();
        });
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function hooks(): array
    {
        $hooks = $GLOBALS['wp_filter'] ?? null;

        self::assertIsArray($hooks);

        return $hooks;
    }

    /**
     * how many callbacks the hook holds, at every priority
     */
    private static function callbacks(string $hook): int
    {
        $filter = self::hooks()[$hook] ?? null;

        if ($filter === null) {
            return 0;
        }

        self::assertInstanceOf(WP_Hook::class, $filter);

        return array_sum(array_map('count', $filter->callbacks));
    }
}
