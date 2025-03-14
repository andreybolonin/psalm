<?php
namespace Psalm\Internal\Analyzer\Statements\Expression\Call;

use PhpParser;
use Psalm\Internal\Analyzer\FunctionAnalyzer;
use Psalm\Internal\Analyzer\FunctionLikeAnalyzer;
use Psalm\Internal\Analyzer\Statements\ExpressionAnalyzer;
use Psalm\Internal\Analyzer\Statements\Expression\AssertionFinder;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Codebase\CallMap;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Internal\FileManipulation\FileManipulationBuffer;
use Psalm\Issue\DeprecatedFunction;
use Psalm\Issue\ForbiddenCode;
use Psalm\Issue\MixedFunctionCall;
use Psalm\Issue\InvalidFunctionCall;
use Psalm\Issue\NullFunctionCall;
use Psalm\Issue\PossiblyInvalidFunctionCall;
use Psalm\Issue\PossiblyNullFunctionCall;
use Psalm\IssueBuffer;
use Psalm\Storage\Assertion;
use Psalm\Type;
use Psalm\Type\Atomic\TCallableObject;
use Psalm\Type\Atomic\TCallableString;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Algebra;
use Psalm\Type\Reconciler;
use function count;
use function reset;
use function implode;
use function strtolower;
use function array_merge;
use function is_string;
use function array_map;
use function extension_loaded;
use function strpos;

/**
 * @internal
 */
