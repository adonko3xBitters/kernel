# ausus/kernel

L0 — contracts only. No implementation logic. No dependencies on any other AUSUS package.

## Owned RFC surfaces

- **RFC-001 (Kernel)** — full, including Amendments-01 and -02.
- **RFC-005 §2 (Policy contract)** — interface, Decision enum, Subject, Context value objects.
- **RFC-013 §2 (Effect contract)** — interface, EffectContext.
- **RFC-014 §2 (Actor contracts)** — Actor, ActorRef, ActorResolver interfaces.

## Public surface

```
Ausus\                                 (facade root — DSL entry points)
  Plugin                               (base class plugins extend)
  Dsl                                  (DSL builder)
  Field                                (Field type fluent builder)
  Action                               (Action fluent builder)
  Policy / Decision / Subject          (re-exported from Ausus\Kernel\Contracts\Policy)
  Effect / EffectContext / Reference   (re-exported from Ausus\Kernel\Contracts\Persistence + Effect)

Ausus\Kernel\Contracts\                (internal contract namespace)
  Persistence\PersistenceDriver, PersistenceContext, Repository, ...
  Policy\Policy, PolicyDescriptor, ...
  Audit\Auditor, AuditEntry, AuditSink, ...
  Authorization\Actor, ActorRef, ActorResolver
  Tenancy\Tenant, TenantId, TenantResolver, TenantIsolationStrategy
  Reporting\ReportingDriver, ReportingQuery
  Workflow\WorkflowDescriptor, TransitionDescriptor
  Effect\Effect, EffectContext
```

## Constraints

- ZERO runtime side effects in any class here.
- ZERO dependencies on `illuminate/database`, `illuminate/http`, etc. — only `illuminate/contracts` is permitted.
- No abstract base classes that subclass-and-override behavior. Value objects and contracts only.
- Every public symbol corresponds to a clause in a frozen RFC.
