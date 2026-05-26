<?php
declare(strict_types=1);

namespace Ausus\Errors;

// =============================================================================
// MARKER INTERFACES — HTTP CLASSIFICATION (Phase A of typed-exception design)
// =============================================================================
//
// Five empty marker interfaces tagging an exception's intended HTTP response
// status. Concrete kernel exceptions in `Ausus\` and `Ausus\Api\Http\BadRequest`
// implement exactly one marker each (Phase B). The `ErrorMapper` does NOT yet
// dispatch on these markers — that switch is Phase C of the design and is
// explicitly out of scope here. Until Phase C lands the markers are pure type
// metadata: zero runtime behaviour change.
//
// Plugin authors may implement a marker on their own custom exception classes
// today; once Phase C ships, doing so will automatically route the exception
// to the marker's HTTP status. Until then the marker is a no-op stable contract.
//
// All five interfaces are `@public stable` per `docs/VERSIONING.md` —
// additive-only inside v0.x; renaming, removal, or status remapping is a
// major-version-only operation.

/** Routes to HTTP 400 Bad Request once Phase C dispatch lands. */
interface BadRequestError {}

/** Routes to HTTP 403 Forbidden once Phase C dispatch lands. */
interface ForbiddenError {}

/** Routes to HTTP 404 Not Found once Phase C dispatch lands. */
interface NotFoundError {}

/** Routes to HTTP 409 Conflict once Phase C dispatch lands. */
interface ConflictError {}

/** Routes to HTTP 500 Internal Server Error once Phase C dispatch lands. */
interface InternalError {}