class FunctionCallAnalyzer extends \Psalm\Internal\Analyzer\Statements\Expression\CallAnalyzer
{
    /**
     * @param   StatementsAnalyzer               $statements_analyzer
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     * @param   Context                         $context
     *
     * @return  false|null
     */
    public static function analyze(
        StatementsAnalyzer $statements_analyzer,
        PhpParser\Node\Expr\FuncCall $stmt,
        Context $context
    ) {
        $function = $stmt->name;

        $function_id = null;
        $function_params = null;
        $in_call_map = false;

        $is_stubbed = false;

        $function_storage = null;

        $codebase = $statements_analyzer->getCodebase();

        $code_location = new CodeLocation($statements_analyzer->getSource(), $stmt);
        $codebase_functions = $codebase->functions;
        $config = $codebase->config;
        $defined_constants = [];
        $global_variables = [];

        $function_exists = false;

        if ($stmt->name instanceof PhpParser\Node\Expr) {
            if (ExpressionAnalyzer::analyze($statements_analyzer, $stmt->name, $context) === false) {
                return;
            }

            if (isset($stmt->name->inferredType)) {
                if ($stmt->name->inferredType->isNull()) {
                    if (IssueBuffer::accepts(
                        new NullFunctionCall(
                            'Cannot call function on null value',
                            new CodeLocation($statements_analyzer->getSource(), $stmt)
                        ),
                        $statements_analyzer->getSuppressedIssues()
                    )) {
                        return false;
                    }

                    return;
                }

                if ($stmt->name->inferredType->isNullable()) {
                    if (IssueBuffer::accepts(
                        new PossiblyNullFunctionCall(
                            'Cannot call function on possibly null value',
                            new CodeLocation($statements_analyzer->getSource(), $stmt)
                        ),
                        $statements_analyzer->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }

                $invalid_function_call_types = [];
                $has_valid_function_call_type = false;

                foreach ($stmt->name->inferredType->getTypes() as $var_type_part) {
                    if ($var_type_part instanceof Type\Atomic\TFn || $var_type_part instanceof Type\Atomic\TCallable) {
                        $function_params = $var_type_part->params;

                        if (isset($stmt->inferredType) && $var_type_part->return_type) {
                            $stmt->inferredType = Type::combineUnionTypes(
                                $stmt->inferredType,
                                $var_type_part->return_type
                            );
                        } else {
                            $stmt->inferredType = $var_type_part->return_type ?: Type::getMixed();
                        }

                        $function_exists = true;
                        $has_valid_function_call_type = true;
                    } elseif ($var_type_part instanceof TMixed || $var_type_part instanceof TTemplateParam) {
                        $has_valid_function_call_type = true;

                        if (IssueBuffer::accepts(
                            new MixedFunctionCall(
                                'Cannot call function on mixed',
                                new CodeLocation($statements_analyzer->getSource(), $stmt)
                            ),
                            $statements_analyzer->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    } elseif ($var_type_part instanceof TCallableObject
                        || $var_type_part instanceof TCallableString
                    ) {
                        // this is fine
                        $has_valid_function_call_type = true;
                    } elseif (($var_type_part instanceof TNamedObject && $var_type_part->value === 'Closure')) {
                        // this is fine
                        $has_valid_function_call_type = true;
                    } elseif ($var_type_part instanceof TString
                        || $var_type_part instanceof Type\Atomic\TArray
                        || ($var_type_part instanceof Type\Atomic\ObjectLike
                            && count($var_type_part->properties) === 2)
                    ) {
                        // this is also kind of fine
                        $has_valid_function_call_type = true;
                    } elseif ($var_type_part instanceof TNull) {
                        // handled above
                    } elseif (!$var_type_part instanceof TNamedObject
                        || !$codebase->classlikes->classOrInterfaceExists($var_type_part->value)
                        || !$codebase->methods->methodExists($var_type_part->value . '::__invoke')
                    ) {
                        $invalid_function_call_types[] = (string)$var_type_part;
                    } else {
                        if (self::checkMethodArgs(
                            $var_type_part->value . '::__invoke',
                            $stmt->args,
                            $class_template_params,
                            $context,
                            new CodeLocation($statements_analyzer->getSource(), $stmt),
                            $statements_analyzer
                        ) === false) {
                            return;
                        }

                        $invokable_return_type = $codebase->methods->getMethodReturnType(
                            $var_type_part->value . '::__invoke',
                            $var_type_part->value
                        );

                        if (isset($stmt->inferredType)) {
                            $stmt->inferredType = Type::combineUnionTypes(
                                $invokable_return_type ?: Type::getMixed(),
                                $stmt->inferredType
                            );
                        } else {
                            $stmt->inferredType = $invokable_return_type ?: Type::getMixed();
                        }
                    }
                }

                if ($invalid_function_call_types) {
                    $var_type_part = reset($invalid_function_call_types);

                    if ($has_valid_function_call_type) {
                        if (IssueBuffer::accepts(
                            new PossiblyInvalidFunctionCall(
                                'Cannot treat type ' . $var_type_part . ' as callable',
                                new CodeLocation($statements_analyzer->getSource(), $stmt)
                            ),
                            $statements_analyzer->getSuppressedIssues()
                        )) {
                            return;
                        }
                    } else {
                        if (IssueBuffer::accepts(
                            new InvalidFunctionCall(
                                'Cannot treat type ' . $var_type_part . ' as callable',
                                new CodeLocation($statements_analyzer->getSource(), $stmt)
                            ),
                            $statements_analyzer->getSuppressedIssues()
                        )) {
                            return;
                        }
                    }
                }
            }

            if (!isset($stmt->inferredType)) {
                $stmt->inferredType = Type::getMixed();
            }
        } else {
            $original_function_id = implode('\\', $stmt->name->parts);

            if (!$stmt->name instanceof PhpParser\Node\Name\FullyQualified) {
                $function_id = $codebase_functions->getFullyQualifiedFunctionNameFromString(
                    $original_function_id,
                    $statements_analyzer
                );
            } else {
                $function_id = $original_function_id;
            }

            $namespaced_function_exists = $codebase_functions->functionExists(
                $statements_analyzer,
                strtolower($function_id)
            );

            if (!$context->collect_initializations
                && !$context->collect_mutations
            ) {
                ArgumentMapPopulator::recordArgumentPositions(
                    $statements_analyzer,
                    $stmt,
                    $codebase,
                    $function_id
                );
            }

            if (!$namespaced_function_exists
                && !$stmt->name instanceof PhpParser\Node\Name\FullyQualified
            ) {
                $in_call_map = CallMap::inCallMap($original_function_id);
                $is_stubbed = $codebase_functions->hasStubbedFunction($original_function_id);

                if ($is_stubbed || $in_call_map) {
                    $function_id = $original_function_id;
                }
            } else {
                $in_call_map = CallMap::inCallMap($function_id);
                $is_stubbed = $codebase_functions->hasStubbedFunction($function_id);
            }

            if ($is_stubbed || $in_call_map || $namespaced_function_exists) {
                $function_exists = true;
            }

            $is_predefined = true;

            $is_maybe_root_function = !$stmt->name instanceof PhpParser\Node\Name\FullyQualified
                && count($stmt->name->parts) === 1;

            if (!$in_call_map) {
                $predefined_functions = $config->getPredefinedFunctions();
                $is_predefined = isset($predefined_functions[strtolower($original_function_id)])
                    || isset($predefined_functions[strtolower($function_id)]);

                if ($context->check_functions) {
                    if (self::checkFunctionExists(
                        $statements_analyzer,
                        $function_id,
                        $code_location,
                        $is_maybe_root_function
                    ) === false
                    ) {
                        return;
                    }
                }
            } else {
                $function_exists = true;
            }

            if ($function_exists) {
                $function_params = null;

                if ($codebase->functions->params_provider->has($function_id)) {
                    $function_params = $codebase->functions->params_provider->getFunctionParams(
                        $statements_analyzer,
                        $function_id,
                        $stmt->args
                    );
                }

                if ($function_params === null) {
                    if (!$in_call_map || $is_stubbed) {
                        $function_storage = $codebase_functions->getStorage(
                            $statements_analyzer,
                            strtolower($function_id)
                        );

                        $function_params = $function_storage->params;

                        if (!$is_predefined) {
                            $defined_constants = $function_storage->defined_constants;
                            $global_variables = $function_storage->global_variables;
                        }
                    }

                    if ($in_call_map && !$is_stubbed) {
                        $function_callable = \Psalm\Internal\Codebase\CallMap::getCallableFromCallMapById(
                            $codebase,
                            $function_id,
                            $stmt->args
                        );

                        $function_params = $function_callable->params;
                    }
                }

                if ($codebase->store_node_types
                    && !$context->collect_initializations
                    && !$context->collect_mutations
                ) {
                    $codebase->analyzer->addNodeReference(
                        $statements_analyzer->getFilePath(),
                        $stmt->name,
                        $function_id . '()'
                    );
                }
            }
        }

        $set_inside_conditional = false;

        if ($function instanceof PhpParser\Node\Name
            && $function->parts === ['assert']
            && !$context->inside_conditional
        ) {
            $context->inside_conditional = true;
            $set_inside_conditional = true;
        }

        if (self::checkFunctionArguments(
            $statements_analyzer,
            $stmt->args,
            $function_params,
            $function_id,
            $context
        ) === false) {
            // fall through
        }

        if ($set_inside_conditional) {
            $context->inside_conditional = false;
        }

        $generic_params = null;

        if ($function_exists) {
            if ($stmt->name instanceof PhpParser\Node\Name && $function_id) {
                if (!$is_stubbed && $in_call_map) {
                    $function_callable = \Psalm\Internal\Codebase\CallMap::getCallableFromCallMapById(
                        $codebase,
                        $function_id,
                        $stmt->args
                    );

                    $function_params = $function_callable->params;
                }
            }

            // do this here to allow closure param checks
            if ($function_params !== null
                && self::checkFunctionLikeArgumentsMatch(
                    $statements_analyzer,
                    $stmt->args,
                    $function_id,
                    $function_params,
                    $function_storage,
                    null,
                    $generic_params,
                    $code_location,
                    $context
                ) === false) {
                // fall through
            }

            if ($stmt->name instanceof PhpParser\Node\Name && $function_id) {
                $stmt->inferredType = null;

                if ($codebase->functions->return_type_provider->has($function_id)) {
                    $stmt->inferredType = $codebase->functions->return_type_provider->getReturnType(
                        $statements_analyzer,
                        $function_id,
                        $stmt->args,
                        $context,
                        new CodeLocation($statements_analyzer->getSource(), $stmt->name)
                    );
                }

                if (!$stmt->inferredType) {
                    if (!$in_call_map || $is_stubbed) {
                        if ($function_storage && $function_storage->template_types) {
                            foreach ($function_storage->template_types as $template_name => $_) {
                                if (!isset($generic_params[$template_name])) {
                                    $generic_params[$template_name] = ['' => [Type::getMixed(), 0]];
                                }
                            }
                        }

                        if ($function_storage && !$context->isSuppressingExceptions($statements_analyzer)) {
                            $context->mergeFunctionExceptions(
                                $function_storage,
                                new CodeLocation($statements_analyzer->getSource(), $stmt)
                            );
                        }

                        try {
                            if ($function_storage && $function_storage->return_type) {
                                $return_type = clone $function_storage->return_type;

                                if ($generic_params && $function_storage->template_types) {
                                    $return_type->replaceTemplateTypesWithArgTypes(
                                        $generic_params
                                    );
                                }

                                $return_type_location = $function_storage->return_type_location;

                                if ($config->after_function_checks) {
                                    $file_manipulations = [];

                                    foreach ($config->after_function_checks as $plugin_fq_class_name) {
                                        $plugin_fq_class_name::afterFunctionCallAnalysis(
                                            $stmt,
                                            $function_id,
                                            $context,
                                            $statements_analyzer->getSource(),
                                            $codebase,
                                            $file_manipulations,
                                            $return_type
                                        );
                                    }

                                    if ($file_manipulations) {
                                        FileManipulationBuffer::add(
                                            $statements_analyzer->getFilePath(),
                                            $file_manipulations
                                        );
                                    }
                                }

                                /** @var Type\Union $return_type */
                                $stmt->inferredType = $return_type;
                                $return_type->by_ref = $function_storage->returns_by_ref;

                                // only check the type locally if it's defined externally
                                if ($return_type_location &&
                                    !$is_stubbed && // makes lookups or array_* functions quicker
                                    !$config->isInProjectDirs($return_type_location->file_path)
                                ) {
                                    $return_type->check(
                                        $statements_analyzer,
                                        new CodeLocation($statements_analyzer->getSource(), $stmt),
                                        $statements_analyzer->getSuppressedIssues(),
                                        $context->phantom_classes
                                    );
                                }
                            }
                        } catch (\InvalidArgumentException $e) {
                            // this can happen when the function was defined in the Config startup script
                            $stmt->inferredType = Type::getMixed();
                        }
                    } else {
                        $stmt->inferredType = FunctionAnalyzer::getReturnTypeFromCallMapWithArgs(
                            $statements_analyzer,
                            $function_id,
                            $stmt->args,
                            $context
                        );
                    }
                }
            }

            foreach ($defined_constants as $const_name => $const_type) {
                $context->constants[$const_name] = clone $const_type;
                $context->vars_in_scope[$const_name] = clone $const_type;
            }

            foreach ($global_variables as $var_id => $_) {
                $context->vars_in_scope[$var_id] = Type::getMixed();
                $context->vars_possibly_in_scope[$var_id] = true;
            }

            if ($config->use_assert_for_type &&
                $function instanceof PhpParser\Node\Name &&
                $function->parts === ['assert'] &&
                isset($stmt->args[0])
            ) {
                $assert_clauses = \Psalm\Type\Algebra::getFormula(
                    $stmt->args[0]->value,
                    $statements_analyzer->getFQCLN(),
                    $statements_analyzer,
                    $codebase
                );

                $simplified_clauses = Algebra::simplifyCNF(array_merge($context->clauses, $assert_clauses));

                $assert_type_assertions = Algebra::getTruthsFromFormula($simplified_clauses);

                if ($assert_type_assertions) {
                    $changed_vars = [];

                    // while in an and, we allow scope to boil over to support
                    // statements of the form if ($x && $x->foo())
                    $op_vars_in_scope = Reconciler::reconcileKeyedTypes(
                        $assert_type_assertions,
                        $context->vars_in_scope,
                        $changed_vars,
                        [],
                        $statements_analyzer,
                        [],
                        $context->inside_loop,
                        new CodeLocation($statements_analyzer->getSource(), $stmt)
                    );

                    foreach ($changed_vars as $changed_var) {
                        if (isset($op_vars_in_scope[$changed_var])) {
                            $op_vars_in_scope[$changed_var]->from_docblock = true;
                        }
                    }

                    $context->vars_in_scope = $op_vars_in_scope;
                }
            }
        }

        if (!$config->remember_property_assignments_after_call
            && !$in_call_map
            && !$context->collect_initializations
        ) {
            $context->removeAllObjectVars();
        }

        if ($stmt->name instanceof PhpParser\Node\Name &&
            ($stmt->name->parts === ['get_class'] || $stmt->name->parts === ['gettype']) &&
            $stmt->args
        ) {
            $var = $stmt->args[0]->value;

            if ($var instanceof PhpParser\Node\Expr\Variable
                && is_string($var->name)
            ) {
                $var_id = '$' . $var->name;

                if (isset($context->vars_in_scope[$var_id])) {
                    $atomic_type = $stmt->name->parts === ['get_class']
                        ? new Type\Atomic\GetClassT($var_id, $context->vars_in_scope[$var_id])
                        : new Type\Atomic\GetTypeT($var_id);

                    $stmt->inferredType = new Type\Union([$atomic_type]);
                }
            } elseif (isset($var->inferredType)) {
                $class_string_types = [];

                foreach ($var->inferredType->getTypes() as $class_type) {
                    if ($class_type instanceof Type\Atomic\TNamedObject) {
                        $class_string_types[] = new Type\Atomic\TClassString($class_type->value, clone $class_type);
                    }
                }

                if ($class_string_types) {
                    $stmt->inferredType = new Type\Union($class_string_types);
                }
            }
        }

        if ($codebase->store_node_types
            && !$context->collect_initializations
            && !$context->collect_mutations
            && isset($stmt->inferredType)
        ) {
            $codebase->analyzer->addNodeType(
                $statements_analyzer->getFilePath(),
                $stmt,
                (string) $stmt->inferredType
            );
        }

        if ($function_storage) {
            if ($function_storage->assertions && $stmt->name instanceof PhpParser\Node\Name) {
                self::applyAssertionsToContext(
                    $stmt->name,
                    $function_storage->assertions,
                    $stmt->args,
                    $generic_params ?: [],
                    $context,
                    $statements_analyzer
                );
            }

            if ($function_storage->if_true_assertions) {
                $stmt->ifTrueAssertions = array_map(
                    function (Assertion $assertion) use ($generic_params) : Assertion {
                        return $assertion->getUntemplatedCopy($generic_params ?: []);
                    },
                    $function_storage->if_true_assertions
                );
            }

            if ($function_storage->if_false_assertions) {
                $stmt->ifFalseAssertions = array_map(
                    function (Assertion $assertion) use ($generic_params) : Assertion {
                        return $assertion->getUntemplatedCopy($generic_params ?: []);
                    },
                    $function_storage->if_false_assertions
                );
            }

            if ($function_storage->deprecated && $function_id) {
                if (IssueBuffer::accepts(
                    new DeprecatedFunction(
                        'The function ' . $function_id . ' has been marked as deprecated',
                        $code_location,
                        $function_id
                    ),
                    $statements_analyzer->getSuppressedIssues()
                )) {
                    // continue
                }
            }
        }

        if ($function instanceof PhpParser\Node\Name) {
            $first_arg = isset($stmt->args[0]) ? $stmt->args[0] : null;

            if ($function->parts === ['method_exists']) {
                $context->check_methods = false;
            } elseif ($function->parts === ['class_exists']) {
                if ($first_arg) {
                    if ($first_arg->value instanceof PhpParser\Node\Scalar\String_) {
                        $context->phantom_classes[strtolower($first_arg->value->value)] = true;
                    } elseif ($first_arg->value instanceof PhpParser\Node\Expr\ClassConstFetch
                        && $first_arg->value->class instanceof PhpParser\Node\Name
                        && $first_arg->value->name instanceof PhpParser\Node\Identifier
                        && $first_arg->value->name->name === 'class'
                    ) {
                        $resolved_name = (string) $first_arg->value->class->getAttribute('resolvedName');

                        if (!$codebase->classlikes->classExists($resolved_name)) {
                            $context->phantom_classes[strtolower($resolved_name)] = true;
                        }
                    }
                }
            } elseif ($function->parts === ['interface_exists']) {
                if ($first_arg) {
                    if ($first_arg->value instanceof PhpParser\Node\Scalar\String_) {
                        $context->phantom_classes[strtolower($first_arg->value->value)] = true;
                    } elseif ($first_arg->value instanceof PhpParser\Node\Expr\ClassConstFetch
                        && $first_arg->value->class instanceof PhpParser\Node\Name
                        && $first_arg->value->name instanceof PhpParser\Node\Identifier
                        && $first_arg->value->name->name === 'class'
                    ) {
                        $resolved_name = (string) $first_arg->value->class->getAttribute('resolvedName');

                        if (!$codebase->classlikes->interfaceExists($resolved_name)) {
                            $context->phantom_classes[strtolower($resolved_name)] = true;
                        }
                    }
                }
            } elseif ($function->parts === ['file_exists'] && $first_arg) {
                $var_id = ExpressionAnalyzer::getArrayVarId($first_arg->value, null);

                if ($var_id) {
                    $context->phantom_files[$var_id] = true;
                }
            } elseif ($function->parts === ['extension_loaded']) {
                if ($first_arg
                    && $first_arg->value instanceof PhpParser\Node\Scalar\String_
                ) {
                    if (@extension_loaded($first_arg->value->value)) {
                        // do nothing
                    } else {
                        $context->check_classes = false;
                    }
                }
            } elseif ($function->parts === ['function_exists']) {
                $context->check_functions = false;
            } elseif ($function->parts === ['is_callable']) {
                $context->check_methods = false;
                $context->check_functions = false;
            } elseif ($function->parts === ['defined']) {
                $context->check_consts = false;
            } elseif ($function->parts === ['extract']) {
                $context->check_variables = false;
            } elseif (strtolower($function->parts[0]) === 'var_dump'
                || strtolower($function->parts[0]) === 'shell_exec') {
                if (IssueBuffer::accepts(
                    new ForbiddenCode(
                        'Unsafe ' . implode('', $function->parts),
                        new CodeLocation($statements_analyzer->getSource(), $stmt)
                    ),
                    $statements_analyzer->getSuppressedIssues()
                )) {
                    // continue
                }
            } elseif (isset($codebase->config->forbidden_functions[strtolower((string) $function)])) {
                if (IssueBuffer::accepts(
                    new ForbiddenCode(
                        'You have forbidden the use of ' . $function,
                        new CodeLocation($statements_analyzer->getSource(), $stmt)
                    ),
                    $statements_analyzer->getSuppressedIssues()
                )) {
                    // continue
                }
            } elseif ($function->parts === ['define']) {
                if ($first_arg) {
                    $fq_const_name = StatementsAnalyzer::getConstName(
                        $first_arg->value,
                        $codebase,
                        $statements_analyzer->getAliases()
                    );

                    if ($fq_const_name !== null) {
                        $second_arg = $stmt->args[1];
                        ExpressionAnalyzer::analyze($statements_analyzer, $second_arg->value, $context);

                        $statements_analyzer->setConstType(
                            $fq_const_name,
                            isset($second_arg->value->inferredType) ?
                                $second_arg->value->inferredType :
                                Type::getMixed(),
                            $context
                        );
                    }
                } else {
                    $context->check_consts = false;
                }
            } elseif ($first_arg
                && $function_id
                && strpos($function_id, 'is_') === 0
                && $function_id !== 'is_a'
            ) {
                if (isset($stmt->assertions)) {
                    $assertions = $stmt->assertions;
                } else {
                    $assertions = AssertionFinder::processFunctionCall(
                        $stmt,
                        $context->self,
                        $statements_analyzer,
                        $context->inside_negation
                    );
                }

                $changed_vars = [];

                $referenced_var_ids = array_map(
                    function (array $_) : bool {
                        return true;
                    },
                    $assertions
                );

                if ($assertions) {
                    Reconciler::reconcileKeyedTypes(
                        $assertions,
                        $context->vars_in_scope,
                        $changed_vars,
                        $referenced_var_ids,
                        $statements_analyzer,
                        [],
                        $context->inside_loop,
                        new CodeLocation($statements_analyzer->getSource(), $stmt)
                    );
                }
            }
        }

        return null;
    }
}
