# Changelog — ausus/kernel

All notable changes documented per [Keep a Changelog](https://keepachangelog.com/).
Versioning follows [SemVer](https://semver.org/).

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
