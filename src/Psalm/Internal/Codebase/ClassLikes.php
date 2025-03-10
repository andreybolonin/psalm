<?php
namespace Psalm\Internal\Codebase;

use function array_merge;
use function array_pop;
use function end;
use function explode;
use function get_declared_classes;
use function get_declared_interfaces;
use function implode;
use const PHP_EOL;
use PhpParser;
use function preg_match;
use function preg_replace;
use Psalm\Aliases;
use Psalm\CodeLocation;
use Psalm\Config;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\FileManipulation\FileManipulationBuffer;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\Internal\Provider\FileReferenceProvider;
use Psalm\Issue\PossiblyUnusedMethod;
use Psalm\Issue\PossiblyUnusedParam;
use Psalm\Issue\PossiblyUnusedProperty;
use Psalm\Issue\UnusedClass;
use Psalm\Issue\UnusedMethod;
use Psalm\Issue\UnusedProperty;
use Psalm\IssueBuffer;
use Psalm\Progress\Progress;
use Psalm\Progress\VoidProgress;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type;
use ReflectionProperty;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;

/**
 * @internal
 *
 * Handles information about classes, interfaces and traits
 */
class ClassLikes
{
    /**
     * @var ClassLikeStorageProvider
     */
    private $classlike_storage_provider;

    /**
     * @var FileReferenceProvider
     */
    public $file_reference_provider;

    /**
     * @var array<string, bool>
     */
    private $existing_classlikes_lc = [];

    /**
     * @var array<string, bool>
     */
    private $existing_classes_lc = [];

    /**
     * @var array<string, bool>
     */
    private $existing_classes = [];

    /**
     * @var array<string, bool>
     */
    private $existing_interfaces_lc = [];

    /**
     * @var array<string, bool>
     */
    private $existing_interfaces = [];

    /**
     * @var array<string, bool>
     */
    private $existing_traits_lc = [];

    /**
     * @var array<string, bool>
     */
    private $existing_traits = [];

    /**
     * @var array<string, PhpParser\Node\Stmt\Trait_>
     */
    private $trait_nodes = [];

    /**
     * @var array<string, Aliases>
     */
    private $trait_aliases = [];

    /**
     * @var array<string, string>
     */
    private $classlike_aliases = [];

    /**
     * @var bool
     */
    public $collect_references = false;

    /**
     * @var bool
     */
    public $collect_locations = false;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var Scanner
     */
    private $scanner;

    public function __construct(
        Config $config,
        ClassLikeStorageProvider $storage_provider,
        FileReferenceProvider $file_reference_provider,
        Scanner $scanner
    ) {
        $this->config = $config;
        $this->classlike_storage_provider = $storage_provider;
        $this->file_reference_provider = $file_reference_provider;
        $this->scanner = $scanner;

        $this->collectPredefinedClassLikes();
    }

    /**
     * @return void
     */
    private function collectPredefinedClassLikes()
    {
        /** @var array<int, string> */
        $predefined_classes = get_declared_classes();

        foreach ($predefined_classes as $predefined_class) {
            $predefined_class = preg_replace('/^\\\/', '', $predefined_class);
            /** @psalm-suppress TypeCoercion */
            $reflection_class = new \ReflectionClass($predefined_class);

            if (!$reflection_class->isUserDefined()) {
                $predefined_class_lc = strtolower($predefined_class);
                $this->existing_classlikes_lc[$predefined_class_lc] = true;
                $this->existing_classes_lc[$predefined_class_lc] = true;
            }
        }

        /** @var array<int, string> */
        $predefined_interfaces = get_declared_interfaces();

        foreach ($predefined_interfaces as $predefined_interface) {
            $predefined_interface = preg_replace('/^\\\/', '', $predefined_interface);
            /** @psalm-suppress TypeCoercion */
            $reflection_class = new \ReflectionClass($predefined_interface);

            if (!$reflection_class->isUserDefined()) {
                $predefined_interface_lc = strtolower($predefined_interface);
                $this->existing_classlikes_lc[$predefined_interface_lc] = true;
                $this->existing_interfaces_lc[$predefined_interface_lc] = true;
            }
        }
    }

    /**
     * @param string        $fq_class_name
     * @param string|null   $file_path
     *
     * @return void
     */
    public function addFullyQualifiedClassName($fq_class_name, $file_path = null)
    {
        $fq_class_name_lc = strtolower($fq_class_name);
        $this->existing_classlikes_lc[$fq_class_name_lc] = true;
        $this->existing_classes_lc[$fq_class_name_lc] = true;
        $this->existing_traits_lc[$fq_class_name_lc] = false;
        $this->existing_interfaces_lc[$fq_class_name_lc] = false;
        $this->existing_classes[$fq_class_name] = true;

        if ($file_path) {
            $this->scanner->setClassLikeFilePath($fq_class_name_lc, $file_path);
        }
    }

    /**
     * @param string        $fq_class_name
     * @param string|null   $file_path
     *
     * @return void
     */
    public function addFullyQualifiedInterfaceName($fq_class_name, $file_path = null)
    {
        $fq_class_name_lc = strtolower($fq_class_name);
        $this->existing_classlikes_lc[$fq_class_name_lc] = true;
        $this->existing_interfaces_lc[$fq_class_name_lc] = true;
        $this->existing_classes_lc[$fq_class_name_lc] = false;
        $this->existing_traits_lc[$fq_class_name_lc] = false;
        $this->existing_interfaces[$fq_class_name] = true;

        if ($file_path) {
            $this->scanner->setClassLikeFilePath($fq_class_name_lc, $file_path);
        }
    }

    /**
     * @param string        $fq_class_name
     * @param string|null   $file_path
     *
     * @return void
     */
    public function addFullyQualifiedTraitName($fq_class_name, $file_path = null)
    {
        $fq_class_name_lc = strtolower($fq_class_name);
        $this->existing_classlikes_lc[$fq_class_name_lc] = true;
        $this->existing_traits_lc[$fq_class_name_lc] = true;
        $this->existing_classes_lc[$fq_class_name_lc] = false;
        $this->existing_interfaces_lc[$fq_class_name_lc] = false;
        $this->existing_traits[$fq_class_name] = true;

        if ($file_path) {
            $this->scanner->setClassLikeFilePath($fq_class_name_lc, $file_path);
        }
    }

    /**
     * @param string        $fq_class_name_lc
     * @param string|null   $file_path
     *
     * @return void
     */
    public function addFullyQualifiedClassLikeName($fq_class_name_lc, $file_path = null)
    {
        $this->existing_classlikes_lc[$fq_class_name_lc] = true;

        if ($file_path) {
            $this->scanner->setClassLikeFilePath($fq_class_name_lc, $file_path);
        }
    }

    /**
     * @return string[]
     */
    public function getMatchingClassLikeNames(string $stub) : array
    {
        $matching_classes = [];

        if ($stub[0] === '*') {
            $stub = substr($stub, 1);
        }

        $stub = strtolower($stub);

        foreach ($this->existing_classlikes_lc as $fq_classlike_name_lc => $found) {
            if (!$found) {
                continue;
            }

            if (preg_match('@(^|\\\)' . $stub . '.*@', $fq_classlike_name_lc)) {
                $matching_classes[] = $fq_classlike_name_lc;
            }
        }

        return $matching_classes;
    }

