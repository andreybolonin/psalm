<?php
namespace Psalm;

use function array_filter;
use function array_keys;
use function count;
use function in_array;
use function json_encode;
use function preg_match;
use function preg_quote;
use function preg_replace;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Clause;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Type\Reconciler;
use Psalm\Type\Union;
use function strpos;
use function strtolower;

class Context
{
    /**
     * @var array<string, Type\Union>
     */
    public $vars_in_scope = [];

    /**
     * @var array<string, bool>
     */
    public $vars_possibly_in_scope = [];

    /**
     * Whether or not we're inside the conditional of an if/where etc.
     *
     * This changes whether or not the context is cloned
     *
     * @var bool
     */
    public $inside_conditional = false;

    /**
     * Whether or not we're inside a __construct function
     *
     * @var bool
     */
    public $inside_constructor = false;

    /**
     * Whether or not we're inside an isset call
     *
     * Inside isssets Psalm is more lenient about certain things
     *
     * @var bool
     */
    public $inside_isset = false;

    /**
     * Whether or not we're inside an unset call, where
     * we don't care about possibly undefined variables
     *
     * @var bool
     */
    public $inside_unset = false;

    /**
     * Whether or not we're inside an class_exists call, where
     * we don't care about possibly undefined classes
     *
     * @var bool
     */
    public $inside_class_exists = false;

    /**
     * Whether or not we're inside a function/method call
     *
     * @var bool
     */
    public $inside_call = false;

    /**
     * @var null|CodeLocation
     */
    public $include_location = null;

    /**
     * @var string|null
     */
    public $self;

    /**
     * @var string|null
     */
    public $parent;

    /**
     * @var bool
     */
    public $check_classes = true;

    /**
     * @var bool
     */
    public $check_variables = true;

    /**
     * @var bool
     */
    public $check_methods = true;

    /**
     * @var bool
     */
    public $check_consts = true;

    /**
     * @var bool
     */
    public $check_functions = true;

    /**
     * A list of classes checked with class_exists
     *
     * @var array<string,bool>
     */
    public $phantom_classes = [];

    /**
     * A list of files checked with file_exists
     *
     * @var array<string,bool>
     */
    public $phantom_files = [];

    /**
     * A list of clauses in Conjunctive Normal Form
     *
     * @var array<int, Clause>
     */
    public $clauses = [];

    /**
     * Whether or not to do a deep analysis and collect mutations to this context
     *
     * @var bool
     */
    public $collect_mutations = false;

    /**
     * Whether or not to do a deep analysis and collect initializations from private methods
     *
     * @var bool
     */
    public $collect_initializations = false;

    /**
     * Stored to prevent re-analysing methods when checking for initialised properties
     *
     * @var array<string, bool>|null
     */
    public $initialized_methods = null;

    /**
     * @var array<string, Type\Union>
     */
    public $constants = [];

    /**
     * Whether or not to track how many times a variable is used
     *
     * @var bool
     */
    public $collect_references = false;

    /**
     * Whether or not to track exceptions
     *
     * @var bool
     */
    public $collect_exceptions = false;

    /**
     * A list of variables that have been referenced
     *
     * @var array<string, bool>
     */
    public $referenced_var_ids = [];

    /**
     * A list of variables that have never been referenced
     *
     * @var array<string, array<string, CodeLocation>>
     */
    public $unreferenced_vars = [];

    /**
     * A list of variables that have been passed by reference (where we know their type)
     *
     * @var array<string, \Psalm\Internal\ReferenceConstraint>|null
     */
    public $byref_constraints;

    /**
     * If this context inherits from a context, it is here
     *
     * @var Context|null
     */
    public $parent_context;

    /**
     * @var array<string, Type\Union>
     */
    public $possible_param_types = [];

    /**
     * A list of vars that have been assigned to
     *
     * @var array<string, bool>
     */
    public $assigned_var_ids = [];

    /**
     * A list of vars that have been may have been assigned to
     *
     * @var array<string, bool>
     */
    public $possibly_assigned_var_ids = [];

    /**
     * A list of classes or interfaces that may have been thrown
     *
     * @var array<string, array<array-key, CodeLocation>>
     */
    public $possibly_thrown_exceptions = [];

    /**
     * @var bool
     */
    public $is_global = false;

    /**
     * @var array<string, bool>
     */
    public $protected_var_ids = [];

    /**
     * If we've branched from the main scope, a byte offset for where that branch happened
     *
     * @var int|null
     */
    public $branch_point;

    /**
     * If we're inside case statements we allow continue; statements as an alias of break;
     *
     * @var bool
     */
    public $inside_case = false;

    /**
     * @var bool
     */
    public $inside_loop = false;

    /**
     * @var Internal\Scope\LoopScope|null
     */
    public $loop_scope = null;

