<?php
declare(strict_types=1);

namespace Ausus;

/**
 * RFC-011 — minimal executable DSL surface.
 *
 * Produces the SAME descriptor arrays the manual `HelloInvoicePlugin` produces;
 * the Compiler consumes both paths unchanged.
 *
 * V0 surface (RFC-011 §1.1 + the bits HelloInvoice actually uses):
 *   - DslPlugin            (abstract base; replaces manual `implements Plugin`)
 *   - Dsl                  (root builder; entity('local') → EntityBuilder)
 *   - EntityBuilder        (fluent: fields() → actions() → workflow() → projection())
 *   - FieldBuilder         (fluent: ->nullable(), ->max(), ->default(), ->unique(), ->currency(), ->options())
 *   - ActionBuilder        (Action::create / Action::transition + ->stamp + ->requireRole + chained ->transition)
 *   - Field / Action       (static facades for fluent entry points)
 *
 * Deferred (RFC-011 §1.2 / §16):
 *   - Convention-resolved Policy / Effect classes (V0 explicit references).
 *   - Field::visibility (Amendment-01 §A-1.2 Field-level Policies).
 *   - Reserved namespace shielding beyond the kernel's own validation.
 *   - DSL invariant enforcement runtime checks (RFC-001 §5.8 detection).
 *   - Tenant-added override registration (RFC-003 §8 paths).
 *   - DSL-level diagnostics with file/line attribution (RFC-011 §10).
 */

// =============================================================================
// DSL Plugin base class
// =============================================================================

abstract class DslPlugin implements Plugin
{
    abstract public function name(): string;
    abstract public function phpNamespace(): string;

    /** Plugin authors implement this. Replaces manual `describe()`. */
    abstract public function dsl(Dsl $dsl): void;

    /** Implements the kernel's Plugin::describe() in terms of dsl(). */
    public function describe(): array
    {
        $dsl = new Dsl($this);
        $this->dsl($dsl);
        return $dsl->emit();
    }
}

// =============================================================================
// Dsl root builder
// =============================================================================

final class Dsl
{
    /** @var EntityBuilder[] */
    private array $entityBuilders = [];

    /** @var array<string, EntityNode> */     private array $entities    = [];
    /** @var array<string, ActionNode> */     private array $actions     = [];
    /** @var array<string, PolicyNode> */     private array $policies    = [];
    /** @var array<string, WorkflowNode> */   private array $workflows   = [];
    /** @var array<string, ProjectionNode> */ private array $projections = [];

    public function __construct(private readonly Plugin $plugin) {}

    public function pluginName(): string { return $this->plugin->name(); }

    public function entity(string $localName): EntityBuilder
    {
        $fqn = $this->plugin->name() . '.' . $localName;
        $builder = new EntityBuilder($this, $fqn);
        $this->entityBuilders[] = $builder;
        return $builder;
    }

    // Internal registration (called by builders during finalize).
    //
    // These methods are `public` only because PHP cannot model
    // package-private visibility — they are an internal seam between
    // {@see EntityBuilder::finalize()} and the surrounding {@see Dsl}
    // instance. Consumers MUST NOT call them. The framework gives no
    // backward-compatibility guarantee on their signatures or existence
    // outside that intra-package contract.
    /** @internal */
    public function _registerEntity(EntityNode $e): void     { $this->entities[$e->fqn]    = $e; }
    /** @internal */
    public function _registerAction(ActionNode $a): void     { $this->actions[$a->fqn]     = $a; }
    /** @internal */
    public function _registerPolicy(PolicyNode $p): void     { if (!isset($this->policies[$p->fqn])) $this->policies[$p->fqn] = $p; }
    /** @internal */
    public function _registerWorkflow(WorkflowNode $w): void { $this->workflows[$w->fqn]   = $w; }
    /** @internal */
    public function _registerProjection(ProjectionNode $p): void { $this->projections[$p->fqn] = $p; }

    /**
     * Finalize every registered EntityBuilder and return the descriptor
     * array consumed by the Compiler.
     *
     * @internal Called from {@see DslPlugin::describe()}. Consumers MUST
     *           NOT call this method directly; they call `describe()`,
     *           which is the public Plugin contract.
     */
    public function emit(): array
    {
        // Finalize all builders (idempotent)
        foreach ($this->entityBuilders as $eb) {
            $eb->finalize();
        }
        return [
            'entities'    => array_values($this->entities),
            'actions'     => array_values($this->actions),
            'policies'    => array_values($this->policies),
            'workflows'   => array_values($this->workflows),
            'projections' => array_values($this->projections),
        ];
    }
}

