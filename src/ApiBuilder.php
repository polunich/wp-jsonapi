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

namespace Polunich\WpJsonApi;

use InvalidArgumentException;
use Polunich\WpJsonApi\Authentication\ChallengeProvider;
use Polunich\WpJsonApi\Authentication\Identity;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Declaration\AtomicRoute;
use Polunich\WpJsonApi\Declaration\Endpoint;
use Polunich\WpJsonApi\Declaration\Implementation;
use Polunich\WpJsonApi\Declaration\LanguageSource;
use Polunich\WpJsonApi\Declaration\Operator;
use Polunich\WpJsonApi\Declaration\ResourceType;
use Polunich\WpJsonApi\Declaration\WrittenTwice;
use Polunich\WpJsonApi\Error\ErrorCatalog;
use Polunich\WpJsonApi\Error\ExceptionMapper;
use Polunich\WpJsonApi\Execution\ContainerImplementationResolver;
use Polunich\WpJsonApi\Http\Method;
use Polunich\WpJsonApi\Localization\ContentLanguage;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\AtomicRouteMetadata;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\ImplementationReference;
use Polunich\WpJsonApi\Metadata\LanguageSourceMetadata;
use Polunich\WpJsonApi\Metadata\LanguageSources;
use Polunich\WpJsonApi\Metadata\OperatorMetadata;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Query\Filter\Operators;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Server\Server;
use Polunich\WpJsonApi\Text\Printable;
use Polunich\WpJsonApi\WordPress\ApplicationPasswordsChallengeProvider;
use Polunich\WpJsonApi\WordPress\CurrentUserIdentity;
use Polunich\WpJsonApi\WordPress\ErrorLogLogger;
use Polunich\WpJsonApi\WordPress\RestUrlGenerator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * declares an API and composes the library around it.
 *
 * every default is WordPress's: the current user is the identity,
 * Application Passwords give the challenge,
 * `error_log()` is the log, `rest_url()` writes the links.
 * each WordPress function sits in a closure called only when a request needs it,
 * so `build()` and `Api::openApiJson()` run where WordPress is not loaded.
 *
 * the builder is mutable, so a call on a line of its own is not lost.
 * a setting is written once and a second write throws.
 * resources, operators and exception mappers are added one by one; `build()` refuses a duplicate.
 * `resource()`, `operator()`, `languageSources()` and `transactionBoundary()`
 * build their declaration on the spot, so a later change to it does not reach the API.
 * the builders returned by `get()`, `post()`, `put()`, `patch()`, `delete()` and `atomic()`
 * are read by `build()`, once the resource types they name are known
 */
final class ApiBuilder
{
    /** @var list<ResourceMetadata> */
    private array $resources = [];

    /** @var list<OperatorMetadata> */
    private array $operators = [];

    /** @var list<Endpoint> */
    private array $endpoints = [];

    /** @var list<AtomicRoute> */
    private array $atomicRoutes = [];

    /** @var ?list<SecurityScheme> */
    private ?array $securitySchemes = null;

    private ?ImplementationReference $transactionBoundary = null;

    private ?ContainerInterface $container = null;

    private ?LoggerInterface $logger = null;

    private ?ContentLanguage $contentLanguage = null;

    private ?LanguageSources $languageSources = null;

    private ?ErrorCatalog $errorCatalog = null;

    private ?ChallengeProvider $challengeProvider = null;

    private ?string $realm = null;

    private ?Identity $identity = null;

    /** @var list<ExceptionMapper<Throwable>> */
    private array $exceptionMappers = [];

    private ?bool $debugOutput = null;

    private ?NamingPolicy $namingPolicy = null;

    private ?bool $strictQueryParameterNames = null;

    /**
     * @internal a declaration starts with `JsonApi::api()`
     */
    public function __construct(
        private readonly string $name,
        private readonly string $version,
        private readonly string $restNamespace,
    ) {}

    /**
     * @throws InvalidArgumentException when the type declares no id,
     *                                  or its path or metadata is refused
     */
    public function resource(ResourceType $type): self
    {
        $this->resources[] = $type->build();

        return $this;
    }

    public function operator(Operator $operator): self
    {
        $this->operators[] = $operator->build();

        return $this;
    }

    /**
     * an endpoint responding to GET, and HEAD with it, on the path
     *
     * @param string         $path    such as `/orders/export` or `/pets/{pet_id}/photos`
     * @param Implementation $handler binds an `Endpoint\Handler`
     */
    public function get(string $path, Implementation $handler): Endpoint
    {
        return $this->endpoint(Method::GET, $path, $handler);
    }

    /**
     * @param Implementation $handler binds an `Endpoint\Handler`
     */
    public function post(string $path, Implementation $handler): Endpoint
    {
        return $this->endpoint(Method::POST, $path, $handler);
    }

    /**
     * @param Implementation $handler binds an `Endpoint\Handler`
     */
    public function put(string $path, Implementation $handler): Endpoint
    {
        return $this->endpoint(Method::PUT, $path, $handler);
    }