    /**
     * @var Internal\Scope\CaseScope|null
     */
    public $case_scope = null;

    /**
     * @var bool
     */
    public $strict_types = false;

    /**
     * @var string|null
     */
    public $calling_method_id;

    /**
     * @var bool
     */
    public $inside_negation = false;

    /**
     * @var bool
     */
    public $ignore_variable_property = false;

    /**
     * @var bool
     */
    public $ignore_variable_method = false;

    /**
     * @param string|null $self
     */
    public function __construct($self = null)
    {
        $this->self = $self;
    }

    public function __destruct()
    {
        $this->case_scope = null;
        $this->parent_context = null;
    }

    /**
     * @return void
     */
    public function __clone()
    {
        foreach ($this->clauses as &$clause) {
            $clause = clone $clause;
        }

        foreach ($this->constants as &$constant) {
            $constant = clone $constant;
        }
    }

    /**
     * Updates the parent context, looking at the changes within a block and then applying those changes, where
     * necessary, to the parent context
     *
     * @param  Context     $start_context
     * @param  Context     $end_context
     * @param  bool        $has_leaving_statements   whether or not the parent scope is abandoned between
     *                                               $start_context and $end_context
     * @param  array       $vars_to_update
     * @param  array       $updated_vars
     *
     * @return void
     */
    public function update(
        Context $start_context,
        Context $end_context,
        $has_leaving_statements,
        array $vars_to_update,
        array &$updated_vars
    ) {
        foreach ($start_context->vars_in_scope as $var_id => $old_type) {
            // this is only true if there was some sort of type negation
            if (in_array($var_id, $vars_to_update, true)) {
                // if we're leaving, we're effectively deleting the possibility of the if types
                $new_type = !$has_leaving_statements && $end_context->hasVariable($var_id)
                    ? $end_context->vars_in_scope[$var_id]
                    : null;

                $existing_type = isset($this->vars_in_scope[$var_id]) ? $this->vars_in_scope[$var_id] : null;

                if (!$existing_type) {
                    if ($new_type) {
                        $this->vars_in_scope[$var_id] = clone $new_type;
                        $updated_vars[$var_id] = true;
                    }

                    continue;
                }

                $existing_type = clone $existing_type;

                // if the type changed within the block of statements, process the replacement
                // also never allow ourselves to remove all types from a union
                if ((!$new_type || !$old_type->equals($new_type))
                    && ($new_type || count($existing_type->getTypes()) > 1)
                ) {
                    $existing_type->substitute($old_type, $new_type);

                    if ($new_type && $new_type->from_docblock) {
                        $existing_type->setFromDocblock();
                    }

                    $updated_vars[$var_id] = true;
                }

                $this->vars_in_scope[$var_id] = $existing_type;
            }
        }
    }

    /**
     * @param  array<string, Type\Union> $new_vars_in_scope
     * @param  bool $include_new_vars
     *
     * @return array<string,Type\Union>
     */
    public function getRedefinedVars(array $new_vars_in_scope, $include_new_vars = false)
    {
        $redefined_vars = [];

        foreach ($this->vars_in_scope as $var_id => $this_type) {
            if (!isset($new_vars_in_scope[$var_id])) {
                if ($include_new_vars) {
                    $redefined_vars[$var_id] = $this_type;
                }
                continue;
            }

            $new_type = $new_vars_in_scope[$var_id];

            if (!$this_type->failed_reconciliation
                && !$this_type->isEmpty()
                && !$new_type->isEmpty()
                && !$this_type->equals($new_type)
            ) {
                $redefined_vars[$var_id] = $this_type;
            }
        }

        return $redefined_vars;
    }

    /**
     * @param  Context $original_context
     * @param  Context $new_context
     *
     * @return array<int, string>
     */
    public static function getNewOrUpdatedVarIds(Context $original_context, Context $new_context)
    {
        $redefined_var_ids = [];

        foreach ($new_context->vars_in_scope as $var_id => $context_type) {
            if (!isset($original_context->vars_in_scope[$var_id])
                || !$original_context->vars_in_scope[$var_id]->equals($context_type)
            ) {
                $redefined_var_ids[] = $var_id;
            }
        }

        return $redefined_var_ids;
    }

    /**
     * @param  string $remove_var_id
     *
     * @return void
     */
    public function remove($remove_var_id)
    {
        unset(
            $this->referenced_var_ids[$remove_var_id],
            $this->vars_possibly_in_scope[$remove_var_id]
        );

        if (isset($this->vars_in_scope[$remove_var_id])) {
            $existing_type = $this->vars_in_scope[$remove_var_id];
            unset($this->vars_in_scope[$remove_var_id]);

            $this->removeDescendents($remove_var_id, $existing_type);
        }
    }

