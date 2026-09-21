# changelog

all notable changes to this project are documented in this file, in the format of
[Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/). the project follows semantic
versioning, and a breaking change is a change to the public API that README.md defines under
"versioning": every class, interface, enum and trait of the library without the `@internal` tag.

## [unreleased]

## [1.0.0] - 2026-09-26

### added

- the routes of JSON:API 1.1 for every declared type: the collection, the resource, and the related
  and relationship routes of each relationship.
- content negotiation with the JSON:API media type, the Atomic Operations extension and the Cursor
  Pagination profile.
- filters with nested `@and`, `@or` and `@not` and 21 comparison operators,
  on attributes and on ids; sorts through a to-one relationship;
  pagination by page number, by offset and by cursor; `include` and sparse fieldsets.
- creates, updates and deletions with write rules per field, the three updates of a to-many
  relationship, client ids, `ETag` and `If-Match`, and `202 Accepted`.
- atomic operations on the routes the API declares, each at a path of its own and for the types it
  names.
- the routes of a type at a path it declares with `path()`, such as `/shop/orders`; every link is the
  path of the route it leads to, filled with the values of its parameters.
- custom endpoints beside the routes of the types, responding in JSON:API or in another media type,
  among them a page of a collection: the library reads its `filter`, `sort` and `page` into the query
  the handler reads, and pages the result as on the collection route.
- query parameters a type or a relationship declares for its routes, decoded by a codec and handed to
  the store beside the families of JSON:API.
- every error as a JSON:API error document, a 401 only with a challenge that applies to the request,
  and WordPress's own errors on a path of a library route converted to one.
- security schemes in the contract, by default the two ways WordPress core authenticates, and per
  operation in place of the API's; a 401 is challenged for the schemes of the operation it refuses.
- the contract command line, `vendor/bin/wp-jsonapi openapi build` and `check`, and PHPUnit
  assertions that validate a response against the committed contract.

[unreleased]: https://github.com/polunich/wp-jsonapi/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/polunich/wp-jsonapi/releases/tag/v1.0.0