    /**
     * @param string        $fq_class_name_lc
     *
     * @return bool
     */
    public function hasFullyQualifiedClassLikeName($fq_class_name_lc)
    {
        return isset($this->existing_classlikes_lc[$fq_class_name_lc]);
    }

    /**
     * @param string $fq_class_name
     *
     * @return bool
     */
    public function hasFullyQualifiedClassName($fq_class_name, CodeLocation $code_location = null)
    {
        $fq_class_name_lc = strtolower($fq_class_name);

        if (isset($this->classlike_aliases[$fq_class_name_lc])) {
            $fq_class_name_lc = strtolower($this->classlike_aliases[$fq_class_name_lc]);
        }

        if (!isset($this->existing_classes_lc[$fq_class_name_lc])
            || !$this->existing_classes_lc[$fq_class_name_lc]
            || !$this->classlike_storage_provider->has($fq_class_name_lc)
        ) {
            if ((
                !isset($this->existing_classes_lc[$fq_class_name_lc])
                    || $this->existing_classes_lc[$fq_class_name_lc] === true
                )
                && !$this->classlike_storage_provider->has($fq_class_name_lc)
            ) {
                if (!isset($this->existing_classes_lc[$fq_class_name_lc])) {
                    $this->existing_classes_lc[$fq_class_name_lc] = false;

                    return false;
                }

                return $this->existing_classes_lc[$fq_class_name_lc];
            }

            return false;
        }

        if ($this->collect_references && $code_location) {
            $this->file_reference_provider->addFileReferenceToClass(
                $code_location->file_path,
                $fq_class_name_lc
            );
        }

        if ($this->collect_locations && $code_location) {
            $this->file_reference_provider->addCallingLocationForClass(
                $code_location,
                strtolower($fq_class_name)
            );
        }

        return true;
    }

    /**
     * @param string $fq_class_name
     *
     * @return bool
     */
    public function hasFullyQualifiedInterfaceName($fq_class_name, CodeLocation $code_location = null)
    {
        $fq_class_name_lc = strtolower($fq_class_name);

        if (isset($this->classlike_aliases[$fq_class_name_lc])) {
            $fq_class_name_lc = strtolower($this->classlike_aliases[$fq_class_name_lc]);
        }

        if (!isset($this->existing_interfaces_lc[$fq_class_name_lc])
            || !$this->existing_interfaces_lc[$fq_class_name_lc]
            || !$this->classlike_storage_provider->has($fq_class_name_lc)
        ) {
            if ((
                !isset($this->existing_classes_lc[$fq_class_name_lc])
                    || $this->existing_classes_lc[$fq_class_name_lc] === true
                )
                && !$this->classlike_storage_provider->has($fq_class_name_lc)
            ) {
                if (!isset($this->existing_interfaces_lc[$fq_class_name_lc])) {
                    $this->existing_interfaces_lc[$fq_class_name_lc] = false;

                    return false;
                }

                return $this->existing_interfaces_lc[$fq_class_name_lc];
            }

            return false;
        }

        if ($this->collect_references && $code_location) {
            $this->file_reference_provider->addFileReferenceToClass(
                $code_location->file_path,
                $fq_class_name_lc
            );
        }

        if ($this->collect_locations && $code_location) {
            $this->file_reference_provider->addCallingLocationForClass(
                $code_location,
                strtolower($fq_class_name)
            );
        }

        return true;
    }

    /**
     * @param string $fq_class_name
     *
     * @return bool
     */
    public function hasFullyQualifiedTraitName($fq_class_name, CodeLocation $code_location = null)
    {
        $fq_class_name_lc = strtolower($fq_class_name);

        if (isset($this->classlike_aliases[$fq_class_name_lc])) {
            $fq_class_name_lc = strtolower($this->classlike_aliases[$fq_class_name_lc]);
        }

        if (!isset($this->existing_traits_lc[$fq_class_name_lc]) ||
            !$this->existing_traits_lc[$fq_class_name_lc]
        ) {
            return false;
        }

        if ($this->collect_references && $code_location) {
            $this->file_reference_provider->addFileReferenceToClass(
                $code_location->file_path,
                $fq_class_name_lc
            );
        }

        return true;
    }