    /**
     * @param Implementation $handler binds an `Endpoint\Handler`
     */
    public function patch(string $path, Implementation $handler): Endpoint
    {
        return $this->endpoint(Method::PATCH, $path, $handler);
    }

    /**
     * @param Implementation $handler binds an `Endpoint\Handler`
     */
    public function delete(string $path, Implementation $handler): Endpoint
    {
        return $this->endpoint(Method::DELETE, $path, $handler);
    }

    /**
     * a route of the Atomic Operations extension responding to POST on the path,
     * which runs its operations inside the transaction of `transactionBoundary()`
     *
     * @param string $path such as `/operations` or `/pets/operations`
     */
    public function atomic(string $path): AtomicRoute
    {
        $route = new AtomicRoute($path);
        $this->atomicRoutes[] = $route;

        return $route;
    }

    /**
     * replaces the default, the two ways WordPress core authenticates:
     * `SecurityScheme::restNonce()` and `SecurityScheme::applicationPasswords()`.
     * an operation may declare its own in place of these
     *
     * @throws InvalidArgumentException when the schemes are written twice
     */
    public function securitySchemes(SecurityScheme ...$schemes): self
    {
        if ($this->securitySchemes !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'its security schemes');
        }

        $this->securitySchemes = array_values($schemes);

