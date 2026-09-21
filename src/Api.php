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
use JsonException;
use LogicException;
use Polunich\WpJsonApi\Access\EndpointPermission;
use Polunich\WpJsonApi\Access\OperationPermission;
use Polunich\WpJsonApi\Endpoint\Handler;
use Polunich\WpJsonApi\Execution\StoreGateway;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\ImplementationReference;
use Polunich\WpJsonApi\Server\Server;
use Polunich\WpJsonApi\Store\TransactionBoundary;
use Polunich\WpJsonApi\Text\Printable;
use Polunich\WpJsonApi\WordPress\HookRegistrar;
use Polunich\WpJsonApi\WordPress\OutputBuffers;
use Polunich\WpJsonApi\WordPress\RecordedResponses;
use Polunich\WpJsonApi\WordPress\RegisteredCallbacks;
use Polunich\WpJsonApi\WordPress\RequestFactory;
use Polunich\WpJsonApi\WordPress\ResponseFactory;
use Polunich\WpJsonApi\WordPress\ResponseFilter;
use Polunich\WpJsonApi\WordPress\RestController;
use Polunich\WpJsonApi\WordPress\RouteNormalizer;
use Polunich\WpJsonApi\WordPress\RoutePatterns;
use Polunich\WpJsonApi\WordPress\RouteRegistrar;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * a built API: its contract, and the hooks that serve it in WordPress
 */
final class Api
{
    private bool $registered = false;

    /**
     * @internal an API is built by `ApiBuilder::build()`
     *
     * @param ?ContainerInterface $container holds the entry ids of the declaration
     * @param LoggerInterface     $logger    receives a throwable that escapes the library
     */
    public function __construct(
        private readonly ApiMetadata $metadata,
        private readonly Server $server,
        private readonly ?ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws JsonException when a string of the declaration is not valid UTF-8
     */
    public function openApiJson(): string
    {
        return $this->server->openApiJson();
    }

    /**
     * @internal `JsonApi::register()` asks for them on the first `rest_api_init`
     *
     * the implementation references are checked here, not in `ApiBuilder::build()`,
     * so the contract builds without the container that holds them.
     * a factory is not called;
     * what it returns is checked when a request resolves the implementation
     *
     * @throws LogicException           when the API is registered already,
     *                                  or an implementation reference is refused:
     *                                  an entry without a container, an entry the container lacks,
     *                                  or an instance of no port it is bound to
     * @throws InvalidArgumentException when WordPress cannot match the REST namespace or a route,
     *                                  or cannot tell two routes apart
     */
    public function hooks(): HookRegistrar
    {
        if ($this->registered) {
            throw new LogicException(sprintf(
                '%s is registered with WordPress already, and a second registration would register'
                . ' each route and each callback twice',
                $this->subject(),
            ));
        }

        $this->checkImplementationReferences();

        $patterns = new RoutePatterns($this->metadata->restNamespace, $this->server->routes());
        $requests = new RequestFactory();
        $buffers = new OutputBuffers();
        $responses = new ResponseFactory($buffers);
        $recorded = new RecordedResponses();
        $callbacks = new RegisteredCallbacks();

        $hooks = new HookRegistrar(
            new RouteRegistrar(
                $patterns,
                new RestController($this->server, $requests, $responses, $recorded, $this->logger),
                $callbacks,
                /**
                 * @param non-falsy-string        $namespace
                 * @param non-falsy-string        $route
                 * @param array<array-key, mixed> $args
                 */
                static function (string $namespace, string $route, array $args): bool {
                    return register_rest_route($namespace, $route, $args);
                },
            ),
            new RouteNormalizer($patterns),
            new ResponseFilter(
                $this->server,
                $patterns,
                $callbacks,
                $requests,
                $responses,
                $recorded,
                $this->logger,
                static function (int $status): void {
                    http_response_code($status);
                },
                static function (string $piece): void {
                    echo $piece;
                },
                $buffers,
            ),
        );

        $this->registered = true;

        return $hooks;
    }

    /**
     * @throws LogicException when a reference is refused
     */
    private function checkImplementationReferences(): void
    {
        foreach ($this->metadata->resources->all() as $resource) {
            foreach ($resource->capabilities as $capability => $reference) {
                $this->checkReference(
                    $reference,
                    StoreGateway::portOf(Capability::from($capability)),
                    sprintf('the %s of the type "%s"', $capability, $resource->type),
                );
            }

            foreach ($resource->permissions as $kind => $reference) {
                $this->checkReference(
                    $reference,
                    OperationPermission::class,
                    sprintf('the permission of %s on the type "%s"', $kind, $resource->type),
                );
            }
        }

        foreach ($this->metadata->endpoints as $endpoint) {
            $this->checkReference(
                $endpoint->handler,
                Handler::class,
                sprintf('the handler of the endpoint "%s"', $endpoint->name),
            );
            $this->checkReference(
                $endpoint->permission,
                EndpointPermission::class,
                sprintf('the permission of the endpoint "%s"', $endpoint->name),
            );
        }

        if ($this->metadata->transactionBoundary !== null) {
            $this->checkReference(
                $this->metadata->transactionBoundary,
                TransactionBoundary::class,
                'the transaction boundary',
            );
        }
    }

    /**
     * @param class-string $port the interface the implementation is bound as
     * @param string       $role names the implementation in the message
     *
     * @throws LogicException when the reference is refused
     */
    private function checkReference(ImplementationReference $reference, string $port, string $role): void
    {
        if ($reference->entryId !== null) {
            $entry = Printable::of($reference->entryId);

            if ($this->container === null) {
                throw new LogicException(sprintf(
                    '%s of %s is the container entry "%s", and the API was built without a container',
                    $role,
                    $this->subject(),
                    $entry,
                ));
            }

            if (!$this->container->has($reference->entryId)) {
                throw new LogicException(sprintf(
                    '%s of %s is the container entry "%s", which the container does not hold',
                    $role,
                    $this->subject(),
                    $entry,
                ));
            }

            return;
        }

        if ($reference->instance !== null && !$reference->instance instanceof $port) {
            throw new LogicException(sprintf(
                '%s of %s is %s, and an implementation is an instance of the port it is bound to, %s',
                $role,
                $this->subject(),
                get_debug_type($reference->instance),
                $port,
            ));
        }
    }

    private function subject(): string
    {
        return sprintf('the API "%s"', Printable::of($this->metadata->name));
    }
}
