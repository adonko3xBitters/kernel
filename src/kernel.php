<?php
declare(strict_types=1);

namespace Ausus;

// =============================================================================
// VALUE OBJECTS  (Reference, TenantId, Tenant, ActorRef, Subject, Decision)
// =============================================================================

final readonly class TenantId {
    public function __construct(public string $value) {}
}

final readonly class Tenant {
    public function __construct(public TenantId $id) {}
    public function value(): string { return $this->id->value; }
}

final readonly class ActorRef {
    public function __construct(
        public string $type,        // 'user' | 'system' | 'service'
        public string $id,
        public string $homeTenant,
    ) {}
}

final readonly class Reference {
    public function __construct(
        public string $tenantId,
        public string $entityFqn,
        public string $identityHandle,
    ) {}
}

final readonly class Subject {
    public function __construct(
        public string $tenantId,
        public string $entityFqn,
        public string $identityHandle,
    ) {}
    public static function fromReference(Reference $r): self {
        return new self($r->tenantId, $r->entityFqn, $r->identityHandle);
    }
}

enum Decision: string {
    case Permit = 'permit';
    case Deny = 'deny';
    case Abstain = 'abstain';
}

final readonly class Version {
    public function __construct(public string $value) {}
}

final readonly class Instant {
    public function __construct(public float $epochSeconds) {}
    public function toRfc3339(): string {
        $secs = (int) $this->epochSeconds;
        $micros = (int) round(($this->epochSeconds - $secs) * 1_000_000);
        return gmdate('Y-m-d\\TH:i:s', $secs) . sprintf('.%06dZ', $micros);
    }
}

// =============================================================================
// ACTOR + CONTEXT
// =============================================================================

interface Actor {
    public function ref(): ActorRef;
    public function roleHash(): string;
    /** @return string[] */ public function roles(): array;
    /** @return string[] */ public function permissions(): array;
}

