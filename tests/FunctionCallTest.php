<?php
namespace Psalm\Tests;

use const DIRECTORY_SEPARATOR;

class FunctionCallTest extends TestCase
{
    use Traits\InvalidCodeAnalysisTestTrait;
    use Traits\ValidCodeAnalysisTestTrait;

    /**
     * @return iterable<string,array{string,assertions?:array<string,string>,error_levels?:string[]}>
     */
    public function providerValidCodeParse()
    {
        return [
            'arrayFilter' => [
                '<?php
                    $d = array_filter(["a" => 5, "b" => 12, "c" => null]);
                    $e = array_filter(
                        ["a" => 5, "b" => 12, "c" => null],
                        function(?int $i): bool {
                            return true;
                        }
                    );',
                'assertions' => [
                    '$d' => 'array<string, int>',
                    '$e' => 'array<string, int|null>',
                ],
            ],
            'arrayFilterAdvanced' => [
                '<?php
                    $f = array_filter(["a" => 5, "b" => 12, "c" => null], function(?int $val, string $key): bool {
                        return true;
                    }, ARRAY_FILTER_USE_BOTH);
                    $g = array_filter(["a" => 5, "b" => 12, "c" => null], function(string $val): bool {
                        return true;
                    }, ARRAY_FILTER_USE_KEY);

                    $bar = "bar";

                    $foo = [
                        $bar => function (): string {
                            return "baz";
                        },
                    ];

                    $foo = array_filter(
                        $foo,
                        function (string $key): bool {
                            return $key === "bar";
                        },
                        ARRAY_FILTER_USE_KEY
                    );',
                'assertions' => [
                    '$f' => 'array<string, int|null>',
                    '$g' => 'array<string, int|null>',
                ],
            ],
            'arrayFilterIgnoreNullable' => [
                '<?php
                    class A {
                        /**
                         * @return array<int, self|null>
                         */
                        public function getRows() : array {
                            return [new self, null];
                        }

                        public function filter() : void {
                            $arr = array_filter(
                                static::getRows(),
                                function (self $row) : bool {
                                    return is_a($row, static::class);
                                }
                            );
                        }
                    }',
                'assertions' => [],
                'error_levels' => ['PossiblyInvalidArgument'],
            ],
            'arrayFilterAllowTrim' => [
                '<?php
                    $foo = array_filter(["hello ", " "], "trim");',
            ],
            'arrayFilterAllowNull' => [
                '<?php
                    function foo() : array {
                        return array_filter(
                            array_map(
                                /** @return null */
                                function (int $arg) {
                                    return null;
                                },
                                [1, 2, 3]
                            )
                        );
                    }',
            ],
            'arrayFilterNamedFunction' => [
                '<?php
                    /**
                     * @param array<int, DateTimeImmutable|null> $a
                     * @return array<int, DateTimeImmutable>
                     */
                    function foo(array $a) : array {
                        return array_filter($a, "is_object");
                    }',
            ],
            'typedArrayWithDefault' => [
                '<?php
                    class A {}

                    /** @param array<A> $a */
                    function fooFoo(array $a = []): void {

                    }',
            ],
            'abs' => [
                '<?php
                    $a = abs(-5);
                    $b = abs(-7.5);
                    $c = $_GET["c"];
                    $c = is_numeric($c) ? abs($c) : null;',
                'assertions' => [
                    '$a' => 'int',
                    '$b' => 'float',
                    '$c' => 'numeric|null',
                ],
                'error_levels' => ['MixedAssignment', 'MixedArgument'],
            ],
            'validDocblockParamDefault' => [
                '<?php
                    /**
                     * @param  int|false $p
                     * @return void
                     */
                    function f($p = false) {}',
            ],
            'byRefNewString' => [
                '<?php
                    function fooFoo(?string &$v): void {}
                    fooFoo($a);',
            ],
            'byRefVariableFunctionExistingArray' => [
                '<?php
                    $arr = [];
                    function fooFoo(array &$v): void {}
                    $function = "fooFoo";
                    $function($arr);
                    if ($arr) {}',
            ],
            'byRefProperty' => [
                '<?php
                    class A {
                        /** @var string */
                        public $foo = "hello";
                    }

                    $a = new A();

                    function fooFoo(string &$v): void {}

                    fooFoo($a->foo);',
            ],
            'namespaced' => [
                '<?php
                    namespace A;

                    /** @return void */
                    function f(int $p) {}
                    f(5);',
            ],
            'namespacedRootFunctionCall' => [
                '<?php
                    namespace {
                        /** @return void */
                        function foo() { }
                    }
                    namespace A\B\C {
                        foo();
                    }',
            ],
            'namespacedAliasedFunctionCall' => [
                '<?php
                    namespace Aye {
                        /** @return void */
                        function foo() { }
                    }
                    namespace Bee {
                        use Aye as A;

                        A\foo();
                    }',
            ],
            'arrayKeys' => [
                '<?php
                    $a = array_keys(["a" => 1, "b" => 2]);',
                'assertions' => [
                    '$a' => 'array<int, string>',
                ],
            ],
            'arrayKeysMixed' => [
                '<?php
                    /** @var array */
                    $b = ["a" => 5];
                    $a = array_keys($b);',
                'assertions' => [
                    '$a' => 'array<int, array-key>',
                ],
                'error_levels' => ['MixedArgument'],
            ],
            'arrayValues' => [
                '<?php
                    $b = array_values(["a" => 1, "b" => 2]);',
                'assertions' => [
                    '$b' => 'array<int, int>',
                ],
            ],
            'arrayCombine' => [
                '<?php
                    $c = array_combine(["a", "b", "c"], [1, 2, 3]);',
                'assertions' => [
                    '$c' => 'array<string, int>|false',
                ],
            ],
            'arrayCombineFalse' => [
                '<?php
                    $c = array_combine(["a", "b"], [1, 2, 3]);',
                'assertions' => [
                    '$c' => 'array<string, int>|false',
                ],
            ],
            'arrayMerge' => [
                '<?php
                    $d = array_merge(["a", "b", "c"], [1, 2, 3]);',
                'assertions' => [
                    '$d' => 'array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int}',
                ],
            ],
            'arrayReverseDontPreserveKey' => [
                '<?php
                    $d = array_reverse(["a", "b", 1, "d" => 4]);',
                'assertions' => [
                    '$d' => 'non-empty-array<string|int, string|int>',
                ],
            ],
            'arrayReverseDontPreserveKeyExplicitArg' => [
                '<?php
                    $d = array_reverse(["a", "b", 1, "d" => 4], false);',
                'assertions' => [
                    '$d' => 'non-empty-array<string|int, string|int>',
                ],
            ],
            'arrayReversePreserveKey' => [
                '<?php
                    $d = array_reverse(["a", "b", 1], true);',
                'assertions' => [
                    '$d' => 'non-empty-array<int, string|int>',
                ],
            ],
            'arrayDiff' => [
                '<?php
                    $d = array_diff(["a" => 5, "b" => 12], [5]);',
                'assertions' => [
                    '$d' => 'array<string, int>',
                ],
            ],
            'arrayPopMixed' => [
                '<?php
                    /** @var mixed */
                    $b = ["a" => 5, "c" => 6];
                    $a = array_pop($b);',
                'assertions' => [
                    '$a' => 'mixed',
                    '$b' => 'mixed',
                ],
                'error_levels' => ['MixedAssignment', 'MixedArgument'],
            ],
            'arrayPopNonEmpty' => [
                '<?php
                    /** @var array<string, int> */
                    $a = ["a" => 5, "b" => 6, "c" => 7];
                    $b = 5;
                    if ($a) {
                        $b = array_pop($a);
                    }
                    $c = array_pop($a);',
                'assertions' => [
                    '$b' => 'int',
                    '$c' => 'int|null',
                ],
            ],
            'arrayPopNonEmptyAfterIsset' => [
                '<?php
                    /** @var array<string, int> */
                    $a = ["a" => 5, "b" => 6, "c" => 7];
                    $b = 5;
                    if (isset($a["a"])) {
                        $b = array_pop($a);
                    }',
                'assertions' => [
                    '$b' => 'int',
                ],
            ],
            'arrayPopNonEmptyAfterCount' => [
                '<?php
                    /** @var array<string, int> */
                    $a = ["a" => 5, "b" => 6, "c" => 7];
                    $b = 5;
                    if (count($a)) {
                        $b = array_pop($a);
                    }',
                'assertions' => [
                    '$b' => 'int',
                ],
            ],
            'noRedundantConditionAfterArrayObjectCountCheck' => [
                '<?php
                    /** @var ArrayObject<int, int> */
                    $a = [];
                    $b = 5;
                    if (count($a)) {}',
            ],
            'noRedundantConditionAfterMixedOrEmptyArrayCountCheck' => [
                '<?php
                    function foo(string $s) : void {
                        $a = json_decode($s) ?: [];
                        if (count($a)) {}
                        if (!count($a)) {}
                    }',
                'assertions' => [],
                'error_levels' => ['MixedAssignment', 'MixedArgument'],
            ],
            'objectLikeArrayAssignmentInConditional' => [
                '<?php
                    $a = [];

                    if (rand(0, 1)) {
                        $a["a"] = 5;
                    }

                    if (count($a)) {}
                    if (!count($a)) {}',
            ],
            'noRedundantConditionAfterCheckingExplodeLength' => [
                '<?php
                    /** @var string */
                    $s = "hello";
                    $segments = explode(".", $s);
                    if (count($segments) === 1) {}',
            ],
            'arrayPopNonEmptyAfterCountEqualsOne' => [
                '<?php
                    /** @var array<string, int> */
                    $a = ["a" => 5, "b" => 6, "c" => 7];
                    $b = 5;
                    if (count($a) === 1) {
                        $b = array_pop($a);
                    }',
                'assertions' => [
                    '$b' => 'int',
                ],
            ],
            'arrayPopNonEmptyAfterCountSoftEqualsOne' => [
                '<?php
                    /** @var array<string, int> */
                    $a = ["a" => 5, "b" => 6, "c" => 7];
                    $b = 5;
                    if (count($a) == 1) {
                        $b = array_pop($a);
                    }',
                'assertions' => [
                    '$b' => 'int',
                ],
            ],
            'arrayPopNonEmptyAfterCountGreaterThanOne' => [
                '<?php
                    /** @var array<string, int> */
                    $a = ["a" => 5, "b" => 6, "c" => 7];
                    $b = 5;
                    if (count($a) > 0) {
                        $b = array_pop($a);
                    }',
                'assertions' => [
                    '$b' => 'int',
                ],
            ],
            'arrayPopNonEmptyAfterCountGreaterOrEqualsOne' => [
                '<?php
                    /** @var array<string, int> */
                    $a = ["a" => 5, "b" => 6, "c" => 7];
                    $b = 5;
                    if (count($a) >= 1) {
                        $b = array_pop($a);
                    }',
                'assertions' => [
                    '$b' => 'int',
                ],
            ],
            'arrayPopNonEmptyAfterCountEqualsOneReversed' => [
                '<?php
                    /** @var array<string, int> */
                    $a = ["a" => 5, "b" => 6, "c" => 7];
                    $b = 5;
                    if (1 === count($a)) {
                        $b = array_pop($a);
                    }',
                'assertions' => [
                    '$b' => 'int',
                ],
            ],
            'arrayPopNonEmptyAfterCountSoftEqualsOneReversed' => [
                '<?php
                    /** @var array<string, int> */
                    $a = ["a" => 5, "b" => 6, "c" => 7];
                    $b = 5;
                    if (1 == count($a)) {
                        $b = array_pop($a);
                    }',
                'assertions' => [
                    '$b' => 'int',
                ],
            ],
            'arrayPopNonEmptyAfterCountGreaterThanOneReversed' => [
                '<?php
                    /** @var array<string, int> */
                    $a = ["a" => 5, "b" => 6, "c" => 7];
                    $b = 5;
                    if (0 < count($a)) {
                        $b = array_pop($a);
                    }',
                'assertions' => [
                    '$b' => 'int',
                ],
            ],
            'arrayPopNonEmptyAfterCountGreatorOrEqualToOneReversed' => [
                '<?php
                    /** @var array<string, int> */
                    $a = ["a" => 5, "b" => 6, "c" => 7];
                    $b = 5;
                    if (1 <= count($a)) {
                        $b = array_pop($a);
                    }',
                'assertions' => [
                    '$b' => 'int',
                ],
            ],
            'arrayPopNonEmptyAfterThreeAssertions' => [
                '<?php
                    class A {}
                    class B extends A {
                        /** @var array<int, string> */
                        public $arr = [];
                    }

                    /** @var array<A> */
                    $replacement_stmts = [];

                    if (!$replacement_stmts
                        || !$replacement_stmts[0] instanceof B
                        || count($replacement_stmts[0]->arr) > 1
                    ) {
                        return null;
                    }

                    $b = $replacement_stmts[0]->arr;',
                'assertions' => [
                    '$b' => 'array<int, string>',
                ],
            ],
            'arrayPopNonEmptyAfterArrayAddition' => [
                '<?php
                    /** @var array<string, int> */
                    $a = ["a" => 5, "b" => 6, "c" => 7];
                    $a["foo"] = 10;
                    $b = array_pop($a);',
                'assertions' => [
                    '$b' => 'int',
                ],
            ],
            'arrayPopNonEmptyAfterMixedArrayAddition' => [
                '<?php
                    /** @var array */
                    $a = ["a" => 5, "b" => 6, "c" => 7];
                    $a[] = "hello";
                    $b = array_pop($a);',
                'assertions' => [
                    '$b' => 'string|mixed',
                ],
                'error_levels' => [
                    'MixedAssignment',
                ],
            ],
            'countMoreThan0CanBeInverted' => [
                '<?php
                    $a = [];

                    if (rand(0, 1)) {
                        $a[] = "hello";
                    }

                    if (count($a) > 0) {
                        exit;
                    }',
                    'assertions' => [
                        '$a' => 'array<empty, empty>',
                    ],
            ],
            'uasort' => [
                '<?php
                    uasort(
                      $manifest,
                      function ($a, $b) {
                        return strcmp($a["parent"],$b["parent"]);
                      }
                    );',
                'assertions' => [],
                'error_levels' => [
                    'MixedArrayAccess',
                    'MixedArgument',
                    'MissingClosureParamType',
                    'MissingClosureReturnType',
                ],
            ],
            'byRefAfterCallable' => [
                '<?php
                    /**
                     * @param callable $callback
                     * @return void
                     */
                    function route($callback) {
                      if (!is_callable($callback)) {  }
                      $a = preg_match("", "", $b);
                      if ($b[0]) {}
                    }',
                'assertions' => [],
                'error_levels' => [
                    'MixedAssignment',
                    'MixedArrayAccess',
                    'RedundantConditionGivenDocblockType',
                ],
            ],
            'ignoreNullablePregReplace' => [
                '<?php
                    function foo(string $s): string {
                        $s = preg_replace("/hello/", "", $s);
                        if ($s === null) {
                            return "hello";
                        }
                        return $s;
                    }
                    function bar(string $s): string {
                        $s = preg_replace("/hello/", "", $s);
                        return $s;
                    }
                    function bat(string $s): ?string {
                        $s = preg_replace("/hello/", "", $s);
                        return $s;
                    }',
            ],
            'extractVarCheck' => [
                '<?php
                    function takesString(string $str): void {}

                    $foo = null;
                    $a = ["$foo" => "bar"];
                    extract($a);
                    takesString($foo);',
                'assertions' => [],
                'error_levels' => [
                    'MixedAssignment',
                    'MixedArrayAccess',
                    'MixedArgument',
                ],
            ],
            'arrayMergeObjectLike' => [
                '<?php
                  /**
                   * @param array<string, int> $a
                   * @return array<string, int>
                   */
                  function foo($a)
                  {
                    return $a;
                  }

                  $a1 = ["hi" => 3];
                  $a2 = ["bye" => 5];
                  $a3 = array_merge($a1, $a2);

                  foo($a3);',
                'assertions' => [
                    '$a3' => 'array{hi: int, bye: int}',
                ],
            ],
            'arrayRand' => [
                '<?php
                    $vars = ["x" => "a", "y" => "b"];
                    $c = array_rand($vars);
                    $d = $vars[$c];
                    $more_vars = ["a", "b"];
                    $e = array_rand($more_vars);',

                'assertions' => [
                    '$vars' => 'array{x: string, y: string}',
                    '$c' => 'string',
                    '$d' => 'string',
                    '$more_vars' => 'array{0: string, 1: string}',
                    '$e' => 'int',
                ],
            ],
            'arrayRandMultiple' => [
                '<?php
                    $vars = ["x" => "a", "y" => "b"];
                    $b = 3;
                    $c = array_rand($vars, 1);
                    $d = array_rand($vars, 2);
                    $e = array_rand($vars, 3);
                    $f = array_rand($vars, $b);',

                'assertions' => [
                    '$vars' => 'array{x: string, y: string}',
                    '$c' => 'string',
                    '$e' => 'array<int, string>',
                    '$f' => 'array<int, string>|string',
                ],
            ],
            'arrayKeysNoEmpty' => [
                '<?php
                    function expect_string(string $x): void {
                        echo $x;
                    }

                    function test(): void {
                        foreach (array_keys([]) as $key) {
                            expect_string($key);
                        }
                    }',
                'assertions' => [],
                'error_levels' => ['MixedAssignment', 'MixedArgument', 'MixedArgumentTypeCoercion'],
            ],
            'compact' => [
                '<?php
                    function test(): array {
                        return compact(["val"]);
                    }',
            ],
            'objectLikeKeyChecksAgainstGeneric' => [
                '<?php
                    /**
                     * @param array<string, string> $b
                     */
                    function a($b): string
                    {
                      return $b["a"];
                    }

                    a(["a" => "hello"]);',
            ],
            'objectLikeKeyChecksAgainstObjectLike' => [
                '<?php
                    /**
                     * @param array{a: string} $b
                     */
                    function a($b): string
                    {
                      return $b["a"];
                    }

                    a(["a" => "hello"]);',
            ],
            'getenv' => [
                '<?php
                    $a = getenv();
                    $b = getenv("some_key");',
                'assertions' => [
                    '$a' => 'array<array-key, string>',
                    '$b' => 'string|false',
                ],
            ],
            'arrayPopNotNullable' => [
                '<?php
                    function expectsInt(int $a) : void {}

                    /**
                     * @param array<array-key, array{item:int}> $list
                     */
                    function test(array $list) : void
                    {
                        while (!empty($list)) {
                            $tmp = array_pop($list);
                            expectsInt($tmp["item"]);
                        }
                    }',
            ],
            'arrayFilterWithAssert' => [
                '<?php
                    $a = array_filter(
                        [1, "hello", 6, "goodbye"],
                        function ($s): bool {
                            return is_string($s);
                        }
                    );',
                'assertions' => [
                    '$a' => 'array<int, string>',
                ],
                'error_levels' => [
                    'MissingClosureParamType',
                ],
            ],
            'arrayFilterUseKey' => [
                '<?php
                    $bar = "bar";

                    $foo = [
                        $bar => function (): string {
                            return "baz";
                        },
                    ];

                    $foo = array_filter(
                        $foo,
                        function (string $key): bool {
                            return $key === "bar";
                        },
                        ARRAY_FILTER_USE_KEY
                    );',
                'assertions' => [
                    '$foo' => 'array<string, Closure():string(baz)>',
                ],
            ],
            'ignoreFalsableCurrent' => [
                '<?php
                    /** @param string[] $arr */
                    function foo(array $arr): string {
                        return current($arr);
                    }
                    /** @param string[] $arr */
                    function bar(array $arr): string {
                        $a = current($arr);
                        if ($a === false) {
                            return "hello";
                        }
                        return $a;
                    }
                    /**
                     * @param string[] $arr
                     * @return string|false
                     */
                    function bat(array $arr) {
                        return current($arr);
                    }',
            ],
            'ignoreFalsableFileGetContents' => [
                '<?php
                    function foo(string $s): string {
                        return file_get_contents($s);
                    }
                    function bar(string $s): string {
                        $a = file_get_contents($s);
                        if ($a === false) {
                            return "hello";
                        }
                        return $a;
                    }
                    /**
                     * @return string|false
                     */
                    function bat(string $s) {
                        return file_get_contents($s);
                    }',
            ],
            'arraySumEmpty' => [
                '<?php
                    $foo = array_sum([]) + 1;',
                'assertions' => [
                    '$foo' => 'float|int',
                ],
            ],
            'arrayMapObjectLikeAndCallable' => [
                '<?php
                    /**
                     * @psalm-return array{key1:int,key2:int}
                     */
                    function foo(): array {
                        $v = ["key1"=> 1, "key2"=> "2"];
                        $r = array_map("intval", $v);
                        return $r;
                    }',
            ],
            'arrayMapObjectLikeAndClosure' => [
                '<?php
                    /**
                     * @psalm-return array{key1:int,key2:int}
                     */
                    function foo(): array {
                      $v = ["key1"=> 1, "key2"=> "2"];
                      $r = array_map(function($i) : int { return intval($i);}, $v);
                      return $r;
                    }',
                'assertions' => [],
                'error_levels' => [
                    'MissingClosureParamType',
                    'MixedTypeCoercion',
                ],
            ],
            'arrayFilterGoodArgs' => [
                '<?php
                    function fooFoo(int $i) : bool {
                      return true;
                    }

                    class A {
                        public static function barBar(int $i) : bool {
                            return true;
                        }
                    }

                    array_filter([1, 2, 3], "fooFoo");
                    array_filter([1, 2, 3], "foofoo");
                    array_filter([1, 2, 3], "FOOFOO");
                    array_filter([1, 2, 3], "A::barBar");
                    array_filter([1, 2, 3], "A::BARBAR");
                    array_filter([1, 2, 3], "A::barbar");',
            ],
            'arrayFilterIgnoreMissingClass' => [
                '<?php
                    array_filter([1, 2, 3], "A::bar");',
                'assertions' => [],
                'error_levels' => ['UndefinedClass'],
            ],
            'arrayFilterIgnoreMissingMethod' => [
                '<?php
                    class A {
                        public static function bar(int $i) : bool {
                            return true;
                        }
                    }

                    array_filter([1, 2, 3], "A::foo");',
                'assertions' => [],
                'error_levels' => ['UndefinedMethod'],
            ],
            'validCallables' => [
                '<?php
                    class A {
                        public static function b() : void {}
                    }

                    function c() : void {}

                    ["a", "b"]();
                    "A::b"();
                    "c"();',
            ],
            'arrayMapParamDefault' => [
                '<?php
                    $arr = ["a", "b"];
                    array_map("mapdef", $arr, array_fill(0, count($arr), 1));
                    function mapdef(string $_a, int $_b = 0): string {
                        return "a";
                    }',
            ],
            'noInvalidOperandForCoreFunctions' => [
                '<?php
                    function foo(string $a, string $b) : int {
                        $aTime = strtotime($a);
                        $bTime = strtotime($b);

                        return $aTime - $bTime;
                    }',
            ],
            'strposIntSecondParam' => [
                '<?php
                    function hasZeroByteOffset(string $s) : bool {
                        return strpos($s, 0) !== false;
                    }',
            ],
            'functionCallInGlobalScope' => [
                '<?php
                    $a = function() use ($argv) : void {};',
            ],
            'implodeMultiDimensionalArray' => [
                '<?php
                    $urls = array_map("implode", [["a", "b"]]);',
            ],
            'varExport' => [
                '<?php
                    $a = var_export(["a"], true);',
                'assertions' => [
                    '$a' => 'string',
                ],
            ],
            'varExportConstFetch' => [
                '<?php
                    class Foo {
                        const BOOL_VAR_EXPORT_RETURN = true;

                        /**
                         * @param mixed $mixed
                         */
                        public static function Baz($mixed) : string {
                            return var_export($mixed, self::BOOL_VAR_EXPORT_RETURN);
                        }
                    }',
            ],
            'key' => [
                '<?php
                    $a = ["one" => 1, "two" => 3];
                    $b = key($a);
                    $c = $a[$b];',
                'assertions' => [
                    '$b' => 'null|string',
                    '$c' => 'int',
                ],
            ],
            'explodeWithPossiblyFalse' => [
                '<?php
                    /** @return array<int, string> */
                    function exploder(string $s) : array {
                        return explode(" ", $s);
                    }',
            ],
            'allowPossiblyUndefinedClassInClassExists' => [
                '<?php
                    if (class_exists(Foo::class)) {}',
            ],
            'allowConstructorAfterClassExists' => [
                '<?php
                    function foo(string $s) : void {
                        if (class_exists($s)) {
                            new $s();
                        }
                    }',
                'assertions' => [],
                'error_levels' => ['MixedMethodCall'],
            ],
            'next' => [
                '<?php
                    $arr = ["one", "two", "three"];
                    $n = next($arr);',
                'assertions' => [
                    '$n' => 'string|false',
                ],
            ],
            'iteratorToArray' => [
                '<?php
                    /**
                     * @return Generator<stdClass>
                     */
                    function generator(): Generator {
                        yield new stdClass;
                    }

                    $a = iterator_to_array(generator());',
                'assertions' => [
                    '$a' => 'array<mixed, stdClass>',
                ],
            ],
            'iteratorToArrayWithGetIterator' => [
                '<?php
                    class C implements IteratorAggregate {
                        /**
                         * @return Traversable<int,string>
                         */
                        public function getIterator() {
                            yield 1 => "1";
                        }
                    }
                    $a = iterator_to_array(new C);',
                'assertions' => [
                    '$a' => 'array<int, string>',
                ],
            ],
            'arrayColumnInference' => [
                '<?php
                    function makeMixedArray(): array { return []; }
                    /** @return array<array<int,bool>> */
                    function makeGenericArray(): array { return []; }
                    /** @return array<array{0:string}> */
                    function makeShapeArray(): array { return []; }
                    /** @return array<array{0:string}|int> */
                    function makeUnionArray(): array { return []; }
                    $a = array_column([[1], [2], [3]], 0);
                    $b = array_column([["a" => 1], ["a" => 2], ["a" => 3]], "a");
                    $c = array_column([["k" => "a", "v" => 1], ["k" => "b", "v" => 2]], "v", "k");
                    $d = array_column([], 0);
                    $e = array_column(makeMixedArray(), 0);
                    $f = array_column(makeGenericArray(), 0);
                    $g = array_column(makeShapeArray(), 0);
                    $h = array_column(makeUnionArray(), 0);
                ',
                'assertions' => [
                    '$a' => 'array<array-key, int>',
                    '$b' => 'array<array-key, int>',
                    '$c' => 'array<string, int>',
                    '$d' => 'array<array-key, mixed>',
                    '$e' => 'array<array-key, mixed>',
                    '$f' => 'array<array-key, mixed>',
                    '$g' => 'array<array-key, string>',
                    '$h' => 'array<array-key, mixed>',
                ],
            ],
            'strtrWithPossiblyFalseFirstArg' => [
                '<?php
                    /**
                     * @param string|false $str
                     * @param array<string, string> $replace_pairs
                     * @return string
                     */
                    function strtr_wrapper($str, array $replace_pairs) {
                        /** @psalm-suppress PossiblyFalseArgument */
                        return strtr($str, $replace_pairs);
                    }',
            ],
            'splatArrayIntersect' => [
                '<?php
                    $foo = [
                        [1, 2, 3],
                        [1, 2],
                    ];

                    $bar = array_intersect(... $foo);',
                'assertions' => [
                    '$bar' => 'array<int, int>',
                ],
            ],
            'arrayReduce' => [
                '<?php
                    $arr = [2, 3, 4, 5];

                    function multiply (int $carry, int $item) : int {
                        return $carry * $item;
                    }

                    $f2 = function (int $carry, int $item) : int {
                        return $carry * $item;
                    };

                    $direct_closure_result = array_reduce(
                        $arr,
                        function (int $carry, int $item) : int {
                            return $carry * $item;
                        },
                        1
                    );

                    $passed_closure_result = array_reduce(
                        $arr,
                        $f2,
                        1
                    );

                    $function_call_result = array_reduce(
                        $arr,
                        "multiply",
                        1
                    );',
                'assertions' => [
                    '$direct_closure_result' => 'int',
                    '$passed_closure_result' => 'int',
                    '$function_call_result' => 'int',
                ],
            ],
            'arrayReduceMixedReturn' => [
                '<?php
                    $arr = [2, 3, 4, 5];

                    $direct_closure_result = array_reduce(
                        $arr,
                        function (int $carry, int $item) {
                            return $_GET["boo"];
                        },
                        1
                    );',
                'assertions' => [],
                'error_levels' => ['MissingClosureReturnType', 'MixedAssignment'],
            ],
            'versionCompare' => [
                '<?php
                    function getString() : string {
                        return rand(0, 1) ? "===" : "==";
                    }

                    $a = version_compare("5.0.0", "7.0.0");
                    $b = version_compare("5.0.0", "7.0.0", "==");
                    $c = version_compare("5.0.0", "7.0.0", getString());
                ',
                'assertions' => [
                    '$a' => 'int',
                    '$b' => 'bool',
                    '$c' => 'bool|null',
                ],
            ],
            'getTimeOfDay' => [
                '<?php
                    $a = gettimeofday(true) - gettimeofday(true);
                    $b = gettimeofday();
                    $c = gettimeofday(false);',
                'assertions' => [
                    '$a' => 'float',
                    '$b' => 'array<string, int>',
                    '$c' => 'array<string, int>',
                ],
            ],
            'parseUrlArray' => [
                '<?php
                    function foo(string $s) : string {
                        return parse_url($s)["host"] ?? "";
                    }

                    function bar(string $s) : string {
                        $parsed = parse_url($s);

                        return $parsed["host"];
                    }

                    function baz(string $s) : string {
                        $parsed = parse_url($s);

                        return $parsed["host"];
                    }

                    function bag(string $s) : string {
                        $parsed = parse_url($s);

                        if (is_string($parsed["host"] ?? false)) {
                            return $parsed["host"];
                        }

                        return "";
                    }


                    function hereisanotherone(string $s) : string {
                        $parsed = parse_url($s);

                        if (isset($parsed["host"]) && is_string($parsed["host"])) {
                            return $parsed["host"];
                        }

                        return "";
                    }

                    function hereisthelastone(string $s) : string {
                        $parsed = parse_url($s);

                        if (isset($parsed["host"]) && is_string($parsed["host"])) {
                            return $parsed["host"];
                        }

                        return "";
                    }

                    function portisint(string $s) : int {
                        $parsed = parse_url($s);

                        if (isset($parsed["port"])) {
                            return $parsed["port"];
                        }

                        return 80;
                    }

                    function portismaybeint(string $s) : ? int {
                        $parsed = parse_url($s);

                        return $parsed["port"] ?? null;
                    }

                    $porta = parse_url("", PHP_URL_PORT);
                    $porte = parse_url("localhost:443", PHP_URL_PORT);',
                'assertions' => [
                    '$porta' => 'int|null',
                    '$porte' => 'int|null',
                ],
                'error_levels' => ['MixedReturnStatement', 'MixedInferredReturnType'],
            ],
            'parseUrlComponent' => [
                '<?php
                    function foo(string $s) : string {
                        return parse_url($s, PHP_URL_HOST) ?? "";
                    }

                    function bar(string $s) : string {
                        return parse_url($s, PHP_URL_HOST);
                    }

                    function bag(string $s) : string {
                        $host = parse_url($s, PHP_URL_HOST);

                        if (is_string($host)) {
                            return $host;
                        }

                        return "";
                    }',
            ],
            'triggerUserError' => [
                '<?php
                    function mightLeave() : string {
                        if (rand(0, 1)) {
                            trigger_error("bad", E_USER_ERROR);
                        } else {
                            return "here";
                        }
                    }',
            ],
            'getParentClass' => [
                '<?php
                    class A {}
                    class B extends A {}

                    $b = get_parent_class(new A());
                    if ($b === false) {}
                    $c = new $b();',
                'assertions' => [],
                'error_levels' => ['MixedMethodCall'],
            ],
            'arraySplice' => [
                '<?php
                    $a = [1, 2, 3];
                    $c = $a;
                    $b = ["a", "b", "c"];
                    array_splice($a, -1, 1, $b);
                    $d = [1, 2, 3];
                    array_splice($d, -1, 1);',
                'assertions' => [
                    '$a' => 'non-empty-array<int, string|int>',
                    '$b' => 'array{0: string, 1: string, 2: string}',
                    '$c' => 'array{0: int, 1: int, 2: int}',
                ],
            ],
            'arraySpliceOtherType' => [
                '<?php
                    $d = [["red"], ["green"], ["blue"]];
                    array_splice($d, -1, 1, "foo");',
                'assertions' => [
                    '$d' => 'array<int, array{0: string}|string>',
                ],
            ],
            'ksortPreserveShape' => [
                '<?php
                    $a = ["a" => 3, "b" => 4];
                    ksort($a);
                    acceptsAShape($a);

                    /**
                     * @param array{a:int,b:int} $a
                     */
                    function acceptsAShape(array $a): void {}',
            ],
            'suppressError' => [
                '<?php
                    $a = @file_get_contents("foo");',
                'assertions' => [
                    '$a' => 'string|false',
                ],
            ],
            'arraySlicePreserveKeys' => [
                '<?php
                    $a = ["a" => 1, "b" => 2, "c" => 3];
                    $b = array_slice($a, 1, 2, true);
                    $c = array_slice($a, 1, 2, false);
                    $d = array_slice($a, 1, 2);',
                'assertions' => [
                    '$b' => 'non-empty-array<string, int>',
                    '$c' => 'array<int, int>',
                    '$d' => 'array<int, int>',
                ],
            ],
            'printrOutput' => [
                '<?php
                    function foo(string $s) : void {
                        echo $s;
                    }

                    foo(print_r(1, true));',
            ],
            'microtime' => [
                '<?php
                    $a = microtime(true);
                    $b = microtime();
                    /** @psalm-suppress InvalidScalarArgument */
                    $c = microtime(1);
                    $d = microtime(false);',
                'assertions' => [
                    '$a' => 'float',
                    '$b' => 'string',
                    '$c' => 'float|string',
                    '$d' => 'string',
                ],
            ],
            'filterVar' => [
                '<?php
                    function filterInt(string $s) : int {
                        $filtered = filter_var($s, FILTER_VALIDATE_INT);
                        if ($filtered === false) {
                            return 0;
                        }
                        return $filtered;
                    }
                    function filterNullableInt(string $s) : ?int {
                        return filter_var($s, FILTER_VALIDATE_INT, ["options" => ["default" => null]]);
                    }
                    function filterIntWithDefault(string $s) : int {
                        return filter_var($s, FILTER_VALIDATE_INT, ["options" => ["default" => 5]]);
                    }
                    function filterBool(string $s) : bool {
                        return filter_var($s, FILTER_VALIDATE_BOOLEAN);
                    }
                    function filterNullableBool(string $s) : ?bool {
                        return filter_var($s, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    }
                    function filterNullableBoolWithFlagsArray(string $s) : ?bool {
                        return filter_var($s, FILTER_VALIDATE_BOOLEAN, ["flags" => FILTER_NULL_ON_FAILURE]);
                    }
                    function filterFloat(string $s) : float {
                        $filtered = filter_var($s, FILTER_VALIDATE_FLOAT);
                        if ($filtered === false) {
                            return 0.0;
                        }
                        return $filtered;
                    }
                    function filterFloatWithDefault(string $s) : float {
                        return filter_var($s, FILTER_VALIDATE_FLOAT, ["options" => ["default" => 5.0]]);
                    }',
            ],
            'callVariableVar' => [
                '<?php
                    class Foo
                    {
                        public static function someInt(): int
                        {
                            return 1;
                        }
                    }

                    /**
                     * @return int
                     */
                    function makeInt()
                    {
                        $fooClass = Foo::class;
                        return $fooClass::someInt();
                    }',
            ],
            'expectsIterable' => [
                '<?php
                    function foo(iterable $i) : void {}
                    function bar(array $a) : void {
                        foo($a);
                    }',
            ],
            'getTypeHasValues' => [
                '<?php
                    /**
                     * @param mixed $maybe
                     */
                    function matchesTypes($maybe) : void {
                        $t = gettype($maybe);
                        if ($t === "object") {}
                    }',
            ],
            'functionResolutionInNamespace' => [
                '<?php
                    namespace Foo;
                    function sort(int $_) : void {}
                    sort(5);',
            ],
            'rangeWithIntStep' => [
                '<?php

                    function foo(int $bar) : string {
                        return (string) $bar;
                    }

                    foreach (range(1, 10, 1) as $x) {
                        foo($x);
                    }',
            ],
            'rangeWithNoStep' => [
                '<?php

                    function foo(int $bar) : string {
                        return (string) $bar;
                    }

                    foreach (range(1, 10) as $x) {
                        foo($x);
                    }',
            ],
            'rangeWithNoStepAndString' => [
                '<?php

                    function foo(string $bar) : void {}

                    foreach (range("a", "z") as $x) {
                        foo($x);
                    }',
            ],
            'rangeWithFloatStep' => [
                '<?php

                    function foo(float $bar) : string {
                        return (string) $bar;
                    }

                    foreach (range(1, 10, .3) as $x) {
                        foo($x);
                    }',
            ],
            'rangeWithFloatStart' => [
                '<?php

                    function foo(float $bar) : string {
                        return (string) $bar;
                    }

                    foreach (range(1.5, 10) as $x) {
                        foo($x);
                    }',
            ],
            'duplicateNamespacedFunction' => [
                '<?php
                    namespace Bar;

                    function sort() : void {}',
            ],
            'arrayMapAfterFunctionMissingFile' => [
                '<?php
                    require_once(FOO);
                    $urls = array_map("strval", [1, 2, 3]);',
                [],
                'error_levels' => ['UndefinedConstant', 'UnresolvableInclude'],
            ],
            'noNamespaceClash' => [
                '<?php
                    namespace FunctionNamespace {
                        function foo() : void {}
                    }

                    namespace ClassNamespace {
                        class Foo {}
                    }

                    namespace {
                        use ClassNamespace\Foo;
                        use function FunctionNamespace\foo;

                        new Foo();

                        foo();
                    }',
            ],
            'round' => [
                '<?php
                    $a = round(4.6);
                    $b = round(3.6, 0);
                    $c = round(3.0, 1);
                    $d = round(3.1, 2);

                    /** @var int */
                    $sig = 1;
                    $e = round(3.1, $sig);',
                'assertions' => [
                    '$a' => 'int',
                    '$b' => 'int',
                    '$c' => 'float',
                    '$d' => 'float',
                    '$e' => 'int|float',
                ],
            ],
            'hashInit70' => [
                '<?php
                    $h = hash_init("sha256");',
                [
                    '$h' => 'resource',
                ],
                [],
                '7.1',
            ],
            'hashInit71' => [
                '<?php
                    $h = hash_init("sha256");',
                [
                    '$h' => 'resource',
                ],
                [],
                '7.1',
            ],
            'hashInit72' => [
                '<?php
                    $h = hash_init("sha256");',
                [
                    '$h' => 'HashContext',
                ],
                [],
                '7.2',
            ],
            'hashInit73' => [
                '<?php
                    $h = hash_init("sha256");',
                [
                    '$h' => 'HashContext',
                ],
                [],
                '7.3',
            ],
            'nullableByRef' => [
                '<?php
                    function foo(?string &$s) : void {}

                    function bar() : void {
                        foo($bar);
                    }',
            ],
            'getClassNewInstance' => [
                '<?php
                    interface I {}
                    class C implements I {}

                    class Props {
                        /** @var class-string<I>[] */
                        public $arr = [];
                    }

                    (new Props)->arr[] = get_class(new C);',
            ],
            'getClassVariable' => [
                '<?php
                    interface I {}
                    class C implements I {}
                    $c_instance = new C;

                    class Props {
                        /** @var class-string<I>[] */
                        public $arr = [];
                    }

                    (new Props)->arr[] = get_class($c_instance);',
            ],
            'getClassAnonymousNewInstance' => [
                '<?php
                    interface I {}

                    class Props {
                        /** @var class-string<I>[] */
                        public $arr = [];
                    }

                    (new Props)->arr[] = get_class(new class implements I{});',
            ],
            'getClassAnonymousVariable' => [
                '<?php
                    interface I {}
                    $anon_instance = new class implements I {};

                    class Props {
                        /** @var class-string<I>[] */
                        public $arr = [];
                    }

                    (new Props)->arr[] = get_class($anon_instance);',
            ],
            'arrayReversePreserveNonEmptiness' => [
                '<?php
                    /** @param string[] $arr */
                    function getOrderings(array $arr): int {
                        if ($arr) {
                            $next = null;
                            foreach (array_reverse($arr) as $v) {
                                $next = 1;
                            }
                            return $next;
                        }

                        return 2;
                    }',
            ],
            'mktime' => [
                '<?php
                    /** @psalm-suppress InvalidScalarArgument */
                    $a = mktime("foo");
                    /** @psalm-suppress MixedArgument */
                    $b = mktime($_GET["foo"]);
                    $c = mktime(1, 2, 3);',
                'assertions' => [
                    '$a' => 'int|false',
                    '$b' => 'int|false',
                    '$c' => 'int',
                ],
            ],
            'PHP73-hrtime' => [
                '<?php
                    $a = hrtime(true);
                    $b = hrtime();
                    /** @psalm-suppress InvalidArgument */
                    $c = hrtime(1);
                    $d = hrtime(false);',
                'assertions' => [
                    '$a' => 'int',
                    '$b' => 'array{0: int, 1: int}',
                    '$c' => 'array{0: int, 1: int}|int',
                    '$d' => 'array{0: int, 1: int}',
                ],
            ],
            'PHP73-hrtimeCanBeFloat' => [
                '<?php
                    $a = hrtime(true);

                    if (is_int($a)) {}
                    if (is_float($a)) {}',
            ],
            'min' => [
                '<?php
                    $a = min(0, 1);
                    $b = min([0, 1]);
                    $c = min("a", "b");
                    $d = min(1, 2, 3, 4);
                    $e = min(1, 2, 3, 4, 5);
                    $f = min(...[1, 2, 3]);',
                'assertions' => [
                    '$a' => 'int',
                    '$b' => 'int',
                    '$c' => 'string',
                    '$d' => 'int',
                    '$e' => 'int',
                    '$f' => 'int',
                ],
            ],
            'minUnpackedArg' => [
                '<?php
                    $f = min(...[1, 2, 3]);',
                'assertions' => [
                    '$f' => 'int',
                ],
            ],
            'sscanf' => [
                '<?php
                    sscanf("10:05:03", "%d:%d:%d", $hours, $minutes, $seconds);',
                'assertions' => [
                    '$hours' => 'string|int|float',
                    '$minutes' => 'string|int|float',
                    '$seconds' => 'string|int|float',
                ],
            ],
            'inferArrayMapReturnType' => [
                '<?php
                    /** @return array<string> */
                    function Foo(DateTime ...$dateTimes) : array {
                        return array_map(
                            function ($dateTime) {
                                return (string) ($dateTime->format("c"));
                            },
                            $dateTimes
                        );
                    }',
            ],
            'noImplicitAssignmentToStringFromMixedWithDocblockTypes' => [
                '<?php
                    /** @param string $s */
                    function takesString($s) : void {}
                    function takesInt(int $i) : void {}

                    /**
                     * @param mixed $s
                     * @psalm-suppress MixedArgument
                     */
                    function bar($s) : void {
                        takesString($s);
                        takesInt($s);
                    }',
            ],
            'ignoreNullableIssuesAfterMixedCoercion' => [
                '<?php
                    function takesNullableString(?string $s) : void {}
                    function takesString(string $s) : void {}

                    /**
                     * @param mixed $s
                     * @psalm-suppress MixedArgument
                     */
                    function bar($s) : void {
                        takesNullableString($s);
                        takesString($s);
                    }',
            ],
            'countableSimpleXmlElement' => [
                '<?php
                    $xml = new SimpleXMLElement("<?xml version=\"1.0\"?><a><b></b><b></b></a>");
                    echo count($xml);',
            ],
            'refineWithTraitExists' => [
                '<?php
                    function foo(string $s) : void {
                        if (trait_exists($s)) {
                            new ReflectionClass($s);
                        }
                    }',
            ],
            'refineWithClassExistsOrTraitExists' => [
                '<?php
                    function foo(string $s) : void {
                        if (trait_exists($s) || class_exists($s)) {
                            new ReflectionClass($s);
                        }
                    }

                    function bar(string $s) : void {
                        if (class_exists($s) || trait_exists($s)) {
                            new ReflectionClass($s);
                        }
                    }

                    function baz(string $s) : void {
                        if (class_exists($s) || interface_exists($s) || trait_exists($s)) {
                            new ReflectionClass($s);
                        }
                    }',
            ],
            'minSingleArg' => [
                '<?php
                    /** @psalm-suppress TooFewArguments */
                    min(0);',
            ],
            'PHP73-allowIsCountableToInformType' => [
                '<?php
                    function getObject() : iterable{
                       return [];
                    }

                    $iterableObject = getObject();

                    if (is_countable($iterableObject)) {
                       if (count($iterableObject) === 0) {}
                    }',
            ],
            'versionCompareAsCallable' => [
                '<?php
                    $a = ["1.0", "2.0"];
                    uksort($a, "version_compare");',
            ],
            'coerceToObjectAfterBeingCalled' => [
                '<?php
                    class Foo {
                        public function bar() : void {}
                    }

                    function takesFoo(Foo $foo) : void {}

                    /** @param mixed $f */
                    function takesMixed($f) : void {
                        if (rand(0, 1)) {
                            $f = new Foo();
                        }
                        /** @psalm-suppress MixedArgument */
                        takesFoo($f);
                        $f->bar();
                    }',
            ],
            'functionExists' => [
                '<?php
                    if (!function_exists("in_array")) {
                        function in_array($a, $b) {
                            return true;
                        }
                    }',
            ],
            'pregReplaceCallback' => [
                '<?php
                    function foo(string $s) : string {
                        return preg_replace_callback(
                            \'/<files (psalm-version="[^"]+") (?:php-version="(.+)">\n)/\',
                            /** @param array<int, string> $matches */
                            function (array $matches) : string {
                                return $matches[1];
                            },
                            $s
                        );
                    }',
            ],
        ];
    }

    /**
     * @return iterable<string,array{string,error_message:string,2?:string[],3?:bool,4?:string}>
     */
    public function providerInvalidCodeParse()
    {
        return [
            'arrayFilterWithoutTypes' => [
                '<?php
                    $e = array_filter(
                        ["a" => 5, "b" => 12, "c" => null],
                        function(?int $i) {
                            return $_GET["a"];
                        }
                    );',
                'error_message' => 'MixedArgumentTypeCoercion',
                'error_levels' => ['MissingClosureParamType', 'MissingClosureReturnType'],
            ],
            'arrayFilterUseMethodOnInferrableInt' => [
                '<?php
                    $a = array_filter([1, 2, 3, 4], function ($i) { return $i->foo(); });',
                'error_message' => 'InvalidMethodCall',
            ],
            'arrayMapUseMethodOnInferrableInt' => [
                '<?php
                    $a = array_map(function ($i) { return $i->foo(); }, [1, 2, 3, 4]);',
                'error_message' => 'InvalidMethodCall',
            ],
            'invalidScalarArgument' => [
                '<?php
                    function fooFoo(int $a): void {}
                    fooFoo("string");',
                'error_message' => 'InvalidScalarArgument',
            ],
            'invalidArgumentWithDeclareStrictTypes' => [
                '<?php declare(strict_types=1);
                    function fooFoo(int $a): void {}
                    fooFoo("string");',
                'error_message' => 'InvalidArgument',
            ],
            'builtinFunctioninvalidArgumentWithWeakTypes' => [
                '<?php
                    $s = substr(5, 4);',
                'error_message' => 'InvalidScalarArgument',
            ],
            'builtinFunctioninvalidArgumentWithDeclareStrictTypes' => [
                '<?php declare(strict_types=1);
                    $s = substr(5, 4);',
                'error_message' => 'InvalidArgument',
            ],
            'builtinFunctioninvalidArgumentWithDeclareStrictTypesInClass' => [
                '<?php declare(strict_types=1);
                    class A {
                        public function foo() : void {
                            $s = substr(5, 4);
                        }
                    }',
                'error_message' => 'InvalidArgument',
            ],
            'mixedArgument' => [
                '<?php
                    function fooFoo(int $a): void {}
                    /** @var mixed */
                    $a = "hello";
                    fooFoo($a);',
                'error_message' => 'MixedArgument',
                'error_levels' => ['MixedAssignment'],
            ],
            'nullArgument' => [
                '<?php
                    function fooFoo(int $a): void {}
                    fooFoo(null);',
                'error_message' => 'NullArgument',
            ],
            'tooFewArguments' => [
                '<?php
                    function fooFoo(int $a): void {}
                    fooFoo();',
                'error_message' => 'TooFewArguments',
            ],
            'tooManyArguments' => [
                '<?php
                    function fooFoo(int $a): void {}
                    fooFoo(5, "dfd");',
                'error_message' => 'TooManyArguments - src' . DIRECTORY_SEPARATOR . 'somefile.php:3:21 - Too many arguments for method fooFoo '
                    . '- expecting 1 but saw 2',
            ],
            'tooManyArgumentsForConstructor' => [
                '<?php
                  class A { }
                  new A("hello");',
                'error_message' => 'TooManyArguments',
            ],
            'typeCoercion' => [
                '<?php
                    class A {}
                    class B extends A{}

                    function fooFoo(B $b): void {}
                    fooFoo(new A());',
                'error_message' => 'ArgumentTypeCoercion',
            ],
            'arrayTypeCoercion' => [
                '<?php
                    class A {}
                    class B extends A{}

                    /**
                     * @param  B[]  $b
                     * @return void
                     */
                    function fooFoo(array $b) {}
                    fooFoo([new A()]);',
                'error_message' => 'ArgumentTypeCoercion',
            ],
            'duplicateParam' => [
                '<?php
                    /**
                     * @return void
                     */
                    function f($p, $p) {}',
                'error_message' => 'DuplicateParam',
                'error_levels' => ['MissingParamType'],
            ],
            'invalidParamDefault' => [
                '<?php
                    function f(int $p = false) {}',
                'error_message' => 'InvalidParamDefault',
            ],
            'invalidDocblockParamDefault' => [
                '<?php
                    /**
                     * @param  int $p
                     * @return void
                     */
                    function f($p = false) {}',
                'error_message' => 'InvalidParamDefault',
            ],
            'badByRef' => [
                '<?php
                    function fooFoo(string &$v): void {}
                    fooFoo("a");',
                'error_message' => 'InvalidPassByReference',
            ],
            'badArrayByRef' => [
                '<?php
                    function fooFoo(array &$a): void {}
                    fooFoo([1, 2, 3]);',
                'error_message' => 'InvalidPassByReference',
            ],
            'invalidArgAfterCallable' => [
                '<?php
                    /**
                     * @param callable $callback
                     * @return void
                     */
                    function route($callback) {
                      if (!is_callable($callback)) {  }
                      takes_int("string");
                    }

                    function takes_int(int $i) {}',
                'error_message' => 'InvalidScalarArgument',
                'error_levels' => [
                    'MixedAssignment',
                    'MixedArrayAccess',
                    'RedundantConditionGivenDocblockType',
                ],
            ],
            'undefinedFunctionInArrayMap' => [
                '<?php
                    array_map(
                        "undefined_function",
                        [1, 2, 3]
                    );',
                'error_message' => 'UndefinedFunction',
            ],
            'arrayMapWithNonCallableStringArray' => [
                '<?php
                    $foo = ["one", "two"];
                    array_map($foo, ["hello"]);',
                'error_message' => 'InvalidArgument',
            ],
            'arrayMapWithNonCallableIntArray' => [
                '<?php
                    $foo = [1, 2];
                    array_map($foo, ["hello"]);',
                'error_message' => 'InvalidArgument',
            ],
            'objectLikeKeyChecksAgainstDifferentGeneric' => [
                '<?php
                    /**
                     * @param array<string, int> $b
                     */
                    function a($b): int
                    {
                      return $b["a"];
                    }

                    a(["a" => "hello"]);',
                'error_message' => 'InvalidScalarArgument',
            ],
            'objectLikeKeyChecksAgainstDifferentObjectLike' => [
                '<?php
                    /**
                     * @param array{a: int} $b
                     */
                    function a($b): int
                    {
                      return $b["a"];
                    }

                    a(["a" => "hello"]);',
                'error_message' => 'InvalidArgument',
            ],
            'possiblyNullFunctionCall' => [
                '<?php
                    $a = rand(0, 1) ? function(): void {} : null;
                    $a();',
                'error_message' => 'PossiblyNullFunctionCall',
            ],
            'possiblyInvalidFunctionCall' => [
                '<?php
                    $a = rand(0, 1) ? function(): void {} : 23515;
                    $a();',
                'error_message' => 'PossiblyInvalidFunctionCall',
            ],
            'arrayFilterBadArgs' => [
                '<?php
                    function foo(int $i) : bool {
                      return true;
                    }

                    array_filter(["hello"], "foo");',
                'error_message' => 'InvalidScalarArgument',
            ],
            'arrayFilterTooFewArgs' => [
                '<?php
                    function foo(int $i, string $s) : bool {
                      return true;
                    }

                    array_filter([1, 2, 3], "foo");',
                'error_message' => 'TooFewArguments',
            ],
            'arrayMapBadArgs' => [
                '<?php
                    function foo(int $i) : bool {
                      return true;
                    }

                    array_map("foo", ["hello"]);',
                'error_message' => 'InvalidScalarArgument',
            ],
            'arrayMapTooFewArgs' => [
                '<?php
                    function foo(int $i, string $s) : bool {
                      return true;
                    }

                    array_map("foo", [1, 2, 3]);',
                'error_message' => 'TooFewArguments',
            ],
            'arrayMapTooManyArgs' => [
                '<?php
                    function foo() : bool {
                      return true;
                    }

                    array_map("foo", [1, 2, 3]);',
                'error_message' => 'TooManyArguments',
            ],
            'varExportAssignmentToVoid' => [
                '<?php
                    $a = var_export(["a"]);',
                'error_message' => 'AssignmentToVoid',
            ],
            'explodeWithEmptyString' => [
                '<?php
                    function exploder(string $s) : array {
                        return explode("", $s);
                    }',
                'error_message' => 'FalsableReturnStatement',
            ],
            'complainAboutArrayToIterable' => [
                '<?php
                    class A {}
                    class B {}
                    /**
                     * @param iterable<mixed,A> $p
                     */
                    function takesIterableOfA(iterable $p): void {}

                    takesIterableOfA([new B]); // should complain',
                'error_message' => 'InvalidArgument',
            ],
            'complainAboutArrayToIterableSingleParam' => [
                '<?php
                    class A {}
                    class B {}
                    /**
                     * @param iterable<A> $p
                     */
                    function takesIterableOfA(iterable $p): void {}

                    takesIterableOfA([new B]); // should complain',
                'error_message' => 'InvalidArgument',
            ],
            'putInvalidTypeMessagesFirst' => [
                '<?php
                    $q = rand(0,1) ? new stdClass : false;
                    strlen($q);',
                'error_message' => 'InvalidArgument',
            ],
            'arrayReduceInvalidClosureTooFewArgs' => [
                '<?php
                    $arr = [2, 3, 4, 5];

                    $direct_closure_result = array_reduce(
                        $arr,
                        function (int $carry) : int {
                            return 5;
                        },
                        1
                    );',
                'error_message' => 'InvalidArgument',
                'error_levels' => ['MixedTypeCoercion'],
            ],
            'arrayReduceInvalidItemType' => [
                '<?php
                    $arr = [2, 3, 4, 5];

                    $direct_closure_result = array_reduce(
                        $arr,
                        function (int $carry, stdClass $item) {
                            return $_GET["boo"];
                        },
                        1
                    );',
                'error_message' => 'InvalidArgument',
                'error_levels' => ['MissingClosureReturnType'],
            ],
            'arrayReduceInvalidCarryType' => [
                '<?php
                    $arr = [2, 3, 4, 5];

                    $direct_closure_result = array_reduce(
                        $arr,
                        function (stdClass $carry, int $item) {
                            return $_GET["boo"];
                        },
                        1
                    );',
                'error_message' => 'InvalidArgument',
                'error_levels' => ['MissingClosureReturnType'],
            ],
            'arrayReduceInvalidCarryOutputType' => [
                '<?php
                    $arr = [2, 3, 4, 5];

                    $direct_closure_result = array_reduce(
                        $arr,
                        function (int $carry, int $item) : stdClass {
                            return new stdClass;
                        },
                        1
                    );',
                'error_message' => 'InvalidArgument',
            ],
            'arrayPopNotNull' => [
                '<?php
                    function expectsInt(int $a) : void {}

                    /**
                     * @param array<array-key, array{item:int}> $list
                     */
                    function test(array $list) : void
                    {
                        while (!empty($list)) {
                            $tmp = array_pop($list);
                            if ($tmp === null) {}
                        }
                    }',
                'error_message' => 'DocblockTypeContradiction',
            ],
            'getTypeInvalidValue' => [
                '<?php
                    /**
                     * @param mixed $maybe
                     */
                    function matchesTypes($maybe) : void {
                        $t = gettype($maybe);
                        if ($t === "bool") {}
                    }',
                'error_message' => 'TypeDoesNotContainType',
            ],
            'rangeWithFloatStep' => [
                '<?php

                    function foo(int $bar) : string {
                        return (string) $bar;
                    }

                    foreach (range(1, 10, .3) as $x) {
                        foo($x);
                    }',
                'error_message' => 'InvalidScalarArgument',
            ],
            'rangeWithFloatStart' => [
                '<?php

                    function foo(int $bar) : string {
                        return (string) $bar;
                    }

                    foreach (range(1.4, 10) as $x) {
                        foo($x);
                    }',
                'error_message' => 'InvalidScalarArgument',
            ],
            'duplicateFunction' => [
                '<?php
                    function f() : void {}
                    function f() : void {}',
                'error_message' => 'DuplicateFunction',
            ],
            'duplicateCoreFunction' => [
                '<?php
                    function sort() : void {}',
                'error_message' => 'DuplicateFunction',
            ],
            'usortInvalidComparison' => [
                '<?php
                    $arr = [["one"], ["two"], ["three"]];

                    usort(
                        $arr,
                        function (string $a, string $b): int {
                            return strcmp($a, $b);
                        }
                    );',
                'error_message' => 'InvalidArgument',
            ],
            'usortInvalidCallableString' => [
                '<?php
                    $a = [[1], [2], [3]];
                    usort($a, "strcmp");',
                'error_message' => 'InvalidArgument',
            ],
            'functionCallOnMixed' => [
                '<?php
                    /**
                     * @var mixed $s
                     * @psalm-suppress MixedAssignment
                     */
                    $s = 1;
                    $s();',
                'error_message' => 'MixedFunctionCall',
            ],
            'iterableOfObjectCannotAcceptIterableOfInt' => [
                '<?php
                    /** @param iterable<string,object> $_p */
                    function accepts(iterable $_p): void {}

                    /** @return iterable<int,int> */
                    function iterable() { yield 1; }

                    accepts(iterable());',
                'error_message' => 'InvalidArgument',
            ],
            'iterableOfObjectCannotAcceptTraversableOfInt' => [
                '<?php
                    /** @param iterable<string,object> $_p */
                    function accepts(iterable $_p): void {}

                    /** @return Traversable<int,int> */
                    function traversable() { yield 1; }

                    accepts(traversable());',
                'error_message' => 'InvalidArgument',
            ],
            'iterableOfObjectCannotAcceptGeneratorOfInt' => [
                '<?php
                    /** @param iterable<string,object> $_p */
                    function accepts(iterable $_p): void {}

                    /** @return Generator<int,int,mixed,void> */
                    function generator() { yield 1; }

                    accepts(generator());',
                'error_message' => 'InvalidArgument',
            ],
            'iterableOfObjectCannotAcceptArrayOfInt' => [
                '<?php
                    /** @param iterable<string,object> $_p */
                    function accepts(iterable $_p): void {}

                    /** @return array<int,int> */
                    function arr() { return [1]; }

                    accepts(arr());',
                'error_message' => 'InvalidArgument',
            ],
            'nonNullableByRef' => [
                '<?php
                    function foo(string &$s) : void {}

                    function bar() : void {
                        foo($bar);
                    }',
                'error_message' => 'NullReference',
            ],
            'intCastByRef' => [
                '<?php
                    function foo(int &$i) : void {}

                    $a = rand(0, 1) ? null : 5;
                    /** @psalm-suppress MixedArgument */
                    foo((int) $a);',
                'error_message' => 'InvalidPassByReference',
            ],
            'implicitAssignmentToStringFromMixed' => [
                '<?php
                    /** @param "a"|"b" $s */
                    function takesString(string $s) : void {}
                    function takesInt(int $i) : void {}

                    /**
                     * @param mixed $s
                     * @psalm-suppress MixedArgument
                     */
                    function bar($s) : void {
                        takesString($s);
                        takesInt($s);
                    }',
                'error_message' => 'InvalidScalarArgument',
            ],
            'tooFewArgsAccurateCount' => [
                '<?php
                    preg_match(\'/adsf/\');',
                'error_message' => 'TooFewArguments - src' . DIRECTORY_SEPARATOR . 'somefile.php:2:21 - Too few arguments for method preg_match - expecting 2 but saw 1',
            ],
        ];
    }
}