// =============================================================================
// FieldBuilder
// =============================================================================

final class FieldBuilder
{
    private bool  $nullable          = false;
    private mixed $default           = null;
    /** @var array<string, mixed> */
    private array $typeOptions       = [];
    private bool  $uniqueWithinTenant = false;
    private ?string $label           = null;

    public function __construct(public readonly string $type) {}

    public function nullable(bool $v = true): self { $this->nullable = $v; return $this; }
    public function unique(): self                  { $this->uniqueWithinTenant = true; return $this; }
    public function default(mixed $v): self          { $this->default = $v; return $this; }

    /** string max length */
    public function max(int $n): self               { $this->typeOptions['maxLength'] = $n; return $this; }
    /** money currency */
    public function currency(string $c): self        { $this->typeOptions['currency'] = $c; return $this; }
    /** enum options */
    public function options(array $opts): self      { $this->typeOptions['options']  = array_values($opts); return $this; }

    /**
     * Explicit human-friendly label for this field.
     *
     * Used by the renderer for table headers, detail-view `<dt>` labels and
     * form-field labels. When unset, the runtime auto-humanises the field
     * name (`project_id` → `Project id`); calling `->label('Project')` makes
     * that header read "Project" instead.
     *
     * Strictly cosmetic — the field name remains the source of truth for
     * persistence, projection lookups and action input keys.
     */
    public function label(string $label): self
    {
        if ($label === '') {
            throw new \InvalidArgumentException(
                "FieldBuilder::label(): label must be a non-empty string."
            );
        }
        $this->label = $label;
        return $this;
    }

    public function buildField(string $name, bool $system = false): FieldNode
    {
        return new FieldNode(
            name: $name,
            type: $this->type,
            system: $system,
            nullable: $this->nullable,
            typeOptions: $this->typeOptions,
            default: $this->default,
            label: $this->label,
        );
    }
}

// =============================================================================
// ActionBuilder
// =============================================================================

final class ActionBuilder
{
    public string $kind = 'standard';   // 'create' | 'transition' | 'update'
    /** @var string[] */ public array $createInputs = [];
    /** @var array<int, array{field:string, from:string, to:string}> */
    public array $transitions = [];
    /** @var string[] */ public array $stamps = [];
    /** @var string[] */ public array $updateFieldNames = [];
    public ?string $requiredRole = null;

    public static function create(string ...$inputs): self
    {
        $b = new self();
        $b->kind = 'create';
        $b->createInputs = $inputs;
        return $b;
    }

    public static function transition(string $field, string $from, string $to): self
    {
        $b = new self();
        $b->kind = 'transition';
        $b->transitions[] = ['field' => $field, 'from' => $from, 'to' => $to];
        return $b;
    }

    /**
     * Build an `update` action — partial-patch on a fixed list of fields.
     *
     * Each named field must exist on the entity, must not be a system field
     * (`id`, `tenant_id`, `_version`, `created_at`, `updated_at`) and must
     * **not** be the workflow state field (when one is declared). State moves
     * keep flowing exclusively through {@see transition()}. Compile-time
     * validation is performed in {@see build()} once the entity field
     * resolution is available.
     *
     * See ADR-0002 — `docs/adr/0002-update-actions.md`.
     */
    public static function update(string ...$fieldNames): self
    {
        if ($fieldNames === []) {
            throw new \RuntimeException(
                "Action::update() requires at least one field name to be patchable."
            );
        }
        $b = new self();
        $b->kind = 'update';
        $b->updateFieldNames = array_values($fieldNames);
        return $b;
    }

    /**
     * Append an additional transition to this action.
     *
     * `Action::transition()` is a **static** constructor — it creates a fresh
     * `ActionBuilder` — so PHP forbids a same-named instance method that would
     * extend it. `addTransition()` is the canonical chained form for declaring
     * a multi-source / multi-target action:
     *
     * ```php
     * Action::transition('status', from: 'DRAFT',  to: 'CANCELLED')
     *     ->addTransition('status', from: 'ISSUED', to: 'CANCELLED');
     * ```
     */
    public function addTransition(string $field, string $from, string $to): self
    {
        $this->transitions[] = ['field' => $field, 'from' => $from, 'to' => $to];
        return $this;
    }