final class StubActor implements Actor {
    public function __construct(
        private readonly ActorRef $ref,
        /** @var string[] */ private readonly array $roles,
        /** @var string[] */ private readonly array $permissions = [],
    ) {}
    public function ref(): ActorRef { return $this->ref; }
    public function roles(): array { return $this->roles; }
    public function permissions(): array { return $this->permissions; }
    public function roleHash(): string {
        // RFC-014 §3 canonical hash
        $payload = json_encode([
            'permissions' => $this->sortUnique($this->permissions),
            'roles'       => $this->sortUnique($this->roles),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $payload);
    }
    private function sortUnique(array $a): array { $a = array_values(array_unique($a)); sort($a, SORT_STRING); return $a; }
}

final readonly class Context {
    public function __construct(
        public Tenant $tenant,
        public string $correlationId,
        public ?string $traceId,
        public Instant $clock,
    ) {}
}

// =============================================================================
// POLICY + EFFECT CONTRACTS
// =============================================================================

interface Policy {
    public function evaluate(Actor $actor, string $actionFqn, ?Subject $subject, Context $context): Decision;
}

interface EffectContext {
    public function repository(string $entityFqn): Repository;
    public function actor(): Actor;
    public function tenant(): Tenant;
    public function correlationId(): string;
    public function traceId(): ?string;
    public function clock(): Instant;
}

interface Effect {
    /** @param array<string,mixed> $inputs @return array<string,mixed> */
    public function execute(EffectContext $context, ?Reference $subject, array $inputs): array;
}

/**
 * Built-in effect identifiers.
 *
 * An {@see ActionNode}'s `effectClass` is overloaded: it carries **either** a
 * PHP class FQN that implements {@see Effect}, **or** a sentinel naming one of
 * the kernel's built-in effects (`Create`, `Transition`). This enum names the
 * sentinel values so DSL writers and effect dispatchers do not pass raw strings
 * around. The underlying string values are stable and form the public wire
 * format of `ActionNode::effectClass`.
 */
enum BuiltinEffect: string {
    case Create     = 'kernel.builtin.create';
    case Transition = 'kernel.builtin.transition';
    case Update     = 'kernel.builtin.update';
}

/**
 * Public contract for {@see ActionNode::$effectClass} (v0.1.x).
 *
 * The string in `$effectClass` is one of exactly two shapes — the runtime's
 * {@see EffectDispatcher} distinguishes them by exact string match, in this order:
 *
 * 1. **Built-in sentinel** — equal to one of the `BuiltinEffect::*->value`
 *    strings (`'kernel.builtin.create'`, `'kernel.builtin.transition'`,
 *    `'kernel.builtin.update'`). The dispatcher routes to the matching
 *    runtime-shipped effect; `effectConfig` carries kind-specific configuration
 *    (e.g. `createStateField`, `createInitial`, `updatableFields`). The DSL
 *    produces these via {@see Action::create()}, {@see Action::transition()},
 *    {@see Action::update()}.
 *
 * 2. **Custom effect FQN** — any other string is treated as a fully-qualified
 *    PHP class name. The class MUST implement {@see Effect} and MUST be
 *    instantiable with **no constructor arguments** — the dispatcher
 *    `new`s it lazily per invocation. `effectConfig` is *not* passed to the
 *    constructor; custom effects receive their inputs through
 *    {@see Effect::execute()} and read configuration from elsewhere (closure,
 *    service locator, environment). v0.1.x does **not** define a registration
 *    hook for constructor injection.
 *
 * The two shapes share a single field deliberately — `effectClass` is the
 * canonical address of "which effect runs", and the dispatcher's first job
 * is to disambiguate the sentinel set from the custom FQN set. The string
 * values of the sentinels are stable wire metadata; renaming or moving them
 * is a public-API break.
 */

// =============================================================================
// PERSISTENCE CONTRACTS (subset of RFC-002)
// =============================================================================

/**
 * Per-entity, per-tenant CRUD-shaped contract.
 *
 * `findAll()` was added in v0.1.1 so the projection renderer can enumerate
 * entities through the repository contract instead of reaching into the SQLite
 * PDO via reflection. This is a documented contract addition — any custom
 * `Repository` implementation must add it.
 */
interface Repository {
    public function find(Reference $ref): ?Entity;
    /** @param array<string,mixed> $payload */ public function create(array $payload, ?string $identity = null): Entity;
    /** @param array<string,mixed> $patch */ public function update(Reference $ref, array $patch, Version $expected): Entity;
    /** List all entities of this kind in the active tenant, ordered by id. @return list<Entity> */
    public function findAll(): array;
}

final readonly class Entity {
    public function __construct(
        public Reference $reference,
        public Version $version,
        /** @var array<string,mixed> */ public array $fields,
    ) {}
    public function field(string $name): mixed { return $this->fields[$name] ?? null; }
}

interface PersistenceDriver {
    public function beginTransaction(Tenant $tenant): TransactionHandle;
    public function commit(TransactionHandle $h): void;
    public function rollback(TransactionHandle $h): void;
    public function context(Tenant $tenant, TransactionHandle $h): PersistenceContext;
    public function generateIdentity(string $entityFqn): string;
}

interface PersistenceContext {
    public function repository(string $entityFqn): Repository;
    public function tenant(): Tenant;
}

interface TransactionHandle {
    public function tenant(): Tenant;
}

// =============================================================================
// AUDIT CONTRACTS (subset of RFC-007)
// =============================================================================

final readonly class SingleSubject {
    public function __construct(
        public string $tenantId, public string $entityFqn, public string $identityHandle,
    ) {}
}

final readonly class AuditEntry {
    public function __construct(
        public string $entryId,
        public int $sequence,
        public ActorRef $actor,
        public string $tenant,
        public string $actionFqn,
        public SingleSubject $subject,
        /** @var array<string,mixed> */ public array $inputs,
        /** @var array<string,mixed> */ public array $outputs,
        public string $timestamp,
        public string $correlationId,
        public ?string $traceId,
        public string $invocationClass,   // 'Standard' | 'Maintenance'
        public string $emitterVersion,
    ) {}
}

interface AuditSink {
    public function writeInTransaction(AuditEntry $entry, TransactionHandle $tx): void;
}

interface Auditor {
    public function emit(AuditEntry $entry, TransactionHandle $tx): void;
}

// =============================================================================
// METADATA GRAPH (subset)
// =============================================================================

final readonly class FieldNode {
    /**
     * @param ?string $label  Human-friendly label for this field. When null
     *                        (the v0.1.x default for fields built without
     *                        `FieldBuilder::label(...)`), the
     *                        ProjectionRenderer auto-humanizes the name
     *                        (`project_id` → "Project id"). Additive; placed
     *                        last so existing positional callers — including
     *                        the manual HelloInvoicePlugin — keep compiling.
     */
    public function __construct(
        public string $name,
        public string $type,              // 'string'|'integer'|'enum'|'money'|'datetime'|'identity'|'version'|'system_string'
        public bool $system,
        public bool $nullable,
        /** @var array<string,mixed> */ public array $typeOptions,
        public mixed $default,
        public ?string $label = null,
    ) {}
}

final readonly class TransitionNode {
    public function __construct(
        public string $source,      // or '*'
        public string $target,
        public string $viaActionFqn,
    ) {}
}

final readonly class WorkflowNode {
    public function __construct(
        public string $fqn,
        public string $ownerEntityFqn,
        public string $stateField,
        /** @var string[] */ public array $states,
        public string $initial,
        /** @var TransitionNode[] */ public array $transitions,
    ) {}
}

final readonly class PolicyNode {
    public function __construct(
        public string $fqn,
        public string $implementationClass,
        /** @var array<string,mixed> */ public array $constructorArgs,
    ) {}
}

final readonly class ActionNode {
    /**
     * @param string $effectClass
     *     One of two shapes (see the docblock above {@see BuiltinEffect}):
     *     a {@see BuiltinEffect} sentinel value (`kernel.builtin.create`,
     *     `kernel.builtin.transition`, `kernel.builtin.update`), **or** a
     *     custom FQN whose class implements {@see Effect} and has a no-arg
     *     constructor. The runtime {@see EffectDispatcher} disambiguates by
     *     exact string match against the sentinel set.
     * @param array<string,mixed> $effectConfig
     *     Built-in-effect configuration consumed by the dispatcher, not by
     *     the effect class' constructor. Empty for custom-FQN effects.
     * @param FieldNode[] $inputs
     * @param string $kind  `'standard'` | `'maintenance'`.
     */
    public function __construct(
        public string $fqn,
        public string $entityFqn,
        public string $policyFqn,
        public bool $subjectRequired,
        public string $effectClass,
        public array $effectConfig,
        public array $inputs,
        public string $kind,
    ) {}
}

final readonly class ProjectionNode {
    public function __construct(
        public string $fqn,
        public string $ownerEntityFqn,
        /** @var string[] */ public array $fields,
        /** @var string[] */ public array $actionFqns,
    ) {}
}

final readonly class EntityNode {
    public function __construct(
        public string $fqn,
        public bool $tenantScoped,
        /** @var FieldNode[] */ public array $fields,
        /** @var string[] */ public array $actionFqns,
        /** @var string[] */ public array $projectionFqns,
        /** @var string[] */ public array $workflowFqns,
    ) {}
    public function field(string $name): ?FieldNode {
        foreach ($this->fields as $f) if ($f->name === $name) return $f;
        return null;
    }
}

final readonly class MetadataGraph {
    public function __construct(
        public string $hash,
        public string $kernelVersion,
        /** @var array<string,EntityNode> */ public array $entities,
        /** @var array<string,ActionNode> */ public array $actions,
        /** @var array<string,PolicyNode> */ public array $policies,
        /** @var array<string,WorkflowNode> */ public array $workflows,
        /** @var array<string,ProjectionNode> */ public array $projections,
    ) {}
}

// =============================================================================
// PLUGIN CONTRACT
// =============================================================================

interface Plugin {
    public function name(): string;            // 'billing'
    public function phpNamespace(): string;    // 'Acme\\Billing'
    /** Returns a normalized descriptor array; the compiler turns it into MetadataGraph. */
    public function describe(): array;
}

// =============================================================================
// COMPILER (minimal — accepts plugin descriptors, produces MetadataGraph)
// =============================================================================

final class Compiler {
    /** @param Plugin[] $plugins */
    public function compile(array $plugins, string $kernelVersion = '1.0.0'): MetadataGraph {
        $entities = []; $actions = []; $policies = []; $workflows = []; $projections = [];
        foreach ($plugins as $plugin) {
            $desc = $plugin->describe();
            foreach ($desc['entities'] ?? [] as $e) {
                $entities[$e->fqn] = $e;
            }
            foreach ($desc['actions'] ?? [] as $a) {
                if (isset($actions[$a->fqn])) {
                    throw new \RuntimeException("DuplicateRegistration: action {$a->fqn}");
                }
                $actions[$a->fqn] = $a;
            }
            foreach ($desc['policies'] ?? [] as $p) {
                $policies[$p->fqn] = $p;
            }
            foreach ($desc['workflows'] ?? [] as $w) {
                $workflows[$w->fqn] = $w;
            }
            foreach ($desc['projections'] ?? [] as $pr) {
                $projections[$pr->fqn] = $pr;
            }
        }
        // Validate references
        foreach ($actions as $a) {
            if (!isset($policies[$a->policyFqn])) {
                throw new \RuntimeException("DanglingReference: action {$a->fqn} → policy {$a->policyFqn} (not registered)");
            }
            if (!isset($entities[$a->entityFqn])) {
                throw new \RuntimeException("DanglingReference: action {$a->fqn} → entity {$a->entityFqn} (not registered)");
            }
        }
        foreach ($workflows as $w) {
            if (!isset($entities[$w->ownerEntityFqn])) {
                throw new \RuntimeException("DanglingReference: workflow {$w->fqn} → entity {$w->ownerEntityFqn}");
            }
            $entity = $entities[$w->ownerEntityFqn];
            if ($entity->field($w->stateField) === null) {
                throw new \RuntimeException("WorkflowCoherence: workflow {$w->fqn} state field '{$w->stateField}' not on entity {$w->ownerEntityFqn}");
            }
            if (!in_array($w->initial, $w->states, true)) {
                throw new \RuntimeException("WorkflowCoherence: workflow {$w->fqn} initial state '{$w->initial}' not in declared states [" . implode(',', $w->states) . "]");
            }
            foreach ($w->transitions as $t) {
                if ($t->source !== '*' && !in_array($t->source, $w->states, true)) {
                    throw new \RuntimeException("WorkflowCoherence: transition source '{$t->source}' not in states");
                }
                if (!in_array($t->target, $w->states, true)) {
                    throw new \RuntimeException("WorkflowCoherence: transition target '{$t->target}' not in states");
                }
                if (!isset($actions[$t->viaActionFqn])) {
                    throw new \RuntimeException("DanglingReference: transition via '{$t->viaActionFqn}' not registered");
                }
            }
        }
        // Canonicalize + hash
        $sortByKey = function(array $a) { ksort($a); return $a; };
        $entities    = $sortByKey($entities);
        $actions     = $sortByKey($actions);
        $policies    = $sortByKey($policies);
        $workflows   = $sortByKey($workflows);
        $projections = $sortByKey($projections);

        $canonical = json_encode([
            'actions'       => array_keys($actions),
            'entities'      => array_keys($entities),
            'kernelVersion' => $kernelVersion,
            'policies'      => array_keys($policies),
            'projections'   => array_keys($projections),
            'workflows'     => array_keys($workflows),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $hash = hash('sha256', $canonical);

        return new MetadataGraph($hash, $kernelVersion, $entities, $actions, $policies, $workflows, $projections);
    }
}

// =============================================================================
// ULID GENERATOR  (Crockford base32, 26 chars)
// =============================================================================

final class Ulid {
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(): string {
        $timestampMs = (int) round(microtime(true) * 1000);
        $bytes = pack('J', $timestampMs);     // 8 bytes big-endian
        $bytes = substr($bytes, 2);            // take last 6 bytes (48 bits)
        $bytes .= random_bytes(10);            // 80 bits randomness
        // 16 bytes → 26 base32 chars
        return self::encodeCrockford($bytes);
    }

    private static function encodeCrockford(string $bytes): string {
        // Convert 16 bytes (128 bits) to 26 chars (130 bits, top 2 zero-padded)
        // Simple bit-by-bit; 26 chars * 5 bits = 130 bits.
        $bits = '';
        foreach (str_split($bytes) as $b) $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        $bits = str_pad($bits, 130, '0', STR_PAD_LEFT);   // pad to 130 from left
        $out = '';
        for ($i = 0; $i < 130; $i += 5) {
            $chunk = substr($bits, $i, 5);
            $out .= self::ALPHABET[(int) bindec($chunk)];
        }
        return $out;
    }
}

// =============================================================================
// INVOCATION RESULT  (typed wrapper around the loose Invoker output array)
// =============================================================================

/**
 * Typed outcome of an invocation.
 *
 * `Invoker::invoke()` returns `array<string,mixed>` (the raw effect outputs) —
 * useful but loosely typed. `InvocationResult` wraps that array together with
 * the post-action {@see Reference} (the new subject for a `create`, the input
 * subject for a transition) so callers get an IDE-discoverable surface.
 *
 * Produced by {@see \Ausus\Application::run()}. The underlying `outputs` array
 * is still available for callers that need it.
 */
final readonly class InvocationResult
{
    /** @param array<string,mixed> $outputs effect outputs, as returned by the runtime */
    public function __construct(
        public string $actionFqn,
        public ?Reference $subject,
        public array $outputs,
    ) {}

    /** The identity of the affected entity, when one exists. */
    public function id(): ?string
    {
        $id = $this->subject?->identityHandle ?? ($this->outputs['id'] ?? null);
        return $id === null ? null : (string) $id;
    }

    /** Read a single output value by key, or null if absent. */
    public function output(string $key): mixed
    {
        return $this->outputs[$key] ?? null;
    }
}

// =============================================================================
// EXCEPTIONS  (V0 minimal closed-ish taxonomy)
// =============================================================================

class AususError extends \RuntimeException {}

class UnknownAction extends AususError implements Errors\NotFoundError {}
class PolicySubjectRequired extends AususError implements Errors\BadRequestError {}
/**
 * @internal Reserved exception class — not raised by any v0.1.x runtime path.
 *           Declared so future Invoker code paths (notably the policy
 *           bootstrap when actor resolution becomes pluggable) can raise it
 *           without a wire/taxonomy break. Do not catch it in v0.1.x consumer
 *           code — there is nothing to catch.
 */
class ActorRequired extends AususError implements Errors\BadRequestError {}
/**
 * @internal Reserved exception class — not raised by any v0.1.x runtime path.
 *           Declared so future PersistenceContext bootstraps can raise it
 *           without a wire/taxonomy break. Do not catch in v0.1.x consumer
 *           code.
 */
class TenantContextRequired extends AususError implements Errors\BadRequestError {}
class TenantBoundaryViolation extends AususError implements Errors\ForbiddenError {}
class PolicyDenied extends AususError implements Errors\ForbiddenError {}
class WorkflowStateMismatch extends AususError implements Errors\ConflictError {}
class WorkflowSubjectNotFound extends AususError implements Errors\NotFoundError {}
class EffectFailed extends AususError implements Errors\InternalError {
    public function __construct(string $actionFqn, public readonly \Throwable $causeError) {
        parent::__construct("EffectFailed: {$actionFqn}: " . $causeError->getMessage(), 0, $causeError);
    }
}
class ConcurrencyConflict extends AususError implements Errors\ConflictError {
    public function __construct(public readonly Reference $ref, public readonly string $expected, public readonly string $actual) {
        parent::__construct("ConcurrencyConflict: {$ref->entityFqn}/{$ref->identityHandle} expected={$expected} actual={$actual}");
    }
}
class NotFound extends AususError implements Errors\NotFoundError {
    public function __construct(public readonly Reference $ref) {
        parent::__construct("NotFound: {$ref->entityFqn}/{$ref->identityHandle} in tenant {$ref->tenantId}");
    }
}
class AuditEmissionFailed extends AususError implements Errors\InternalError {}
/**
 * @internal Reserved exception class — not raised by any v0.1.x runtime path.
 *           The v0.1.x WorkflowRuntime uses {@see WorkflowStateMismatch} for
 *           guard failures. This class exists so a future guard-as-policy
 *           split can introduce a distinct denial-by-guard signal without a
 *           wire/taxonomy break. Do not catch in v0.1.x consumer code.
 */
class WorkflowGuardDenied extends AususError implements Errors\ForbiddenError {}