    /**
     * Check whether a class/interface exists
     *
     * @param  string          $fq_class_name
     * @param  CodeLocation $code_location
     *
     * @return bool
     */
    public function classOrInterfaceExists(
        $fq_class_name,
        CodeLocation $code_location = null
    ) {
        if (!$this->classExists($fq_class_name, $code_location)
            && !$this->interfaceExists($fq_class_name, $code_location)
        ) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether or not a given class exists
     *
     * @param  string       $fq_class_name
     *
     * @return bool
     */
    public function classExists($fq_class_name, CodeLocation $code_location = null)
    {
        if (isset(ClassLikeAnalyzer::SPECIAL_TYPES[$fq_class_name])) {
            return false;
        }

        if ($fq_class_name === 'Generator') {
            return true;
        }

        return $this->hasFullyQualifiedClassName($fq_class_name, $code_location);
    }

    /**
     * Determine whether or not a class extends a parent
     *
     * @param  string       $fq_class_name
     * @param  string       $possible_parent
     *
     * @throws UnpopulatedClasslikeException when called on unpopulated class
     * @throws \InvalidArgumentException when class does not exist
     *
     * @return bool
     */
    public function classExtends($fq_class_name, $possible_parent, bool $from_api = false)
    {
        $fq_class_name_lc = strtolower($fq_class_name);

        if ($fq_class_name_lc === 'generator') {
            return false;
        }

        $fq_class_name_lc = $this->classlike_aliases[$fq_class_name_lc] ?? $fq_class_name_lc;

        $class_storage = $this->classlike_storage_provider->get($fq_class_name_lc);

        if ($from_api && !$class_storage->populated) {
            throw new UnpopulatedClasslikeException($fq_class_name);
        }

        return isset($class_storage->parent_classes[strtolower($possible_parent)]);
    }

    /**
     * Check whether a class implements an interface
     *
     * @param  string       $fq_class_name
     * @param  string       $interface
     *
     * @return bool
     */
    public function classImplements($fq_class_name, $interface)
    {
        $interface_id = strtolower($interface);

        $fq_class_name = strtolower($fq_class_name);

        if ($interface_id === 'callable' && $fq_class_name === 'closure') {
            return true;
        }

        if ($interface_id === 'traversable' && $fq_class_name === 'generator') {
            return true;
        }

        if ($interface_id === 'traversable' && $fq_class_name === 'iterator') {
            return true;
        }

        if (isset(ClassLikeAnalyzer::SPECIAL_TYPES[$interface_id])
            || isset(ClassLikeAnalyzer::SPECIAL_TYPES[$fq_class_name])
        ) {
            return false;
        }

        if (isset($this->classlike_aliases[$fq_class_name])) {
            $fq_class_name = $this->classlike_aliases[$fq_class_name];
        }

        $class_storage = $this->classlike_storage_provider->get($fq_class_name);

        return isset($class_storage->class_implements[$interface_id]);
    }

    /**
     * @param  string         $fq_interface_name
     *
     * @return bool
     */
    public function interfaceExists($fq_interface_name, CodeLocation $code_location = null)
    {
        if (isset(ClassLikeAnalyzer::SPECIAL_TYPES[strtolower($fq_interface_name)])) {
            return false;
        }

        return $this->hasFullyQualifiedInterfaceName($fq_interface_name, $code_location);
    }

    /**
     * @param  string         $interface_name
     * @param  string         $possible_parent
     *
     * @return bool
     */
    public function interfaceExtends($interface_name, $possible_parent)
    {
        return isset($this->getParentInterfaces($interface_name)[strtolower($possible_parent)]);
    }

    /**
     * @param  string         $fq_interface_name
     *
     * @return array<string, string>   all interfaces extended by $interface_name
     */
    public function getParentInterfaces($fq_interface_name)
    {
        $fq_interface_name = strtolower($fq_interface_name);

        $storage = $this->classlike_storage_provider->get($fq_interface_name);

        return $storage->parent_interfaces;
    }

    /**
     * @param  string         $fq_trait_name
     *
     * @return bool
     */
    public function traitExists($fq_trait_name, CodeLocation $code_location = null)
    {
        return $this->hasFullyQualifiedTraitName($fq_trait_name, $code_location);
    }

    /**
     * Determine whether or not a class has the correct casing
     *
     * @param  string $fq_class_name
     *
     * @return bool
     */
    public function classHasCorrectCasing($fq_class_name)
    {
        if ($fq_class_name === 'Generator') {
            return true;
        }

        if (isset($this->classlike_aliases[strtolower($fq_class_name)])) {
            return true;
        }

        return isset($this->existing_classes[$fq_class_name]);
    }

    /**
     * @param  string $fq_interface_name
     *
     * @return bool
     */
    public function interfaceHasCorrectCasing($fq_interface_name)
    {
        if (isset($this->classlike_aliases[strtolower($fq_interface_name)])) {
            return true;
        }

        if (isset($this->classlike_aliases[strtolower($fq_interface_name)])) {
            return true;
        }

        return isset($this->existing_interfaces[$fq_interface_name]);
    }

    /**
     * @param  string $fq_trait_name
     *
     * @return bool
     */
    public function traitHasCorrectCase($fq_trait_name)
    {
        if (isset($this->classlike_aliases[strtolower($fq_trait_name)])) {
            return true;
        }

        return isset($this->existing_traits[$fq_trait_name]);
    }

    /**
     * @param  string  $fq_class_name
     *
     * @return bool
     */
    public function isUserDefined($fq_class_name)
    {
        return $this->classlike_storage_provider->get($fq_class_name)->user_defined;
    }

    /**
     * @param  string $fq_trait_name
     *
     * @return void
     */
    public function addTraitNode($fq_trait_name, PhpParser\Node\Stmt\Trait_ $node, Aliases $aliases)
    {
        $fq_trait_name_lc = strtolower($fq_trait_name);
        $this->trait_nodes[$fq_trait_name_lc] = $node;
        $this->trait_aliases[$fq_trait_name_lc] = $aliases;
    }

    /**
     * @param  string $fq_trait_name
     *
     * @return PhpParser\Node\Stmt\Trait_
     */
    public function getTraitNode($fq_trait_name)
    {
        $fq_trait_name_lc = strtolower($fq_trait_name);

        if (isset($this->trait_nodes[$fq_trait_name_lc])) {
            return $this->trait_nodes[$fq_trait_name_lc];
        }

        throw new \UnexpectedValueException(
            'Expecting trait statements to exist for ' . $fq_trait_name
        );
    }

    /**
     * @param  string $fq_trait_name
     *
     * @return Aliases
     */
    public function getTraitAliases($fq_trait_name)
    {
        $fq_trait_name_lc = strtolower($fq_trait_name);

        if (isset($this->trait_aliases[$fq_trait_name_lc])) {
            return $this->trait_aliases[$fq_trait_name_lc];
        }

        throw new \UnexpectedValueException(
            'Expecting trait aliases to exist for ' . $fq_trait_name
        );
    }

    /**
     * @return void
     */
    public function addClassAlias(string $fq_class_name, string $alias_name)
    {
        $this->classlike_aliases[strtolower($alias_name)] = $fq_class_name;
    }

    /**
     * @return string
     */
    public function getUnAliasedName(string $alias_name)
    {
        return $this->classlike_aliases[strtolower($alias_name)] ?? $alias_name;
    }

    /**
     * @return void
     */
    public function checkClassReferences(Methods $methods, Progress $progress = null)
    {
        if ($progress === null) {
            $progress = new VoidProgress();
        }

        $progress->debug('Checking class references' . PHP_EOL);

        foreach ($this->existing_classlikes_lc as $fq_class_name_lc => $_) {
            try {
                $classlike_storage = $this->classlike_storage_provider->get($fq_class_name_lc);
            } catch (\InvalidArgumentException $e) {
                continue;
            }

            if ($classlike_storage->location
                && $this->config->isInProjectDirs($classlike_storage->location->file_path)
                && !$classlike_storage->is_trait
            ) {
                if (!$this->file_reference_provider->isClassReferenced($fq_class_name_lc)) {
                    if (IssueBuffer::accepts(
                        new UnusedClass(
                            'Class ' . $classlike_storage->name . ' is never used',
                            $classlike_storage->location,
                            $classlike_storage->name
                        ),
                        $classlike_storage->suppressed_issues
                    )) {
                        // fall through
                    }
                } else {
                    $this->checkMethodReferences($classlike_storage, $methods);
                    $this->checkPropertyReferences($classlike_storage);
                }
            }
        }
    }

    /**
     * @return void
     */
    public function moveMethods(Methods $methods, Progress $progress = null)
    {
        if ($progress === null) {
            $progress = new VoidProgress();
        }

        $project_analyzer = \Psalm\Internal\Analyzer\ProjectAnalyzer::getInstance();
        $codebase = $project_analyzer->getCodebase();

        if (!$codebase->methods_to_move) {
            return;
        }

        $progress->debug('Refactoring methods ' . PHP_EOL);

        $code_migrations = [];

        foreach ($codebase->methods_to_move as $source => $destination) {
            try {
                $source_method_storage = $methods->getStorage($source);
            } catch (\InvalidArgumentException $e) {
                continue;
            }

            list($destination_fq_class_name, $destination_name) = explode('::', $destination);

            try {
                $classlike_storage = $this->classlike_storage_provider->get($destination_fq_class_name);
            } catch (\InvalidArgumentException $e) {
                continue;
            }

            if ($classlike_storage->stmt_location
                && $this->config->isInProjectDirs($classlike_storage->stmt_location->file_path)
                && $source_method_storage->stmt_location
                && $source_method_storage->stmt_location->file_path
                && $source_method_storage->location
            ) {
                $new_class_bounds = $classlike_storage->stmt_location->getSnippetBounds();
                $old_method_bounds = $source_method_storage->stmt_location->getSnippetBounds();

                $old_method_name_bounds = $source_method_storage->location->getSelectionBounds();

                FileManipulationBuffer::add(
                    $source_method_storage->stmt_location->file_path,
                    [
                        new \Psalm\FileManipulation(
                            $old_method_name_bounds[0],
                            $old_method_name_bounds[1],
                            $destination_name
                        ),
                    ]
                );

                $selection = $classlike_storage->stmt_location->getSnippet();

                $insert_pos = strrpos($selection, "\n", -1);

                if (!$insert_pos) {
                    $insert_pos = strlen($selection) - 1;
                } else {
                    ++$insert_pos;
                }

                $code_migrations[] = new \Psalm\Internal\FileManipulation\CodeMigration(
                    $source_method_storage->stmt_location->file_path,
                    $old_method_bounds[0],
                    $old_method_bounds[1],
                    $classlike_storage->stmt_location->file_path,
                    $new_class_bounds[0] + $insert_pos
                );
            }
        }

        FileManipulationBuffer::addCodeMigrations($code_migrations);
    }

    /**
     * @return void
     */
    public function moveProperties(Properties $properties, Progress $progress = null)
    {
        if ($progress === null) {
            $progress = new VoidProgress();
        }

        $project_analyzer = \Psalm\Internal\Analyzer\ProjectAnalyzer::getInstance();
        $codebase = $project_analyzer->getCodebase();

        if (!$codebase->properties_to_move) {
            return;
        }

        $progress->debug('Refacting properties ' . PHP_EOL);

        $code_migrations = [];

        foreach ($codebase->properties_to_move as $source => $destination) {
            try {
                $source_property_storage = $properties->getStorage($source);
            } catch (\InvalidArgumentException $e) {
                continue;
            }

            list($source_fq_class_name) = explode('::$', $source);
            list($destination_fq_class_name, $destination_name) = explode('::$', $destination);

            $source_classlike_storage = $this->classlike_storage_provider->get($source_fq_class_name);
            $destination_classlike_storage = $this->classlike_storage_provider->get($destination_fq_class_name);

            if ($destination_classlike_storage->stmt_location
                && $this->config->isInProjectDirs($destination_classlike_storage->stmt_location->file_path)
                && $source_property_storage->stmt_location
                && $source_property_storage->stmt_location->file_path
                && $source_property_storage->location
            ) {
                if ($source_property_storage->type
                    && $source_property_storage->type_location
                    && $source_property_storage->type_location !== $source_property_storage->signature_type_location
                ) {
                    $bounds = $source_property_storage->type_location->getSelectionBounds();

                    $replace_type = \Psalm\Internal\Analyzer\Statements\ExpressionAnalyzer::fleshOutType(
                        $codebase,
                        $source_property_storage->type,
                        $source_classlike_storage->name,
                        $source_classlike_storage->name,
                        $source_classlike_storage->parent_class
                    );

                    $this->airliftClassDefinedDocblockType(
                        $replace_type,
                        $destination_fq_class_name,
                        $source_property_storage->stmt_location->file_path,
                        $bounds[0],
                        $bounds[1]
                    );
                }

                $new_class_bounds = $destination_classlike_storage->stmt_location->getSnippetBounds();
                $old_property_bounds = $source_property_storage->stmt_location->getSnippetBounds();

                $old_property_name_bounds = $source_property_storage->location->getSelectionBounds();

                FileManipulationBuffer::add(
                    $source_property_storage->stmt_location->file_path,
                    [
                        new \Psalm\FileManipulation(
                            $old_property_name_bounds[0],
                            $old_property_name_bounds[1],
                            '$' . $destination_name
                        ),
                    ]
                );

                $selection = $destination_classlike_storage->stmt_location->getSnippet();

                $insert_pos = strrpos($selection, "\n", -1);

                if (!$insert_pos) {
                    $insert_pos = strlen($selection) - 1;
                } else {
                    ++$insert_pos;
                }

                $code_migrations[] = new \Psalm\Internal\FileManipulation\CodeMigration(
                    $source_property_storage->stmt_location->file_path,
                    $old_property_bounds[0],
                    $old_property_bounds[1],
                    $destination_classlike_storage->stmt_location->file_path,
                    $new_class_bounds[0] + $insert_pos
                );
            }
        }

        FileManipulationBuffer::addCodeMigrations($code_migrations);
    }

    /**
     * @return void
     */
    public function moveClassConstants(Progress $progress = null)
    {
        if ($progress === null) {
            $progress = new VoidProgress();
        }

        $project_analyzer = \Psalm\Internal\Analyzer\ProjectAnalyzer::getInstance();
        $codebase = $project_analyzer->getCodebase();

        if (!$codebase->class_constants_to_move) {
            return;
        }

        $progress->debug('Refacting constants ' . PHP_EOL);

        $code_migrations = [];

        foreach ($codebase->class_constants_to_move as $source => $destination) {
            list($source_fq_class_name, $source_const_name) = explode('::', $source);
            list($destination_fq_class_name, $destination_name) = explode('::', $destination);

            $source_classlike_storage = $this->classlike_storage_provider->get($source_fq_class_name);
            $destination_classlike_storage = $this->classlike_storage_provider->get($destination_fq_class_name);

            $source_const_stmt_location = $source_classlike_storage->class_constant_stmt_locations[$source_const_name];
            $source_const_location = $source_classlike_storage->class_constant_locations[$source_const_name];

            if ($destination_classlike_storage->stmt_location
                && $this->config->isInProjectDirs($destination_classlike_storage->stmt_location->file_path)
                && $source_const_stmt_location->file_path
            ) {
                $new_class_bounds = $destination_classlike_storage->stmt_location->getSnippetBounds();
                $old_const_bounds = $source_const_stmt_location->getSnippetBounds();

                $old_const_name_bounds = $source_const_location->getSelectionBounds();

                FileManipulationBuffer::add(
                    $source_const_stmt_location->file_path,
                    [
                        new \Psalm\FileManipulation(
                            $old_const_name_bounds[0],
                            $old_const_name_bounds[1],
                            $destination_name
                        ),
                    ]
                );

                $selection = $destination_classlike_storage->stmt_location->getSnippet();

                $insert_pos = strrpos($selection, "\n", -1);

                if (!$insert_pos) {
                    $insert_pos = strlen($selection) - 1;
                } else {
                    ++$insert_pos;
                }

                $code_migrations[] = new \Psalm\Internal\FileManipulation\CodeMigration(
                    $source_const_stmt_location->file_path,
                    $old_const_bounds[0],
                    $old_const_bounds[1],
                    $destination_classlike_storage->stmt_location->file_path,
                    $new_class_bounds[0] + $insert_pos
                );
            }
        }

        FileManipulationBuffer::addCodeMigrations($code_migrations);
    }

    public function handleClassLikeReferenceInMigration(
        \Psalm\Codebase $codebase,
        \Psalm\StatementsSource $source,
        PhpParser\Node $class_name_node,
        string $fq_class_name,
        ?string $calling_method_id,
        bool $force_change = false
    ) : bool {
        $calling_fq_class_name = $source->getFQCLN();

        // if we're inside a moved class static method
        if ($codebase->methods_to_move
            && $calling_fq_class_name
            && $calling_method_id
            && isset($codebase->methods_to_move[strtolower($calling_method_id)])
        ) {
            $destination_class = explode('::', $codebase->methods_to_move[strtolower($calling_method_id)])[0];

            $intended_fq_class_name = strtolower($calling_fq_class_name) === strtolower($fq_class_name)
                && isset($codebase->classes_to_move[strtolower($calling_fq_class_name)])
                ? $destination_class
                : $fq_class_name;

            $this->airliftClassLikeReference(
                $intended_fq_class_name,
                $destination_class,
                $source->getFilePath(),
                (int) $class_name_node->getAttribute('startFilePos'),
                (int) $class_name_node->getAttribute('endFilePos') + 1,
                $class_name_node instanceof PhpParser\Node\Scalar\MagicConst\Class_
            );

            return true;
        }

        // if we're outside a moved class, but we're changing all references to a class
        if (isset($codebase->class_transforms[strtolower($fq_class_name)])) {
            $new_fq_class_name = $codebase->class_transforms[strtolower($fq_class_name)];
            $file_manipulations = [];

            if ($class_name_node instanceof PhpParser\Node\Identifier) {
                $destination_parts = explode('\\', $new_fq_class_name);

                $destination_class_name = array_pop($destination_parts);
                $file_manipulations = [];

                $file_manipulations[] = new \Psalm\FileManipulation(
                    (int) $class_name_node->getAttribute('startFilePos'),
                    (int) $class_name_node->getAttribute('endFilePos') + 1,
                    $destination_class_name
                );

                FileManipulationBuffer::add($source->getFilePath(), $file_manipulations);

                return true;
            }

            $uses_flipped = $source->getAliasedClassesFlipped();
            $uses_flipped_replaceable = $source->getAliasedClassesFlippedReplaceable();

            $old_fq_class_name = strtolower($fq_class_name);

            $migrated_source_fqcln = $calling_fq_class_name;

            if ($calling_fq_class_name
                && isset($codebase->class_transforms[strtolower($calling_fq_class_name)])
            ) {
                $migrated_source_fqcln = $codebase->class_transforms[strtolower($calling_fq_class_name)];
            }

            $source_namespace = $source->getNamespace();

            if ($migrated_source_fqcln && $calling_fq_class_name !== $migrated_source_fqcln) {
                $new_source_parts = explode('\\', $migrated_source_fqcln);
                array_pop($new_source_parts);
                $source_namespace = implode('\\', $new_source_parts);
            }

            if (isset($uses_flipped_replaceable[$old_fq_class_name])) {
                $alias = $uses_flipped_replaceable[$old_fq_class_name];
                unset($uses_flipped[$old_fq_class_name]);
                $old_class_name_parts = explode('\\', $old_fq_class_name);
                $old_class_name = end($old_class_name_parts);
                if (strtolower($old_class_name) === strtolower($alias)) {
                    $new_class_name_parts = explode('\\', $new_fq_class_name);
                    $new_class_name = end($new_class_name_parts);
                    $uses_flipped[strtolower($new_fq_class_name)] = $new_class_name;
                } else {
                    $uses_flipped[strtolower($new_fq_class_name)] = $alias;
                }
            }

            $file_manipulations[] = new \Psalm\FileManipulation(
                (int) $class_name_node->getAttribute('startFilePos'),
                (int) $class_name_node->getAttribute('endFilePos') + 1,
                Type::getStringFromFQCLN(
                    $new_fq_class_name,
                    $source_namespace,
                    $uses_flipped,
                    $migrated_source_fqcln
                )
                    . ($class_name_node instanceof PhpParser\Node\Scalar\MagicConst\Class_ ? '::class' : '')
            );

            FileManipulationBuffer::add($source->getFilePath(), $file_manipulations);

            return true;
        }

        // if we're inside a moved class (could be a method, could be a property/class const default)
        if ($codebase->classes_to_move
            && $calling_fq_class_name
            && isset($codebase->classes_to_move[strtolower($calling_fq_class_name)])
        ) {
            $destination_class = $codebase->classes_to_move[strtolower($calling_fq_class_name)];

            if ($class_name_node instanceof PhpParser\Node\Identifier) {
                $destination_parts = explode('\\', $destination_class);

                $destination_class_name = array_pop($destination_parts);
                $file_manipulations = [];

                $file_manipulations[] = new \Psalm\FileManipulation(
                    (int) $class_name_node->getAttribute('startFilePos'),
                    (int) $class_name_node->getAttribute('endFilePos') + 1,
                    $destination_class_name
                );

                FileManipulationBuffer::add($source->getFilePath(), $file_manipulations);
            } else {
                $this->airliftClassLikeReference(
                    strtolower($calling_fq_class_name) === strtolower($fq_class_name)
                        ? $destination_class
                        : $fq_class_name,
                    $destination_class,
                    $source->getFilePath(),
                    (int) $class_name_node->getAttribute('startFilePos'),
                    (int) $class_name_node->getAttribute('endFilePos') + 1,
                    $class_name_node instanceof PhpParser\Node\Scalar\MagicConst\Class_
                );
            }

            return true;
        }

        if ($force_change) {
            if ($calling_fq_class_name) {
                $this->airliftClassLikeReference(
                    $fq_class_name,
                    $calling_fq_class_name,
                    $source->getFilePath(),
                    (int) $class_name_node->getAttribute('startFilePos'),
                    (int) $class_name_node->getAttribute('endFilePos') + 1
                );
            } else {
                $file_manipulations = [];

                $file_manipulations[] = new \Psalm\FileManipulation(
                    (int) $class_name_node->getAttribute('startFilePos'),
                    (int) $class_name_node->getAttribute('endFilePos') + 1,
                    Type::getStringFromFQCLN(
                        $fq_class_name,
                        $source->getNamespace(),
                        $source->getAliasedClassesFlipped(),
                        null
                    )
                );

                FileManipulationBuffer::add($source->getFilePath(), $file_manipulations);
            }

            return true;
        }

        return false;
    }

    public function handleDocblockTypeInMigration(
        \Psalm\Codebase $codebase,
        \Psalm\StatementsSource $source,
        Type\Union $type,
        CodeLocation $type_location,
        ?string $calling_method_id
    ) : void {
        $calling_fq_class_name = $source->getFQCLN();

        $moved_type = false;

        // if we're inside a moved class static method
        if ($codebase->methods_to_move
            && $calling_fq_class_name
            && $calling_method_id
            && isset($codebase->methods_to_move[strtolower($calling_method_id)])
        ) {
            $bounds = $type_location->getSelectionBounds();

            $destination_class = explode('::', $codebase->methods_to_move[strtolower($calling_method_id)])[0];

            $this->airliftClassDefinedDocblockType(
                $type,
                $destination_class,
                $source->getFilePath(),
                $bounds[0],
                $bounds[1]
            );

            $moved_type = true;
        }

        // if we're outside a moved class, but we're changing all references to a class
        if (!$moved_type && $codebase->class_transforms) {
            $uses_flipped = $source->getAliasedClassesFlipped();
            $uses_flipped_replaceable = $source->getAliasedClassesFlippedReplaceable();

            $migrated_source_fqcln = $calling_fq_class_name;

            if ($calling_fq_class_name
                && isset($codebase->class_transforms[strtolower($calling_fq_class_name)])
            ) {
                $migrated_source_fqcln = $codebase->class_transforms[strtolower($calling_fq_class_name)];
            }

            $source_namespace = $source->getNamespace();

            if ($migrated_source_fqcln && $calling_fq_class_name !== $migrated_source_fqcln) {
                $new_source_parts = explode('\\', $migrated_source_fqcln);
                array_pop($new_source_parts);
                $source_namespace = implode('\\', $new_source_parts);
            }

            foreach ($codebase->class_transforms as $old_fq_class_name => $new_fq_class_name) {
                if (isset($uses_flipped_replaceable[$old_fq_class_name])) {
                    $alias = $uses_flipped_replaceable[$old_fq_class_name];
                    unset($uses_flipped[$old_fq_class_name]);
                    $old_class_name_parts = explode('\\', $old_fq_class_name);
                    $old_class_name = end($old_class_name_parts);
                    if (strtolower($old_class_name) === strtolower($alias)) {
                        $new_class_name_parts = explode('\\', $new_fq_class_name);
                        $new_class_name = end($new_class_name_parts);
                        $uses_flipped[strtolower($new_fq_class_name)] = $new_class_name;
                    } else {
                        $uses_flipped[strtolower($new_fq_class_name)] = $alias;
                    }
                }
            }

            foreach ($codebase->class_transforms as $old_fq_class_name => $new_fq_class_name) {
                if ($type->containsClassLike($old_fq_class_name)) {
                    $type = clone $type;

                    $type->replaceClassLike($old_fq_class_name, $new_fq_class_name);

                    $bounds = $type_location->getSelectionBounds();

                    $file_manipulations = [];

                    $file_manipulations[] = new \Psalm\FileManipulation(
                        $bounds[0],
                        $bounds[1],
                        $type->toNamespacedString(
                            $source_namespace,
                            $uses_flipped,
                            $migrated_source_fqcln,
                            false
                        )
                    );

                    FileManipulationBuffer::add(
                        $source->getFilePath(),
                        $file_manipulations
                    );

                    $moved_type = true;
                }
            }
        }

        // if we're inside a moved class (could be a method, could be a property/class const default)
        if (!$moved_type
            && $codebase->classes_to_move
            && $calling_fq_class_name
            && isset($codebase->classes_to_move[strtolower($calling_fq_class_name)])
        ) {
            $bounds = $type_location->getSelectionBounds();

            $destination_class = $codebase->classes_to_move[strtolower($calling_fq_class_name)];

            if ($type->containsClassLike(strtolower($calling_fq_class_name))) {
                $type = clone $type;

                $type->replaceClassLike(strtolower($calling_fq_class_name), $destination_class);
            }

            $this->airliftClassDefinedDocblockType(
                $type,
                $destination_class,
                $source->getFilePath(),
                $bounds[0],
                $bounds[1]
            );
        }
    }

    public function airliftClassLikeReference(
        string $fq_class_name,
        string $destination_fq_class_name,
        string $source_file_path,
        int $source_start,
        int $source_end,
        bool $add_class_constant = false
    ) : void {
        $project_analyzer = \Psalm\Internal\Analyzer\ProjectAnalyzer::getInstance();
        $codebase = $project_analyzer->getCodebase();

        $destination_class_storage = $codebase->classlike_storage_provider->get($destination_fq_class_name);

        if (!$destination_class_storage->aliases) {
            throw new \UnexpectedValueException('Aliases should not be null');
        }

        $file_manipulations = [];

        $file_manipulations[] = new \Psalm\FileManipulation(
            $source_start,
            $source_end,
            Type::getStringFromFQCLN(
                $fq_class_name,
                $destination_class_storage->aliases->namespace,
                $destination_class_storage->aliases->uses_flipped,
                $destination_class_storage->name
            ) . ($add_class_constant ? '::class' : '')
        );

        FileManipulationBuffer::add(
            $source_file_path,
            $file_manipulations
        );
    }

    public function airliftClassDefinedDocblockType(
        Type\Union $type,
        string $destination_fq_class_name,
        string $source_file_path,
        int $source_start,
        int $source_end
    ) : void {
        $project_analyzer = \Psalm\Internal\Analyzer\ProjectAnalyzer::getInstance();
        $codebase = $project_analyzer->getCodebase();

        $destination_class_storage = $codebase->classlike_storage_provider->get($destination_fq_class_name);

        if (!$destination_class_storage->aliases) {
            throw new \UnexpectedValueException('Aliases should not be null');
        }

        $file_manipulations = [];

        $file_manipulations[] = new \Psalm\FileManipulation(
            $source_start,
            $source_end,
            $type->toNamespacedString(
                $destination_class_storage->aliases->namespace,
                $destination_class_storage->aliases->uses_flipped,
                $destination_class_storage->name,
                false
            )
        );

        FileManipulationBuffer::add(
            $source_file_path,
            $file_manipulations
        );
    }

    /**
     * @param  string $class_name
     * @param  mixed  $visibility
     *
     * @return array<string,Type\Union>
     */
    public function getConstantsForClass($class_name, $visibility)
    {
        $class_name = strtolower($class_name);

        $storage = $this->classlike_storage_provider->get($class_name);

        if ($visibility === ReflectionProperty::IS_PUBLIC) {
            return $storage->public_class_constants;
        }

        if ($visibility === ReflectionProperty::IS_PROTECTED) {
            return array_merge(
                $storage->public_class_constants,
                $storage->protected_class_constants
            );
        }

        if ($visibility === ReflectionProperty::IS_PRIVATE) {
            return array_merge(
                $storage->public_class_constants,
                $storage->protected_class_constants,
                $storage->private_class_constants
            );
        }

        throw new \InvalidArgumentException('Must specify $visibility');
    }

    /**
     * @param   string      $class_name
     * @param   string      $const_name
     * @param   Type\Union  $type
     * @param   int         $visibility
     *
     * @return  void
     */
    public function setConstantType(
        $class_name,
        $const_name,
        Type\Union $type,
        $visibility
    ) {
        $storage = $this->classlike_storage_provider->get($class_name);

        if ($visibility === ReflectionProperty::IS_PUBLIC) {
            $storage->public_class_constants[$const_name] = $type;
        } elseif ($visibility === ReflectionProperty::IS_PROTECTED) {
            $storage->protected_class_constants[$const_name] = $type;
        } elseif ($visibility === ReflectionProperty::IS_PRIVATE) {
            $storage->private_class_constants[$const_name] = $type;
        }
    }

    /**
     * @return void
     */
    private function checkMethodReferences(ClassLikeStorage $classlike_storage, Methods $methods)
    {
        $project_analyzer = \Psalm\Internal\Analyzer\ProjectAnalyzer::getInstance();
        $codebase = $project_analyzer->getCodebase();

        foreach ($classlike_storage->appearing_method_ids as $method_name => $appearing_method_id) {
            list($appearing_fq_classlike_name) = explode('::', $appearing_method_id);

            if ($appearing_fq_classlike_name !== $classlike_storage->name) {
                continue;
            }

            $method_id = $appearing_method_id;

            $declaring_classlike_storage = $classlike_storage;

            if (isset($classlike_storage->methods[$method_name])) {
                $method_storage = $classlike_storage->methods[$method_name];
            } else {
                $declaring_method_id = $classlike_storage->declaring_method_ids[$method_name];

                list($declaring_fq_classlike_name, $declaring_method_name) = explode('::', $declaring_method_id);

                try {
                    $declaring_classlike_storage = $this->classlike_storage_provider->get($declaring_fq_classlike_name);
                } catch (\InvalidArgumentException $e) {
                    continue;
                }

                $method_storage = $declaring_classlike_storage->methods[$declaring_method_name];
                $method_id = $declaring_method_id;
            }

            $method_referenced = $this->file_reference_provider->isClassMethodReferenced(strtolower($method_id));

            if (!$method_referenced
                && (substr($method_name, 0, 2) !== '__' || $method_name === '__construct')
                && $method_storage->location
            ) {
                $method_location = $method_storage->location;

                $method_id = $classlike_storage->name . '::' . $method_storage->cased_name;

                if ($method_storage->visibility !== ClassLikeAnalyzer::VISIBILITY_PRIVATE) {
                    $method_name_lc = strtolower($method_name);

                    $has_parent_references = false;

                    $has_variable_calls = $codebase->analyzer->hasMixedMemberName(strtolower($method_name))
                        || $codebase->analyzer->hasMixedMemberName(strtolower($classlike_storage->name . '::'));

                    if (isset($classlike_storage->overridden_method_ids[$method_name_lc])) {
                        foreach ($classlike_storage->overridden_method_ids[$method_name_lc] as $parent_method_id) {
                            $parent_method_storage = $methods->getStorage($parent_method_id);

                            $parent_method_referenced = $this->file_reference_provider->isClassMethodReferenced(
                                strtolower($parent_method_id)
                            );

                            if (!$parent_method_storage->abstract || $parent_method_referenced) {
                                $has_parent_references = true;
                            }
                        }
                    }

                    foreach ($classlike_storage->parent_classes as $parent_method_fqcln) {
                        if ($codebase->analyzer->hasMixedMemberName(
                            strtolower($parent_method_fqcln) . '::'
                        )) {
                            $has_variable_calls = true;
                        }
                    }

                    foreach ($classlike_storage->class_implements as $fq_interface_name) {
                        try {
                            $interface_storage = $this->classlike_storage_provider->get($fq_interface_name);
                        } catch (\InvalidArgumentException $e) {
                            continue;
                        }

                        if ($codebase->analyzer->hasMixedMemberName(
                            strtolower($fq_interface_name) . '::'
                        )) {
                            $has_variable_calls = true;
                        }

                        if (isset($interface_storage->methods[$method_name])) {
                            $interface_method_referenced = $this->file_reference_provider->isClassMethodReferenced(
                                strtolower($fq_interface_name . '::' . $method_name)
                            );

                            if ($interface_method_referenced) {
                                $has_parent_references = true;
                            }
                        }
                    }

                    if (!$has_parent_references) {
                        $issue = new PossiblyUnusedMethod(
                            'Cannot find ' . ($has_variable_calls ? 'explicit' : 'any')
                                . ' calls to method ' . $method_id
                                . ($has_variable_calls ? ' (but did find some potential callers)' : ''),
                            $method_storage->location,
                            $method_id
                        );

                        if ($codebase->alter_code) {
                            if ($method_storage->stmt_location
                                && !$declaring_classlike_storage->is_trait
                                && isset($project_analyzer->getIssuesToFix()['PossiblyUnusedMethod'])
                                && !$has_variable_calls
                                && !IssueBuffer::isSuppressed($issue, $method_storage->suppressed_issues)
                            ) {
                                FileManipulationBuffer::addForCodeLocation(
                                    $method_storage->stmt_location,
                                    '',
                                    true
                                );
                            }
                        } elseif (IssueBuffer::accepts(
                            $issue,
                            $method_storage->suppressed_issues
                        )) {
                            // fall through
                        }
                    }
                } elseif (!isset($classlike_storage->declaring_method_ids['__call'])) {
                    $has_variable_calls = $codebase->analyzer->hasMixedMemberName(
                        strtolower($classlike_storage->name . '::')
                    ) || $codebase->analyzer->hasMixedMemberName(strtolower($method_name));

                    $issue = new UnusedMethod(
                        'Cannot find ' . ($has_variable_calls ? 'explicit' : 'any')
                            . ' calls to private method ' . $method_id
                            . ($has_variable_calls ? ' (but did find some potential callers)' : ''),
                        $method_location,
                        $method_id
                    );

                    if ($codebase->alter_code) {
                        if ($method_storage->stmt_location
                            && !$declaring_classlike_storage->is_trait
                            && isset($project_analyzer->getIssuesToFix()['UnusedMethod'])
                            && !$has_variable_calls
                            && !IssueBuffer::isSuppressed($issue, $method_storage->suppressed_issues)
                        ) {
                            FileManipulationBuffer::addForCodeLocation(
                                $method_storage->stmt_location,
                                '',
                                true
                            );
                        }
                    } elseif (IssueBuffer::accepts(
                        $issue,
                        $method_storage->suppressed_issues
                    )) {
                        // fall through
                    }
                }
            } else {
                if ($codebase->alter_code
                    && isset($project_analyzer->getIssuesToFix()['MissingParamType'])
                    && isset($codebase->analyzer->possible_method_param_types[strtolower($method_id)])
                ) {
                    if ($method_storage->location) {
                        $function_analyzer = $project_analyzer->getFunctionLikeAnalyzer(
                            $method_id,
                            $method_storage->location->file_path
                        );

                        $possible_param_types
                            = $codebase->analyzer->possible_method_param_types[strtolower($method_id)];

                        if ($function_analyzer && $possible_param_types) {
                            foreach ($possible_param_types as $offset => $possible_type) {
                                if (!isset($method_storage->params[$offset])) {
                                    continue;
                                }

                                $param_name = $method_storage->params[$offset]->name;

                                if ($possible_type->hasMixed() || $possible_type->isNull()) {
                                    continue;
                                }

                                if ($method_storage->params[$offset]->default_type) {
                                    $possible_type = \Psalm\Type::combineUnionTypes(
                                        $possible_type,
                                        $method_storage->params[$offset]->default_type
                                    );
                                }

                                $function_analyzer->addOrUpdateParamType(
                                    $project_analyzer,
                                    $param_name,
                                    $possible_type,
                                    true
                                );
                            }
                        }
                    }
                }

                if ($method_storage->visibility !== ClassLikeAnalyzer::VISIBILITY_PRIVATE
                    && !$classlike_storage->is_interface
                ) {
                    foreach ($method_storage->params as $offset => $param_storage) {
                        if (!$this->file_reference_provider->isMethodParamUsed(strtolower($method_id), $offset)
                            && $param_storage->location
                        ) {
                            if (IssueBuffer::accepts(
                                new PossiblyUnusedParam(
                                    'Param #' . ($offset + 1) . ' is never referenced in this method',
                                    $param_storage->location
                                ),
                                $method_storage->suppressed_issues
                            )) {
                                // fall through
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @return void
     */
    private function checkPropertyReferences(ClassLikeStorage $classlike_storage)
    {
        $project_analyzer = \Psalm\Internal\Analyzer\ProjectAnalyzer::getInstance();
        $codebase = $project_analyzer->getCodebase();

        foreach ($classlike_storage->properties as $property_name => $property_storage) {
            $property_referenced = $this->file_reference_provider->isClassPropertyReferenced(
                strtolower($classlike_storage->name) . '::$' . $property_name
            );

            if (!$property_referenced
                && (substr($property_name, 0, 2) !== '__' || $property_name === '__construct')
                && $property_storage->location
            ) {
                $property_id = $classlike_storage->name . '::$' . $property_name;

                if ($property_storage->visibility === ClassLikeAnalyzer::VISIBILITY_PUBLIC
                    || $property_storage->visibility === ClassLikeAnalyzer::VISIBILITY_PROTECTED
                ) {
                    $has_parent_references = isset($classlike_storage->overridden_property_ids[$property_name]);

                    $has_variable_calls = $codebase->analyzer->hasMixedMemberName('$' . $property_name)
                        || $codebase->analyzer->hasMixedMemberName(strtolower($classlike_storage->name) . '::$');

                    foreach ($classlike_storage->parent_classes as $parent_method_fqcln) {
                        if ($codebase->analyzer->hasMixedMemberName(
                            strtolower($parent_method_fqcln) . '::$'
                        )) {
                            $has_variable_calls = true;
                            break;
                        }
                    }

                    foreach ($classlike_storage->class_implements as $fq_interface_name) {
                        if ($codebase->analyzer->hasMixedMemberName(
                            strtolower($fq_interface_name) . '::$'
                        )) {
                            $has_variable_calls = true;
                            break;
                        }
                    }

                    if (!$has_parent_references
                        && ($property_storage->visibility === ClassLikeAnalyzer::VISIBILITY_PUBLIC
                            || !isset($classlike_storage->declaring_method_ids['__get']))
                    ) {
                        $issue = new PossiblyUnusedProperty(
                            'Cannot find ' . ($has_variable_calls ? 'explicit' : 'any')
                                . ' references to property ' . $property_id
                                . ($has_variable_calls ? ' (but did find some potential references)' : ''),
                            $property_storage->location
                        );

                        if ($codebase->alter_code) {
                            if ($property_storage->stmt_location
                                && isset($project_analyzer->getIssuesToFix()['PossiblyUnusedProperty'])
                                && !$has_variable_calls
                                && !IssueBuffer::isSuppressed($issue, $classlike_storage->suppressed_issues)
                            ) {
                                FileManipulationBuffer::addForCodeLocation(
                                    $property_storage->stmt_location,
                                    '',
                                    true
                                );
                            }
                        } elseif (IssueBuffer::accepts(
                            $issue,
                            $classlike_storage->suppressed_issues
                        )) {
                            // fall through
                        }
                    }
                } elseif (!isset($classlike_storage->declaring_method_ids['__get'])) {
                    $has_variable_calls = $codebase->analyzer->hasMixedMemberName('$' . $property_name);

                    $issue = new UnusedProperty(
                        'Cannot find ' . ($has_variable_calls ? 'explicit' : 'any')
                            . ' references to private property ' . $property_id
                            . ($has_variable_calls ? ' (but did find some potential references)' : ''),
                        $property_storage->location
                    );

                    if ($codebase->alter_code) {
                        if ($property_storage->stmt_location
                            && isset($project_analyzer->getIssuesToFix()['UnusedProperty'])
                            && !$has_variable_calls
                            && !IssueBuffer::isSuppressed($issue, $classlike_storage->suppressed_issues)
                        ) {
                            FileManipulationBuffer::addForCodeLocation(
                                $property_storage->stmt_location,
                                '',
                                true
                            );
                        }
                    } elseif (IssueBuffer::accepts(
                        $issue,
                        $classlike_storage->suppressed_issues
                    )) {
                        // fall through
                    }
                }
            }
        }
    }

    /**
     * @param  string $fq_classlike_name_lc
     *
     * @return void
     */
    public function registerMissingClassLike($fq_classlike_name_lc)
    {
        $this->existing_classlikes_lc[$fq_classlike_name_lc] = false;
    }

    /**
     * @param  string $fq_classlike_name_lc
     *
     * @return bool
     */
    public function isMissingClassLike($fq_classlike_name_lc)
    {
        return isset($this->existing_classlikes_lc[$fq_classlike_name_lc])
            && $this->existing_classlikes_lc[$fq_classlike_name_lc] === false;
    }

    /**
     * @param  string $fq_classlike_name_lc
     *
     * @return bool
     */
    public function doesClassLikeExist($fq_classlike_name_lc)
    {
        return isset($this->existing_classlikes_lc[$fq_classlike_name_lc])
            && $this->existing_classlikes_lc[$fq_classlike_name_lc];
    }

    /**
     * @param string $fq_class_name
     *
     * @return void
     */
    public function removeClassLike($fq_class_name)
    {
        $fq_class_name_lc = strtolower($fq_class_name);
        unset(
            $this->existing_classlikes_lc[$fq_class_name_lc],
            $this->existing_classes_lc[$fq_class_name_lc],
            $this->existing_traits_lc[$fq_class_name_lc],
            $this->existing_traits[$fq_class_name],
            $this->existing_interfaces_lc[$fq_class_name_lc],
            $this->existing_interfaces[$fq_class_name],
            $this->existing_classes[$fq_class_name],
            $this->trait_nodes[$fq_class_name_lc],
            $this->trait_aliases[$fq_class_name_lc]
        );

        $this->scanner->removeClassLike($fq_class_name_lc);
    }

    /**
     * @return array{
     *     0: array<string, bool>,
     *     1: array<string, bool>,
     *     2: array<string, bool>,
     *     3: array<string, bool>,
     *     4: array<string, bool>,
     *     5: array<string, bool>,
     *     6: array<string, bool>,
     *     7: array<string, \PhpParser\Node\Stmt\Trait_>,
     *     8: array<string, \Psalm\Aliases>
     * }
     */
    public function getThreadData()
    {
        return [
            $this->existing_classlikes_lc,
            $this->existing_classes_lc,
            $this->existing_traits_lc,
            $this->existing_traits,
            $this->existing_interfaces_lc,
            $this->existing_interfaces,
            $this->existing_classes,
            $this->trait_nodes,
            $this->trait_aliases,
        ];
    }

    /**
     * @param array{
     *     0: array<string, bool>,
     *     1: array<string, bool>,
     *     2: array<string, bool>,
     *     3: array<string, bool>,
     *     4: array<string, bool>,
     *     5: array<string, bool>,
     *     6: array<string, bool>,
     *     7: array<string, \PhpParser\Node\Stmt\Trait_>,
     *     8: array<string, \Psalm\Aliases>
     * } $thread_data
     *
     * @return void
     */
    public function addThreadData(array $thread_data)
    {
        list(
            $existing_classlikes_lc,
            $existing_classes_lc,
            $existing_traits_lc,
            $existing_traits,
            $existing_interfaces_lc,
            $existing_interfaces,
            $existing_classes,
            $trait_nodes,
            $trait_aliases) = $thread_data;

        $this->existing_classlikes_lc = array_merge($existing_classlikes_lc, $this->existing_classlikes_lc);
        $this->existing_classes_lc = array_merge($existing_classes_lc, $this->existing_classes_lc);
        $this->existing_traits_lc = array_merge($existing_traits_lc, $this->existing_traits_lc);
        $this->existing_traits = array_merge($existing_traits, $this->existing_traits);
        $this->existing_interfaces_lc = array_merge($existing_interfaces_lc, $this->existing_interfaces_lc);
        $this->existing_interfaces = array_merge($existing_interfaces, $this->existing_interfaces);
        $this->existing_classes = array_merge($existing_classes, $this->existing_classes);
        $this->trait_nodes = array_merge($trait_nodes, $this->trait_nodes);
        $this->trait_aliases = array_merge($trait_aliases, $this->trait_aliases);
    }
}