    /**
     * @deprecated Prefer {@see addTransition()} — same behaviour, consistent
     *             `add*` naming. `andTransition()` will remain through v0.1.x
     *             for backward compatibility.
     */
    public function andTransition(string $field, string $from, string $to): self
    {
        return $this->addTransition($field, $from, $to);
    }

    public function stamp(string $field): self       { $this->stamps[] = $field; return $this; }
    public function requireRole(string $role): self  { $this->requiredRole = $role; return $this; }

    /**
     * Compile this ActionBuilder into an {@see ActionNode} and its policy.
     *
     * @internal Called from {@see EntityBuilder::finalize()}. The signature
     *           and parameter shape are an intra-package contract and may
     *           change between minor releases. Consumers should never call
     *           it directly — the public action surface is the
     *           {@see Action} facade (`Action::create()`,
     *           `Action::transition()`, `Action::update()`).
     *
     * @param array<int,FieldNode> $entityFields all entity fields (system + user)
     * @param ?string $createStateField workflow state field a `create` effect seeds,
     *                                  resolved by {@see EntityBuilder} — null when the
     *                                  entity has no Workflow.
     * @param ?string $createInitial    initial state value the `create` effect writes.
     * @return array{0:ActionNode, 1:PolicyNode[]}
     */
    public function build(
        string $entityFqn,
        string $actionFqn,
        string $localName,
        array $entityFields,
        ?string $createStateField = null,
        ?string $createInitial = null,
    ): array {
        $policyFqn = "{$entityFqn}.policy.{$localName}";
        $policy = new PolicyNode(
            $policyFqn,
            \Ausus\Runtime\RoleRequired::class,
            ['role' => $this->requiredRole ?? 'unspecified'],
        );

        if ($this->kind === 'create') {
            $inputs = [];
            foreach ($this->createInputs as $inputName) {
                $found = null;
                foreach ($entityFields as $ef) {
                    if ($ef->name === $inputName) { $found = $ef; break; }
                }
                if ($found === null) {
                    throw new \RuntimeException("Action::create input '{$inputName}' not declared on {$entityFqn}");
                }
                $inputs[] = $found;
            }
            // The initial Workflow state is resolved by EntityBuilder from the
            // explicit ->workflow() declaration (or legacy inference); the
            // ActionBuilder no longer scans fields for it.
            return [new ActionNode(
                fqn: $actionFqn,
                entityFqn: $entityFqn,
                policyFqn: $policyFqn,
                subjectRequired: false,
                effectClass: BuiltinEffect::Create->value,
                effectConfig: [
                    'entityFqn'          => $entityFqn,
                    'workflowStateField' => $createStateField,
                    'workflowInitial'    => $createInitial,
                ],
                inputs: $inputs,
                kind: 'standard',
            ), [$policy]];
        }

        if ($this->kind === 'update') {
            $inputs = [];
            $meta = [];
            foreach ($this->updateFieldNames as $name) {
                $found = null;
                foreach ($entityFields as $ef) {
                    if ($ef->name === $name) { $found = $ef; break; }
                }
                if ($found === null) {
                    throw new \RuntimeException(
                        "Action::update('{$name}') refers to a field that is not declared on entity '{$entityFqn}'."
                    );
                }
                if ($found->system) {
                    throw new \RuntimeException(
                        "Action::update('{$name}') cannot patch a system field on entity '{$entityFqn}' — "
                        . "system fields (id / tenant_id / _version / created_at / updated_at) are runtime-managed."
                    );
                }
                if ($createStateField !== null && $name === $createStateField) {
                    throw new \RuntimeException(
                        "Action::update('{$name}') cannot patch the workflow state field on entity '{$entityFqn}' — "
                        . "state moves go through Action::transition(...). See ADR-0002 §7."
                    );
                }
                $inputs[] = $found;
                $meta[] = ['name' => $found->name, 'type' => $found->type, 'nullable' => $found->nullable];
            }
            return [new ActionNode(
                fqn: $actionFqn,
                entityFqn: $entityFqn,
                policyFqn: $policyFqn,
                subjectRequired: true,
                effectClass: BuiltinEffect::Update->value,
                effectConfig: [
                    'entityFqn'       => $entityFqn,
                    'updatableFields' => $meta,
                ],
                inputs: $inputs,
                kind: 'standard',
            ), [$policy]];
        }

        if ($this->kind === 'transition') {
            $first = $this->transitions[0];
            return [new ActionNode(
                fqn: $actionFqn,
                entityFqn: $entityFqn,
                policyFqn: $policyFqn,
                subjectRequired: true,
                effectClass: BuiltinEffect::Transition->value,
                effectConfig: [
                    'entityFqn'  => $entityFqn,
                    'stateField' => $first['field'],
                    'target'     => $first['to'],
                    'stamps'     => $this->stamps,
                ],
                inputs: [],
                kind: 'standard',
            ), [$policy]];
        }

        throw new \RuntimeException("ActionBuilder: unsupported kind '{$this->kind}'");
    }
}

