# Changelog — ausus/kernel

All notable changes documented per [Keep a Changelog](https://keepachangelog.com/).
Versioning follows [SemVer](https://semver.org/).

## [0.2.0-alpha.4] — 2026-05-27

### Release engineering
- **No runtime, API, or wire change.** This release is a coordinated
  DX/release-engineering hotfix across the AUSUS package set. The kernel
  itself ships zero code changes vs `v0.2.0-alpha.3`; the version bump
  exists to keep all `ausus/*` packages tag-aligned per the documented
  alignment policy.
- **CI validation strictness.** `scripts/ci.sh` and the new
  `scripts/release-gate.sh` (repo-level) now invoke
  `composer validate --no-check-publish --no-check-lock --strict` against
  the kernel manifest. The `--no-check-version` flag (removed in
  Composer 2.x) is dropped. Warnings are now build failures, which is the
  correct CI posture for a published package manifest.
- **Subtree release hardening.** The kernel ships as part of the
  coordinated `scripts/release-publish.sh` flow, which now enforces:
  - HEAD on `main`, clean working tree, synced with `origin/main`
  - All 10 `rel-*` remotes pre-checked for reachability
  - Idempotent re-run via tag-collision detection + SHA verification
  - Cleanup trap restoring the original branch
  - No `git push --force` on tags (immutable contract)
- **Tag protection ruleset** (HIGH-12) now blocks any `v*.*.*` tag push
  that has not passed the `release-gate / gate` workflow. The kernel's
  tag for `v0.2.0-alpha.4` was the first to land under this rule.

## [Unreleased] — v0.1.x stabilisation

### Documentation
- **API stability sweep (annotation only, no runtime change).**
  Package-private symbols that PHP forces to be `public`
  (`Dsl::_register*`, `Dsl::emit()`, `EntityBuilder::finalize()`,
  `ActionBuilder::build()`) now carry `@internal` PHPDoc tags marking
  them as not part of the consumer surface. `ActionNode::$effectClass`
  carries a public-contract docblock spelling out the two valid string
  shapes (`BuiltinEffect` sentinel vs custom-Effect FQN with no-arg
  constructor) and the dispatcher's disambiguation order. The three
  reserved exception classes `ActorRequired`, `TenantContextRequired`,
  and `WorkflowGuardDenied` — declared but never raised by any v0.1.x
  runtime path — carry `@internal` docblocks explaining their reserved
  status.

### Added
- **`Ausus\BuiltinEffect`** string-backed enum naming the three sentinel
  `ActionNode::effectClass` values (`Create`, `Transition`, `Update`).
  The underlying string values are stable wire metadata.
- **`Action::update(string ...$fieldNames)`** facade and
  `ActionBuilder::update()` builder for the third action kind defined
  by ADR-0002 (partial-PATCH). Compile-time validation refuses unknown
  fields, system fields, and the workflow state field.
- **`Repository::findAll(): list<Entity>`** added to the persistence
  contract so the projection renderer no longer reads the driver's
  private PDO via reflection. Custom `Repository` implementations must
  add this method.
- **`Ausus\InvocationResult`** typed wrapper around the loose
  `Invoker::invoke()` array return — carries the action FQN, the
  post-action `Reference`, and the raw outputs.
- **`FieldBuilder::label(string)`** for renderer-friendly column /
  form-field labels (strictly cosmetic — the field `name` remains the
  source of truth).
- **`EntityBuilder::workflow(field:, initial:)`** — explicit workflow
  declaration. The implicit "first enum with default wins" inference
  becomes a deprecated fallback emitting `E_USER_DEPRECATED`; an
  entity with two defaulted enum fields and no `->workflow()` call now
  fails fast with `AmbiguousWorkflowField`.
- **`ActionBuilder::addTransition()`** as the canonical chained
  multi-source-transition builder; `andTransition()` continues to work
  and is now PHPDoc-deprecated.
- **`Compiler` initial-state coherence check** — rejects a
  `WorkflowNode` whose `initial` is not among its `states`.
- **`FieldNode`** gains an optional `?string $label` constructor
  parameter at the end of the positional list (every existing
  positional caller, including the manual `HelloInvoicePlugin`, keeps
  compiling).

### Fixed
- Various PHPDoc clarifications on `ActionNode::effectClass` overload
  semantics and on the `Reference` vs `Subject` distinction.

### Notes
- No public-class-shape regression: the `HelloInvoice` DSL plugin and
  the hand-built descriptor plugin still compile to byte-identical
  `MetadataGraph` hashes (asserted by playground test 10).

## [0.1.0] — 2026-05-19

First public release. L0 of the AUSUS architecture: contracts, value
objects, and the DSL facade. **No runtime dependencies** beyond PHP 8.3+.

### Added
- **Compiler.** Deterministic `MetadataGraph` synthesis from a list of
  `Plugin` instances. Graph hash is SHA-256 over the FQN-sorted membership
  set (RFC-001 §6.4). Byte-identical hash across manual and DSL plugins.
- **Value objects** (all `final readonly`): `TenantId`, `Tenant`,
  `ActorRef`, `Reference`, `Subject`, `Decision`, `Version`, `Instant`.
- **Identity.** `Ulid` generator — Crockford base32, 26 chars, 80 bits of
  randomness, monotonic within process (RFC-001 §6.5).
- **Plugin contract.** `Plugin` interface; descriptor-array shape for
  Fields, Actions, Policies, Workflows, Transitions, Projections, Entities.
- **DSL facade.** `Dsl`, `DslPlugin`, `EntityBuilder`, `FieldBuilder`,
  `ActionBuilder`, plus `Field`/`Action` static facades. Produces graphs
  byte-identically equivalent to manual descriptor-array plugins per
  RFC-011 §11.
- **Graph nodes.** `FieldNode`, `ActionNode`, `PolicyNode`, `WorkflowNode`,
  `TransitionNode`, `ProjectionNode`, `EntityNode`, `MetadataGraph`.
- **Contracts.** `Actor`, `Policy`, `Effect`, `EffectContext`,
  `Repository`, `PersistenceDriver`, `AuditSink`, `Auditor`.
- **Exception taxonomy.** `TenantBoundaryViolation`, `PolicyDeniedException`,
  `WorkflowStateMismatch`, `ConcurrencyConflict`, `EffectFailure`,
  `MalformedDescriptor`.

### Tested
- 36 PHP assertions in upstream `apps/playground/run.php`, including
  DSL byte-identical hash equality, ULID monotonicity, exception
  taxonomy completeness.

### License
MIT — see `LICENSE`.

[0.1.0]: https://github.com/ausus-framework/ausus/releases/tag/kernel-v0.1.0
