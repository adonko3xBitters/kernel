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

    // Internal registration (called by builders during finalize):
    public function _registerEntity(EntityNode $e): void     { $this->entities[$e->fqn]    = $e; }
    public function _registerAction(ActionNode $a): void     { $this->actions[$a->fqn]     = $a; }
    public function _registerPolicy(PolicyNode $p): void     { if (!isset($this->policies[$p->fqn])) $this->policies[$p->fqn] = $p; }
    public function _registerWorkflow(WorkflowNode $w): void { $this->workflows[$w->fqn]   = $w; }
    public function _registerProjection(ProjectionNode $p): void { $this->projections[$p->fqn] = $p; }

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

    public function buildField(string $name, bool $system = false): FieldNode
    {
        return new FieldNode(
            name: $name,
            type: $this->type,
            system: $system,
            nullable: $this->nullable,
            typeOptions: $this->typeOptions,
            default: $this->default,
        );
    }
}

// =============================================================================
// ActionBuilder
// =============================================================================

final class ActionBuilder
{
    public string $kind = 'standard';   // 'create' | 'transition'
    /** @var string[] */ public array $createInputs = [];
    /** @var array<int, array{field:string, from:string, to:string}> */
    public array $transitions = [];
    /** @var string[] */ public array $stamps = [];
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

    /** chained additional transition (multi-transition Actions per amended RFC-006 §4.2).
     *  PHP forbids same-class static+instance methods sharing a name, so the
     *  instance form is `andTransition()`. RFC-011 §8.2 example uses wildcards
     *  (`from: '*'`) for "cancel from any state"; this helper is the explicit
     *  multi-source alternative when wildcards conflict with explicit sources. */
    public function andTransition(string $field, string $from, string $to): self
    {
        $this->transitions[] = ['field' => $field, 'from' => $from, 'to' => $to];
        return $this;
    }

    public function stamp(string $field): self       { $this->stamps[] = $field; return $this; }
    public function requireRole(string $role): self  { $this->requiredRole = $role; return $this; }

    /** @return array{0:ActionNode, 1:PolicyNode[]} */
    public function build(string $entityFqn, string $actionFqn, string $localName, array $entityFields): array
    {
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
            // Infer initial Workflow state from any enum field with default
            $stateField = null; $initial = null;
            foreach ($entityFields as $f) {
                if ($f->type === 'enum' && $f->default !== null) {
                    $stateField = $f->name; $initial = $f->default; break;
                }
            }
            return [new ActionNode(
                fqn: $actionFqn,
                entityFqn: $entityFqn,
                policyFqn: $policyFqn,
                subjectRequired: false,
                effectClass: 'kernel.builtin.create',
                effectConfig: [
                    'entityFqn'          => $entityFqn,
                    'workflowStateField' => $stateField,
                    'workflowInitial'    => $initial,
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
                effectClass: 'kernel.builtin.transition',
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
    private ?string $workflowField = null;
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

    /** Declare the enum field whose values drive Workflow state inference (RFC-011 §6.4). */
    public function workflow(string $fieldName): self
    {
        $this->workflowField = $fieldName;
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

        // Build actions + per-action policies
        $actionFqns = [];
        $localToFqn = [];
        foreach ($this->actionBuilders as $localName => $ab) {
            $afqn = "{$this->fqn}.{$localName}";
            [$action, $policies] = $ab->build($this->fqn, $afqn, $localName, $allFields);
            $this->dsl->_registerAction($action);
            foreach ($policies as $p) $this->dsl->_registerPolicy($p);
            $actionFqns[] = $afqn;
            $localToFqn[$localName] = $afqn;
        }

        // Workflow inference (RFC-011 §6.4)
        $workflowFqns = [];
        if ($this->workflowField !== null) {
            $stateField = null;
            foreach ($userFields as $f) {
                if ($f->name === $this->workflowField) { $stateField = $f; break; }
            }
            if ($stateField === null) {
                throw new \RuntimeException("workflow('{$this->workflowField}') refers to unknown field on {$this->fqn}");
            }
            if ($stateField->type !== 'enum') {
                throw new \RuntimeException("workflow('{$this->workflowField}') field must be enum, got '{$stateField->type}'");
            }
            $states  = $stateField->typeOptions['options'];
            $initial = $stateField->default ?? $states[0];

            $transitions = [];
            foreach ($this->actionBuilders as $localName => $ab) {
                $afqn = "{$this->fqn}.{$localName}";
                foreach ($ab->transitions as $t) {
                    if ($t['field'] === $this->workflowField) {
                        $transitions[] = new TransitionNode($t['from'], $t['to'], $afqn);
                    }
                }
            }

            $wfqn = "{$this->fqn}.lifecycle";
            $this->dsl->_registerWorkflow(new WorkflowNode(
                fqn: $wfqn,
                ownerEntityFqn: $this->fqn,
                stateField: $this->workflowField,
                states: $states,
                initial: $initial,
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
}