// =============================================================================
// EntityBuilder
// =============================================================================

final class EntityBuilder
{
    /** @var array<string, FieldBuilder> */
    private array $fieldBuilders = [];
    /** @var array<string, ActionBuilder> */
    private array $actionBuilders = [];
    private ?string $workflowField   = null;
    private ?string $workflowInitial = null;
    private bool    $workflowDeclared = false;
    /** @var array<string, array{fields:array, actions:array, role:?string, policyFqn:?string}> */
    private array $projectionsConfig = [];
    private bool $finalized = false;

    public function __construct(
        private readonly Dsl $dsl,
        private readonly string $fqn,
    ) {}

    /** @param array<string, FieldBuilder> $fields */
    public function fields(array $fields): self
    {
        $this->fieldBuilders = $fields;
        return $this;
    }

    /** @param array<string, ActionBuilder> $actions */
    public function actions(array $actions): self
    {
        $this->actionBuilders = $actions;
        return $this;
    }

    /**
     * Declare the Workflow for this entity, explicitly.
     *
     * ```php
     * $dsl->entity('invoice')
     *     ->fields([...])
     *     ->workflow(field: 'status', initial: 'DRAFT');
     * ```
     *
     * @param string  $field   the enum field whose values are the workflow states.
     * @param ?string $initial the state a freshly created entity starts in. When
     *                         omitted, it is inferred from the field default —
     *                         this is **deprecated** and emits an `E_USER_DEPRECATED`
     *                         notice. Always pass `initial` explicitly.
     */
    public function workflow(string $field, ?string $initial = null): self
    {
        $this->workflowField    = $field;
        $this->workflowInitial  = $initial;
        $this->workflowDeclared = true;
        return $this;
    }

    /**
     * @param string[] $fields  field names (no '*' wildcard in V0)
     * @param string[] $actions local action names (defaults to all entity actions if empty)
     */
    public function projection(string $localName, array $fields, array $actions = [], ?string $role = null, ?string $policyFqn = null): self
    {
        $this->projectionsConfig[$localName] = compact('fields', 'actions', 'role', 'policyFqn');
        return $this;
    }