    /**
     * @param  string[]             $changed_var_ids
     *
     * @return void
     */
    public function removeReconciledClauses(array $changed_var_ids)
    {
        $this->clauses = array_filter(
            $this->clauses,
            /** @return bool */
            function (Clause $c) use ($changed_var_ids) {
                if ($c->wedge) {
                    return true;
                }

                foreach ($c->possibilities as $key => $_) {
                    if (in_array($key, $changed_var_ids, true)) {
                        return false;
                    }
                }

                return true;
            }
        );
    }

    /**
     * @param  string                 $remove_var_id
     * @param  Clause[]               $clauses
     * @param  Union|null             $new_type
     * @param  StatementsAnalyzer|null $statements_analyzer
     *
     * @return array<int, Clause>
     */
    public static function filterClauses(
        $remove_var_id,
        array $clauses,
        Union $new_type = null,
        StatementsAnalyzer $statements_analyzer = null
    ) {
        $new_type_string = $new_type ? $new_type->getId() : '';

        $clauses_to_keep = [];

        foreach ($clauses as $clause) {
            \Psalm\Type\Algebra::calculateNegation($clause);

            $quoted_remove_var_id = preg_quote($remove_var_id, '/');

            foreach ($clause->possibilities as $var_id => $_) {
                if (preg_match('/' . $quoted_remove_var_id . '[\]\[\-]/', $var_id)) {
                    break 2;
                }
            }

            if (!isset($clause->possibilities[$remove_var_id]) ||
                $clause->possibilities[$remove_var_id] === [$new_type_string]
            ) {
                $clauses_to_keep[] = $clause;
            } elseif ($statements_analyzer &&
                $new_type &&
                !$new_type->hasMixed()
            ) {
                $type_changed = false;

                // if the clause contains any possibilities that would be altered
                // by the new type
                foreach ($clause->possibilities[$remove_var_id] as $type) {
                    // empty and !empty are not definitive for arrays and scalar types
                    if (($type === '!falsy' || $type === 'falsy') &&
                        ($new_type->hasArray() || $new_type->hasPossiblyNumericType())
                    ) {
                        $type_changed = true;
                        break;
                    }

                    $result_type = Reconciler::reconcileTypes(
                        $type,
                        clone $new_type,
                        null,
                        $statements_analyzer,
                        false,
                        [],
                        null,
                        [],
                        $failed_reconciliation
                    );

                    if ($result_type->getId() !== $new_type_string) {
                        $type_changed = true;
                        break;
                    }
                }

                if (!$type_changed) {
                    $clauses_to_keep[] = $clause;
                }
            }
        }

        return $clauses_to_keep;
    }

    /**
     * @param  string               $remove_var_id
     * @param  Union|null           $new_type
     * @param  null|StatementsAnalyzer   $statements_analyzer
     *
     * @return void
     */
    public function removeVarFromConflictingClauses(
        $remove_var_id,
        Union $new_type = null,
        StatementsAnalyzer $statements_analyzer = null
    ) {
        $this->clauses = self::filterClauses($remove_var_id, $this->clauses, $new_type, $statements_analyzer);

        if ($this->parent_context) {
            $this->parent_context->removeVarFromConflictingClauses($remove_var_id);
        }
    }

    /**
     * @param  string                 $remove_var_id
     * @param  \Psalm\Type\Union|null $existing_type
     * @param  \Psalm\Type\Union|null $new_type
     * @param  null|StatementsAnalyzer     $statements_analyzer
     *
     * @return void
     */
    public function removeDescendents(
        $remove_var_id,
        Union $existing_type = null,
        Union $new_type = null,
        StatementsAnalyzer $statements_analyzer = null
    ) {
        if (!$existing_type && isset($this->vars_in_scope[$remove_var_id])) {
            $existing_type = $this->vars_in_scope[$remove_var_id];
        }

        if (!$existing_type) {
            return;
        }

        if ($this->clauses) {
            $this->removeVarFromConflictingClauses(
                $remove_var_id,
                $existing_type->hasMixed()
                    || ($new_type && $existing_type->from_docblock !== $new_type->from_docblock)
                    ? null
                    : $new_type,
                $statements_analyzer
            );
        }

        $vars_to_remove = [];

        foreach ($this->vars_in_scope as $var_id => $_) {
            if (preg_match('/' . preg_quote($remove_var_id, '/') . '[\]\[\-]/', $var_id)) {
                $vars_to_remove[] = $var_id;
            }
        }

        foreach ($vars_to_remove as $var_id) {
            unset($this->vars_in_scope[$var_id]);
        }
    }

