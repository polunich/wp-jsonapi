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

namespace Polunich\WpJsonApi\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;
use Polunich\WpJsonApi\Api;
use Polunich\WpJsonApi\ApiBuilder;
use Polunich\WpJsonApi\Declaration\AtomicRoute;
use Polunich\WpJsonApi\Declaration\Attribute;
use Polunich\WpJsonApi\Declaration\BodyShape;
use Polunich\WpJsonApi\Declaration\Endpoint;
use Polunich\WpJsonApi\Declaration\Relationship;
use Polunich\WpJsonApi\Declaration\ResourceType;
use Polunich\WpJsonApi\Declaration\ToMany;
use Polunich\WpJsonApi\Declaration\ToOne;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Execution\Atomic\LocalIds;
use Polunich\WpJsonApi\Execution\IncludeWalk;
use Polunich\WpJsonApi\Http\Content;
use Polunich\WpJsonApi\JsonApi;

/**
 * built-in PHP classes are allowed through `phpat.ignore_built_in_classes`, not the target lists
 *
 * @internal
 */
final class ModuleDependencyRules
{
    private const ROOT = 'Polunich\WpJsonApi';

    /**
     * core module => the core modules it may depend on besides itself;
     * `Server` may depend on every core module, which the rule takes from the keys
     *
     * @var array<string, list<string>>
     */
    private const CORE = [
        'Text' => [],
        'Codec' => ['Text'],
        'Http' => ['Text'],
        'Routing' => ['Codec', 'Text'],
        'Localization' => ['Http', 'Text'],
        'Document' => ['Text'],
        'Authentication' => ['Http', 'Text'],
        'Error' => ['Document', 'Authentication', 'Http', 'Codec', 'Text'],
        'Query' => ['Codec', 'Localization', 'Document', 'Text'],
        'Operation' => ['Query', 'Localization', 'Http', 'Routing', 'Text'],
        'Metadata' => ['Codec', 'Query', 'Authentication', 'Operation', 'Http', 'Routing', 'Document', 'Text'],
        'Store' => ['Operation', 'Query', 'Localization', 'Document', 'Text'],
        'Endpoint' => ['Http', 'Localization', 'Operation', 'Store', 'Text'],
        'Declaration' => ['Metadata', 'Codec', 'Query', 'Authentication', 'Operation', 'Routing', 'Http', 'Text'],
        'Parsing' => [
            'Http',
            'Localization',
            'Error',
            'Query',
            'Codec',
            'Metadata',
            'Operation',
            'Endpoint',
            'Routing',
            'Document',
            'Text',
        ],
        'Access' => ['Http', 'Authentication', 'Error', 'Metadata', 'Operation', 'Text'],
        'Execution' => [
            'Operation',
            'Store',
            'Metadata',
            'Query',
            'Error',
            'Http',
            'Access',
            'Authentication',
            'Localization',
            'Endpoint',
            'Text',
        ],
        'Rendering' => ['Document', 'Http', 'Metadata', 'Execution', 'Error', 'Query', 'Routing', 'Text'],
        'OpenApi' => ['Metadata', 'Query', 'Authentication', 'Http', 'Operation', 'Document', 'Routing', 'Text'],
        'Server' => [],
    ];

    /**
     * @var list<string>
     */
    private const ROOT_CLASSES = [
        JsonApi::class,
        ApiBuilder::class,
        Api::class,
    ];

    /**
     * @var list<string>
     */
    private const DRIVERS = ['WordPress', 'Console', 'Testing'];

    /**
     * @var list<string>
     */
    private const VALUE_MODULES = [
        'Text',
        'Codec',
        'Http',
        'Routing',
        'Localization',
        'Document',
        'Authentication',
        'Query',
        'Operation',
        'Metadata',
        'Store',
        'Endpoint',
        'Declaration',
    ];

    /**
     * @var list<string>
     */
    private const SERVICE_MODULES = ['Error', 'Parsing', 'Access', 'Execution', 'Rendering', 'OpenApi', 'Server'];

    /**
     * the classes of a readonly module that are mutable on purpose,
     * named one by one so that a second mutable class is still reported
     *
     * @var array<string, list<class-string>>
     */
    private const MUTABLE = [
        'Http' => [Content::class],
        'Declaration' => [
            ResourceType::class,
            Endpoint::class,
            AtomicRoute::class,
            Attribute::class,
            Relationship::class,
            ToOne::class,
            ToMany::class,
            BodyShape::class,
        ],
        'Error' => [ErrorList::class],
        'Execution' => [LocalIds::class, IncludeWalk::class],
    ];