    /**
     * Resolve and register every node this EntityBuilder accumulated.
     *
     * @internal Called from {@see Dsl::emit()}. Consumers MUST NOT call
     *           it directly — the public surface is the fluent builder
     *           chain that ends with the implicit finalize on
     *           {@see DslPlugin::describe()}. The method is idempotent
     *           (a second call is a no-op) but its execution order
     *           inside the Dsl emit cycle is part of the kernel's
     *           private contract.
     */
    public function finalize(): void
    {
        if ($this->finalized) return;
        $this->finalized = true;

        // System fields (V0: emitted here for byte-identical match with manual descriptor path;
        // RFC-001 §2.1.1.6 / docs/COMPILER-DESIGN.md §10.4 spec Normalizer injection — deferred).
        $systemFields = [
            new FieldNode('id',         'identity',      true, false, [], null),
            new FieldNode('tenant_id',  'system_string', true, false, [], null),
            new FieldNode('_version',   'version',       true, false, [], null),
            new FieldNode('created_at', 'datetime',      true, false, [], null),
            new FieldNode('updated_at', 'datetime',      true, false, [], null),
        ];
        $userFields = [];
        foreach ($this->fieldBuilders as $name => $fb) {
            $userFields[] = $fb->buildField($name, false);
        }
        $allFields = array_merge($systemFields, $userFields);

        // Resolve the Workflow declaration first — the runtime prefers the
        // explicit ->workflow() metadata over any field-order inference.
        $workflow = $this->resolveWorkflow($userFields);

        // The `create` effect seeds the initial state. Prefer the resolved
        // Workflow; fall back to legacy per-entity inference only when no
        // Workflow was declared at all.
        if ($workflow !== null) {
            $createStateField = $workflow['field']->name;
            $createInitial    = $workflow['initial'];
        } else {
            [$createStateField, $createInitial] = $this->inferCreateState($userFields);
        }

        // Build actions + per-action policies
        $actionFqns = [];
        $localToFqn = [];
        foreach ($this->actionBuilders as $localName => $ab) {
            $afqn = "{$this->fqn}.{$localName}";
            [$action, $policies] = $ab->build(
                $this->fqn, $afqn, $localName, $allFields, $createStateField, $createInitial,
            );
            $this->dsl->_registerAction($action);
            foreach ($policies as $p) $this->dsl->_registerPolicy($p);
            $actionFqns[] = $afqn;
            $localToFqn[$localName] = $afqn;
        }

        // Register the Workflow node (only when one was declared).
        $workflowFqns = [];
        if ($workflow !== null) {
            $transitions = [];
            foreach ($this->actionBuilders as $localName => $ab) {
                $afqn = "{$this->fqn}.{$localName}";
                foreach ($ab->transitions as $t) {
                    if ($t['field'] === $workflow['field']->name) {
                        $transitions[] = new TransitionNode($t['from'], $t['to'], $afqn);
                    }
                }
            }
            $wfqn = "{$this->fqn}.lifecycle";
            $this->dsl->_registerWorkflow(new WorkflowNode(
                fqn: $wfqn,
                ownerEntityFqn: $this->fqn,
                stateField: $workflow['field']->name,
                states: $workflow['states'],
                initial: $workflow['initial'],
                transitions: $transitions,
            ));
            $workflowFqns[] = $wfqn;
        }

        // Build projections; V0 uses a single shared `<entity>.projection.read` policy
        // when projection roles are declared.
        $projectionFqns = [];
        $sharedPolicyFqn = "{$this->fqn}.projection.read";
        $sharedPolicyRegistered = false;
        foreach ($this->projectionsConfig as $localName => $cfg) {
            $projFqn = "{$this->fqn}.{$localName}";
            $polFqn  = $cfg['policyFqn'] ?? $sharedPolicyFqn;
            if ($cfg['role'] !== null && !$sharedPolicyRegistered) {
                $this->dsl->_registerPolicy(new PolicyNode(
                    $polFqn,
                    \Ausus\Runtime\RoleRequired::class,
                    ['role' => $cfg['role']],
                ));
                $sharedPolicyRegistered = true;
            }
            $projActionFqns = empty($cfg['actions'])
                ? $actionFqns
                : array_map(fn(string $local) => $localToFqn[$local] ?? throw new \RuntimeException("projection {$projFqn} references unknown action '{$local}'"), $cfg['actions']);
            $this->dsl->_registerProjection(new ProjectionNode(
                fqn: $projFqn,
                ownerEntityFqn: $this->fqn,
                fields: $cfg['fields'],
                actionFqns: $projActionFqns,
            ));
            $projectionFqns[] = $projFqn;
        }

        // Finally, register the entity
        $this->dsl->_registerEntity(new EntityNode(
            fqn: $this->fqn,
            tenantScoped: true,
            fields: $allFields,
            actionFqns: $actionFqns,
            projectionFqns: $projectionFqns,
            workflowFqns: $workflowFqns,
        ));
    }