        return $this;
    }

    /**
     * the transaction the operations of an atomic request run in,
     * which every route declared with `atomic()` needs
     *
     * @throws InvalidArgumentException when a boundary is written twice
     */
    public function transactionBoundary(Implementation $implementation): self
    {
        if ($this->transactionBoundary !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'a transaction boundary');
        }

        $this->transactionBoundary = $implementation->build();

        return $this;
    }

    /**
     * resolves the implementations declared by entry id
     *
     * @throws InvalidArgumentException when a container is written twice
     */
    public function container(ContainerInterface $container): self
    {
        if ($this->container !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'a container');
        }

        $this->container = $container;

        return $this;
    }

    /**
     * receives the throwable behind a 5xx, in place of `error_log()`
     *
     * @throws InvalidArgumentException when a logger is written twice
     */
    public function logger(LoggerInterface $logger): self
    {
        if ($this->logger !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'a logger');
        }

        $this->logger = $logger;

        return $this;
    }

    /**
     * without it no response carries `Content-Language`
     *
     * @throws InvalidArgumentException when a content language is written twice
     */
    public function contentLanguage(ContentLanguage $contentLanguage): self
    {
        if ($this->contentLanguage !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'a content language');
        }

        $this->contentLanguage = $contentLanguage;

        return $this;
    }

    /**
     * where the language of a request comes from, in order:
     * the first source present in the request decides, and the ones after it are not read.
     * without the call the language comes from `Accept-Language` alone;
     * a call without sources negotiates no language, and `Vary` then names no language field
     *
     * @throws InvalidArgumentException when the sources are written twice, or a source stands twice
     */
    public function languageSources(LanguageSource ...$sources): self
    {
        if ($this->languageSources !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'its language sources');
        }

        $built = array_map(static function (LanguageSource $source): LanguageSourceMetadata {
            return $source->build();
        }, array_values($sources));
        $this->languageSources = new LanguageSources($built);

        return $this;
    }

    /**
     * asked for the title and the detail of an error before the library's own catalog
     *
     * @throws InvalidArgumentException when a catalog is written twice
     */
    public function errorCatalog(ErrorCatalog $errorCatalog): self
    {
        if ($this->errorCatalog !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'an error catalog');
        }

        $this->errorCatalog = $errorCatalog;

        return $this;
    }

    /**
     * replaces the Application Passwords provider of the challenges of a 401
     *
     * @throws InvalidArgumentException when a provider is written twice, or a realm is written:
     *                                  a realm configures the default provider alone
     */
    public function challengeProvider(ChallengeProvider $challengeProvider): self
    {
        if ($this->challengeProvider !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'a challenge provider');
        }

        if ($this->realm !== null) {
            throw new InvalidArgumentException($this->realmAndProvider());
        }

        $this->challengeProvider = $challengeProvider;

        return $this;
    }

    /**
     * the realm of the Basic challenge of the default provider, `WordPress` when none is written
     *
     * @throws InvalidArgumentException when a realm is written twice,
     *                                  or a challenge provider is written:
     *                                  a realm configures the default provider alone
     */
    public function realm(string $realm): self
    {
        if ($this->realm !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'a realm');
        }

        if ($this->challengeProvider !== null) {
            throw new InvalidArgumentException($this->realmAndProvider());
        }

        $this->realm = $realm;

        return $this;
    }

    /**
     * replaces the current user of WordPress
     *
     * @throws InvalidArgumentException when an identity is written twice
     */
    public function identity(Identity $identity): self
    {
        if ($this->identity !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'an identity');
        }

        $this->identity = $identity;

        return $this;
    }

    /**
     * @param ExceptionMapper<Throwable> $mapper
     */
    public function exceptionMapper(ExceptionMapper $mapper): self
    {
        $this->exceptionMappers[] = $mapper;

        return $this;
    }

    /**
     * puts the exception chain of a 500 into its `meta`; for development only
     *
     * @throws InvalidArgumentException when the choice is written twice
     */
    public function debugOutput(bool $enabled): self
    {
        if ($this->debugOutput !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'its debug output');
        }

        $this->debugOutput = $enabled;

        return $this;
    }

    /**
     * `NamingPolicy::SnakeCase` when none is written
     *
     * @throws InvalidArgumentException when a policy is written twice
     */
    public function namingPolicy(NamingPolicy $policy): self
    {
        if ($this->namingPolicy !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'a naming policy');
        }

        $this->namingPolicy = $policy;

        return $this;
    }

    /**
     * whether `build()` refuses, as JSON:API asks, a custom query parameter of a-z alone,
     * such as `locale`: a language parameter, or one an endpoint or a route declares.
     * strict when none is written
     *
     * @throws InvalidArgumentException when the choice is written twice
     */
    public function strictQueryParameterNames(bool $enabled): self
    {
        if ($this->strictQueryParameterNames !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'its rule of query parameter names');
        }

        $this->strictQueryParameterNames = $enabled;

        return $this;
    }

    /**
     * @throws InvalidArgumentException when the declaration is refused by the resource registry,
     *                                  an endpoint, an atomic route, the API metadata,
     *                                  the operator table, the exception mappers or the realm
     */
    public function build(): Api
    {
        $schemes = $this->securitySchemes ?? [SecurityScheme::restNonce(), SecurityScheme::applicationPasswords()];
        $resources = new ResourceRegistry($this->resources);
        $api = new ApiMetadata(
            $this->name,
            $this->version,
            $this->restNamespace,
            $resources,
            array_map(static function (Endpoint $endpoint) use ($resources): EndpointMetadata {
                return $endpoint->build($resources);
            }, $this->endpoints),
            array_map(static function (AtomicRoute $route) use ($resources): AtomicRouteMetadata {
                return $route->build($resources);
            }, $this->atomicRoutes),
            $this->operatorTable(),
            $schemes,
            $this->transactionBoundary,
            $this->languageSources ?? new LanguageSources([LanguageSourceMetadata::acceptLanguage()]),
            $this->namingPolicy ?? NamingPolicy::SnakeCase,
            $this->strictQueryParameterNames ?? true,
        );
        $logger = $this->logger ?? new ErrorLogLogger($this->name);
        $server = Server::compose(
            $api,
            new ContainerImplementationResolver($this->container),
            new RestUrlGenerator($this->restNamespace, static function (string $path): string {
                return rest_url($path);
            }),
            $this->identity ?? self::currentUser(),
            $this->challengeProvider ?? $this->applicationPasswords(),
            $this->contentLanguage,
            $this->errorCatalog,
            $this->exceptionMappers,
            $this->debugOutput ?? false,
            $logger,
        );

        return new Api($api, $server, $this->container, $logger);
    }

    private function endpoint(string $method, string $path, Implementation $handler): Endpoint
    {
        $endpoint = new Endpoint($method, $path, $handler);
        $this->endpoints[] = $endpoint;

        return $endpoint;
    }

    /**
     * @return array<string, OperatorMetadata>
     */
    private function operatorTable(): array
    {
        $table = [];

        foreach (Operators::names() as $name) {
            $table[$name] = OperatorMetadata::builtIn($name);
        }

        foreach ($this->operators as $operator) {
            // a name written twice would collapse into one key, out of the sight of `ApiMetadata`
            if (array_key_exists($operator->name, $table)) {
                throw WrittenTwice::refusal(
                    $this->declaration(),
                    sprintf('the operator "%s"', Printable::of($operator->name)),
                );
            }

            $table[$operator->name] = $operator;
        }

        return $table;
    }

    private static function currentUser(): CurrentUserIdentity
    {
        return new CurrentUserIdentity(static function (): bool {
            return is_user_logged_in();
        });
    }

    /**
     * @throws InvalidArgumentException when no header field can carry the realm
     */
    private function applicationPasswords(): ApplicationPasswordsChallengeProvider
    {
        return new ApplicationPasswordsChallengeProvider(
            $this->realm ?? ApplicationPasswordsChallengeProvider::DEFAULT_REALM,
            static function (): bool {
                return wp_is_application_passwords_available();
            },
            static function (): array {
                return $_SERVER;
            },
        );
    }

    private function declaration(): string
    {
        return sprintf('the API "%s"', Printable::of($this->name));
    }

    private function realmAndProvider(): string
    {
        return sprintf(
            '%s declares a realm and a challenge provider, and a realm configures the challenge of the'
            . ' default provider, which a provider of the project replaces',
            $this->declaration(),
        );
    }
}
