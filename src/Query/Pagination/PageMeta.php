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

namespace Polunich\WpJsonApi\Query\Pagination;

use Polunich\WpJsonApi\Query\NamingPolicy;

/**
 * a member of `meta.page`, valued as the cursor pagination profile names it;
 * `nameIn()` spells it under the naming policy of the API
 */
enum PageMeta: string
{
    /**
     * the member of `meta` holding the cases, in a document and in an error alike;
     * the query family `ParameterFamily::Page` only shares its name
     */
    public const MEMBER = 'page';

    case Total = 'total';

    case EstimatedTotal = 'estimatedTotal';

    /** the member of `estimatedTotal` that holds the estimate */
    case BestGuess = 'bestGuess';

    case RangeTruncated = 'rangeTruncated';

    case MaxSize = 'maxSize';

    public function nameIn(NamingPolicy $naming): string
    {
        return $naming->name($this->value);
    }
}