    /**
     * Resolve an explicit ->workflow() declaration into its state field, the
     * full set of states, and the validated initial state.
     *
     * Returns null when ->workflow() was not called on this entity (the entity
     * has no Workflow). Throws a validation error for an unknown field, a
     * non-enum field, an enum with no states, or an out-of-range initial state.
     *
     * @param array<int,FieldNode> $userFields
     * @return array{field:FieldNode, states:list<string>, initial:string}|null
     */
    private function resolveWorkflow(array $userFields): ?array
    {
        if (!$this->workflowDeclared) {
            return null;
        }

        $field = null;
        foreach ($userFields as $f) {
            if ($f->name === $this->workflowField) { $field = $f; break; }
        }
        if ($field === null) {
            $declared = implode(', ', array_map(fn(FieldNode $f) => $f->name, $userFields));
            throw new \RuntimeException(
                "WorkflowFieldNotFound: workflow(field: '{$this->workflowField}') on entity "
                . "'{$this->fqn}' refers to a field that is not declared. Declared fields: [{$declared}]."
            );
        }
        if ($field->type !== 'enum') {
            throw new \RuntimeException(
                "WorkflowFieldNotEnum: workflow field '{$this->workflowField}' on entity "
                . "'{$this->fqn}' must be an enum field, but it is of type '{$field->type}'."
            );
        }

        /** @var list<string> $states */
        $states = array_values($field->typeOptions['options'] ?? []);
        if ($states === []) {
            throw new \RuntimeException(
                "WorkflowFieldNoStates: enum field '{$this->workflowField}' on entity "
                . "'{$this->fqn}' declares no states."
            );
        }

        $initial = $this->workflowInitial;
        if ($initial === null) {
            // Backward-compatible fallback: infer the initial state from the
            // field default. Deprecated — the initial state should be explicit.
            $initial = $field->default ?? $states[0];
            trigger_error(
                "AUSUS deprecation: entity '{$this->fqn}' calls workflow('{$this->workflowField}') "
                . "without an explicit initial state; it was inferred as '{$initial}'. Declare it "
                . "explicitly: ->workflow(field: '{$this->workflowField}', initial: '{$initial}'). "
                . 'Implicit inference will be removed in a future release.',
                E_USER_DEPRECATED,
            );
        }
        if (!in_array($initial, $states, true)) {
            throw new \RuntimeException(
                "WorkflowInitialInvalid: initial state '{$initial}' for entity '{$this->fqn}' is not "
                . "one of the '{$this->workflowField}' enum states [" . implode(', ', $states) . '].'
            );
        }

        return ['field' => $field, 'states' => $states, 'initial' => $initial];
    }

    /**
     * Legacy implicit workflow-state inference: pick the single enum field that
     * carries a default. Runs only when ->workflow() was NOT called, and is
     * preserved purely for backward compatibility.
     *
     *  - 0 candidate fields → [null, null] (the entity simply has no Workflow).
     *  - 1 candidate        → used, with an E_USER_DEPRECATED notice.
     *  - 2+ candidates      → AmbiguousWorkflowField validation error.
     *
     * @param array<int,FieldNode> $userFields
     * @return array{0:?string, 1:?string} [stateField, initial]
     */
    private function inferCreateState(array $userFields): array
    {
        $candidates = array_values(array_filter(
            $userFields,
            fn(FieldNode $f) => $f->type === 'enum' && $f->default !== null,
        ));
        if ($candidates === []) {
            return [null, null];
        }
        if (count($candidates) > 1) {
            $names = implode(', ', array_map(fn(FieldNode $f) => $f->name, $candidates));
            throw new \RuntimeException(
                "AmbiguousWorkflowField: entity '{$this->fqn}' has multiple enum fields with a default "
                . "({$names}); the workflow state field cannot be inferred. Declare it explicitly: "
                . "->workflow(field: '<field>', initial: '<state>')."
            );
        }
        $field = $candidates[0];
        trigger_error(
            "AUSUS deprecation: entity '{$this->fqn}' relies on implicit workflow-state inference "
            . "(enum field '{$field->name}' with a default). Declare the workflow explicitly: "
            . "->workflow(field: '{$field->name}', initial: '{$field->default}'). "
            . 'Implicit inference will be removed in a future release.',
            E_USER_DEPRECATED,
        );
        return [$field->name, $field->default];
    }
}

// =============================================================================
// Static facades  (Field::string(), Action::create(), Action::transition(), ...)
// =============================================================================

final class Field
{
    public static function string(): FieldBuilder        { return new FieldBuilder('string'); }
    public static function integer(): FieldBuilder       { return new FieldBuilder('integer'); }
    public static function datetime(): FieldBuilder      { return new FieldBuilder('datetime'); }
    public static function money(): FieldBuilder         { return new FieldBuilder('money'); }
    public static function enum(string ...$options): FieldBuilder
    {
        return (new FieldBuilder('enum'))->options($options);
    }
}

final class Action
{
    public static function create(string ...$inputs): ActionBuilder
    {
        return ActionBuilder::create(...$inputs);
    }
    public static function transition(string $field, string $from, string $to): ActionBuilder
    {
        return ActionBuilder::transition($field, $from, $to);
    }

    /**
     * Partial-PATCH update action over a fixed list of patchable fields.
     * See ADR-0002 — `docs/adr/0002-update-actions.md`.
     */
    public static function update(string ...$fieldNames): ActionBuilder
    {
        return ActionBuilder::update(...$fieldNames);
    }
}