    /**
     * @return void
     */
    public function removeAllObjectVars()
    {
        $vars_to_remove = [];

        foreach ($this->vars_in_scope as $var_id => $_) {
            if (strpos($var_id, '->') !== false || strpos($var_id, '::') !== false) {
                $vars_to_remove[] = $var_id;
            }
        }

        if (!$vars_to_remove) {
            return;
        }

        foreach ($vars_to_remove as $var_id) {
            unset($this->vars_in_scope[$var_id], $this->vars_possibly_in_scope[$var_id]);
        }

        $clauses_to_keep = [];

        foreach ($this->clauses as $clause) {
            $abandon_clause = false;

            foreach (array_keys($clause->possibilities) as $key) {
                if (strpos($key, '->') !== false || strpos($key, '::') !== false) {
                    $abandon_clause = true;
                    break;
                }
            }

            if (!$abandon_clause) {
                $clauses_to_keep[] = $clause;
            }
        }

        $this->clauses = $clauses_to_keep;
    }

    /**
     * @param   Context $op_context
     *
     * @return  void
     */
    public function updateChecks(Context $op_context)
    {
        $this->check_classes = $this->check_classes && $op_context->check_classes;
        $this->check_variables = $this->check_variables && $op_context->check_variables;
        $this->check_methods = $this->check_methods && $op_context->check_methods;
        $this->check_functions = $this->check_functions && $op_context->check_functions;
        $this->check_consts = $this->check_consts && $op_context->check_consts;
    }

    /**
     * @param   string $class_name
     *
     * @return  bool
     */
    public function isPhantomClass($class_name)
    {
        return isset($this->phantom_classes[strtolower($class_name)]);
    }

    /**
     * @param  string|null  $var_name
     *
     * @return bool
     */
    public function hasVariable($var_name, StatementsAnalyzer $statements_analyzer = null)
    {
        if (!$var_name) {
            return false;
        }

        $stripped_var = preg_replace('/(->|\[).*$/', '', $var_name);

        if ($stripped_var[0] === '$' && ($stripped_var !== '$this' || $var_name !== $stripped_var)) {
            $this->referenced_var_ids[$var_name] = true;

            if (!isset($this->vars_possibly_in_scope[$var_name])
                && !isset($this->vars_in_scope[$var_name])
            ) {
                return false;
            }

            if ($this->collect_references && $statements_analyzer) {
                if (isset($this->unreferenced_vars[$var_name])) {
                    $statements_analyzer->registerVariableUses($this->unreferenced_vars[$var_name]);
                }

                unset($this->unreferenced_vars[$var_name]);
            }
        }

        return isset($this->vars_in_scope[$var_name]);
    }

    public function getScopeSummary() : string
    {
        $summary = [];
        foreach ($this->vars_possibly_in_scope as $k => $_) {
            $summary[$k] = true;
        }
        foreach ($this->vars_in_scope as $k => $v) {
            $summary[$k] = $v->getId();
        }

        return json_encode($summary);
    }

    /**
     * @return void
     */
    public function defineGlobals()
    {
        $globals = [
            '$argv' => new Type\Union([
                new Type\Atomic\TArray([Type::getInt(), Type::getString()]),
            ]),
            '$argc' => Type::getInt(),
        ];

        $config = Config::getInstance();

        foreach ($config->globals as $global_id => $type_string) {
            $globals[$global_id] = Type::parseString($type_string);
        }

        foreach ($globals as $global_id => $type) {
            $this->vars_in_scope[$global_id] = $type;
            $this->vars_possibly_in_scope[$global_id] = true;
        }
    }

    /**
     * @return void
     */
    public function mergeExceptions(Context $other_context)
    {
        foreach ($other_context->possibly_thrown_exceptions as $possibly_thrown_exception => $codelocations) {
            foreach ($codelocations as $hash => $codelocation) {
                $this->possibly_thrown_exceptions[$possibly_thrown_exception][$hash] = $codelocation;
            }
        }
    }

    /**
     * @return bool
     */
    public function isSuppressingExceptions(StatementsAnalyzer $statements_analyzer)
    {
        if (!$this->collect_exceptions) {
            return true;
        }

        $issue_type = $this->is_global ? 'UncaughtThrowInGlobalScope' : 'MissingThrowsDocblock';
        $suppressed_issues = $statements_analyzer->getSuppressedIssues();
        if (in_array($issue_type, $suppressed_issues, true)) {
            return true;
        }

        return false;
    }

    /**
     * @return void
     */
    public function mergeFunctionExceptions(
        FunctionLikeStorage $function_storage,
        CodeLocation $codelocation
    ) {
        $hash = $codelocation->getHash();
        foreach ($function_storage->throws as $possibly_thrown_exception => $_) {
            $this->possibly_thrown_exceptions[$possibly_thrown_exception][$hash] = $codelocation;
        }
    }
}