    /**
     * @return iterable<string, Rule>
     */
    #[TestRule]
    public function coreModulesDependOnlyOnAllowedModules(): iterable
    {
        foreach (self::CORE as $module => $allowed) {
            if ($module === 'Server') {
                $allowed = array_keys(self::CORE);
            }

            $targets = [Selector::inNamespace(self::ROOT . '\\' . $module)];
            foreach ($allowed as $name) {
                $targets[] = Selector::inNamespace(self::ROOT . '\\' . $name);
            }
            if ($module === 'Execution') {
                $targets[] = Selector::inNamespace('Psr\Container');
            }
            if ($module === 'Server') {
                $targets[] = Selector::inNamespace('Psr\Log');
            }

            yield $module => PHPat::rule()
                ->classes(Selector::inNamespace(self::ROOT . '\\' . $module))
                ->canOnlyDependOn()
                ->classes(...$targets);
        }
    }

    /**
     * a WordPress class has no namespace, so the rule matches the `WP_` prefix and `wpdb` by name.
     * it repeats what the target lists above already forbid,
     * so it still holds when one of them grows
     *
     * @return iterable<string, Rule>
     */
    #[TestRule]
    public function coreModulesDependOnNoWordPressClass(): iterable
    {
        foreach (array_keys(self::CORE) as $module) {
            yield $module => PHPat::rule()
                ->classes(Selector::inNamespace(self::ROOT . '\\' . $module))
                ->shouldNotDependOn()
                ->classes(Selector::classname('/^WP_/', true), Selector::classname('/^wpdb$/', true));
        }
    }

    /**
     * @return iterable<string, Rule>
     */
    #[TestRule]
    public function coreModulesDependOnNoDriverAndNoRootClass(): iterable
    {
        $forbidden = [];
        foreach (self::DRIVERS as $driver) {
            $forbidden[] = Selector::inNamespace(self::ROOT . '\\' . $driver);
        }
        foreach (self::ROOT_CLASSES as $class) {
            $forbidden[] = Selector::classname($class);
        }

        foreach (array_keys(self::CORE) as $module) {
            yield $module => PHPat::rule()
                ->classes(Selector::inNamespace(self::ROOT . '\\' . $module))
                ->shouldNotDependOn()
                ->classes(...$forbidden);
        }
    }

    #[TestRule]
    public function wordPressAdapterDependsOnCoreAndWordPress(): Rule
    {
        $targets = [
            Selector::inNamespace(self::ROOT . '\WordPress'),
            Selector::inNamespace('Psr\Log'),
            Selector::classname('/^WP_/', true),
            Selector::classname('/^wpdb$/', true),
        ];
        foreach (array_keys(self::CORE) as $module) {
            $targets[] = Selector::inNamespace(self::ROOT . '\\' . $module);
        }

        return PHPat::rule()
            ->classes(Selector::inNamespace(self::ROOT . '\WordPress'))
            ->canOnlyDependOn()
            ->classes(...$targets);
    }

    #[TestRule]
    public function consoleDependsOnNoOtherModule(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::ROOT . '\Console'))
            ->canOnlyDependOn()
            ->classes(Selector::inNamespace(self::ROOT . '\Console'));
    }

    #[TestRule]
    public function testingDependsOnNoOtherModule(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::ROOT . '\Testing'))
            ->canOnlyDependOn()
            ->classes(
                Selector::inNamespace(self::ROOT . '\Testing'),
                Selector::inNamespace('PHPUnit\Framework'),
                Selector::inNamespace('Opis\JsonSchema'),
            );
    }

    /**
     * a value that crosses a module boundary is immutable
     *
     * @return iterable<string, Rule>
     */
    #[TestRule]
    public function valueModulesAreReadonly(): iterable
    {
        foreach (self::VALUE_MODULES as $module) {
            yield $module => self::readonlyRule($module);
        }
    }

    /**
     * a service of these modules is built once and derives its results from its arguments alone,
     * so it holds no state and is readonly like a value
     *
     * @return iterable<string, Rule>
     */
    #[TestRule]
    public function serviceModulesAreReadonly(): iterable
    {
        foreach (self::SERVICE_MODULES as $module) {
            yield $module => self::readonlyRule($module);
        }
    }

    /**
     * interfaces, enums and traits cannot be readonly, and neither can a throwable,
     * since a readonly class cannot extend `Exception`; `MUTABLE` names the rest
     */
    private static function readonlyRule(string $module): Rule
    {
        $excluded = [Selector::isInterface(), Selector::isEnum(), Selector::isTrait(), Selector::isThrowable()];

        foreach (self::MUTABLE[$module] ?? [] as $class) {
            $excluded[] = Selector::classname($class);
        }

        return PHPat::rule()
            ->classes(Selector::inNamespace(self::ROOT . '\\' . $module))
            ->excluding(...$excluded)
            ->shouldBeReadonly();
    }
}
