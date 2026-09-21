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

namespace Polunich\WpJsonApi\Access;

use OutOfBoundsException;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ForbiddenException;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\ImplementationResolver;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Operation\OperationKind;

/**
 * the gate every attempted operation passes:
 * the declaration must enable its kind, and the permission bound to that kind must grant it.
 *
 * the declaration read is the one of `$attempt->type`,
 * which for a kind that targets a relationship is the parent:
 * one permission decides for every relationship of the parent; the related type decides nothing.
 *
 * before any permission is asked, 403 refuses:
 *
 * - a kind without a permission, `operation_not_supported`: a declaration binds a permission
 *   to exactly the kinds it enables, so that kind is not enabled;
 * - a relationship write the relationship does not declare, `relationship_update_not_supported`,
 *   and a relationship read the relationship excludes, `relationship_fetch_not_supported`:
 *   permissions are keyed by kind, not by relationship, so the permission map cannot decide this.
 *   the codes differ since the kind is enabled on another relationship,
 *   and their detail has to name this one.
 *
 * 403 is the status the spec gives an unsupported create or update.
 * no refusal names a source: the relationship comes from the route,
 * which is no document member, query parameter or header.
 * the refusals live here and not in the callers, so no caller can reach an undeclared permission.
 *
 * `checkEndpoint()` asks only the permission an endpoint declares: an endpoint performs no kind
 *
 * @internal
 */
final readonly class Gate
{
    public function __construct(
        private ResourceRegistry $resources,
        private ImplementationResolver $implementations,
        private DenialPolicy $denials,
    ) {}

    /**
     * @param list<SecurityScheme> $schemes those of the requested operation, which a 401 challenges
     *
     * @throws JsonApiException     403 for an attempt the declaration refuses,
     *                              or what `DenialPolicy::deny()` builds on a denial
     * @throws OutOfBoundsException when the type is not declared, a caller bug and no refusal
     */
    public function check(OperationAttempt $attempt, array $schemes): void
    {
        $resource = $this->resources->get($attempt->type);
        $reference = $resource->permissions[$attempt->kind->value] ?? null;

        if ($reference === null) {
            throw new ForbiddenException([new ErrorDescription(
                ErrorCode::OperationNotSupported,
                ['type' => $attempt->type, 'operation' => $attempt->kind->value],
            )]);
        }

        // `OperationAttempt` requires a name for a relationship kind; `?? ''` only narrows the type
        $name = $attempt->relationship ?? '';

        if ($attempt->kind->targetsRelationship() && !self::offers($resource, $name, $attempt->kind)) {
            throw new ForbiddenException([new ErrorDescription(
                $attempt->kind->isFetch()
                    ? ErrorCode::RelationshipFetchNotSupported
                    : ErrorCode::RelationshipUpdateNotSupported,
                ['relationship' => $name, 'type' => $attempt->type, 'operation' => $attempt->kind->value],
            )]);
        }

        if (!$this->implementations->resolve($reference, OperationPermission::class)->allows($attempt)) {
            throw $this->denials->deny($attempt->request, $schemes);
        }
    }

    /**
     * @param EndpointAttempt      $attempt level 1 without records, level 2 with the bound records
     * @param list<SecurityScheme> $schemes those of the endpoint, which a 401 challenges
     *
     * @throws JsonApiException what `DenialPolicy::deny()` builds when the permission denies it
     */
    public function checkEndpoint(EndpointMetadata $endpoint, EndpointAttempt $attempt, array $schemes): void
    {
        if (!$this->implementations->resolve($endpoint->permission, EndpointPermission::class)->allows($attempt)) {
            throw $this->denials->deny($attempt->request, $schemes);
        }
    }

    private static function offers(ResourceMetadata $resource, string $name, OperationKind $kind): bool
    {
        $relationship = $resource->relationships[$name] ?? null;

        return $relationship !== null && $relationship->enables($kind);
    }
}
