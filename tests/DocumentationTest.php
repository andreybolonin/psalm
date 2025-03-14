<?php
namespace Psalm\Tests;

use function array_keys;
use function count;
use const DIRECTORY_SEPARATOR;
use function dirname;
use function explode;
use function file_exists;
use function file_get_contents;
use function implode;
use function preg_quote;
use Psalm\Config;
use Psalm\Context;
use Psalm\Internal\Analyzer\FileAnalyzer;
use Psalm\Tests\Internal\Provider;
use function sort;
use function strpos;
use function substr;
use function trim;

class DocumentationTest extends TestCase
{
    /** @var \Psalm\Internal\Analyzer\ProjectAnalyzer */
    protected $project_analyzer;

    /**
     * @return array<string, array<int, string>>
     */
    private static function getCodeBlocksFromDocs()
    {
        $issue_file = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'running_psalm' . DIRECTORY_SEPARATOR . 'issues.md';

        if (!file_exists($issue_file)) {
            throw new \UnexpectedValueException('docs not found');
        }

        $file_contents = file_get_contents($issue_file);

        if (!$file_contents) {
            throw new \UnexpectedValueException('Docs are empty');
        }

        $file_lines = explode("\n", $file_contents);

        $issue_code = [];

        $current_issue = null;

        for ($i = 0, $j = count($file_lines); $i < $j; ++$i) {
            $current_line = $file_lines[$i];

            if (substr($current_line, 0, 4) === '### ') {
                $current_issue = trim(substr($current_line, 4));
                ++$i;
                continue;
            }

            if (substr($current_line, 0, 6) === '```php' && $current_issue) {
                $current_block = '';
                ++$i;

                do {
                    $current_block .= $file_lines[$i] . "\n";
                    ++$i;
                } while (substr($file_lines[$i], 0, 3) !== '```' && $i < $j);

                $issue_code[(string) $current_issue][] = trim($current_block);
            }
        }

        return $issue_code;
    }

    /**
     * @return void
     */
    public function setUp() : void
    {
        FileAnalyzer::clearCache();
        \Psalm\Internal\FileManipulation\FunctionDocblockManipulator::clearCache();

        $this->file_provider = new Provider\FakeFileProvider();

        $this->project_analyzer = new \Psalm\Internal\Analyzer\ProjectAnalyzer(
            new TestConfig(),
            new \Psalm\Internal\Provider\Providers(
                $this->file_provider,
                new Provider\FakeParserCacheProvider()
            )
        );

        $this->project_analyzer->setPhpVersion('7.3');
    }

    /**
     * @return void
     */
    public function testAllIssuesCovered()
    {
        $all_issues = \Psalm\Config\IssueHandler::getAllIssueTypes();
        $all_issues[] = 'ParseError';
        $all_issues[] = 'PluginIssue';

        sort($all_issues);

        $code_blocks = self::getCodeBlocksFromDocs();

        // these cannot have code
        $code_blocks['UnrecognizedExpression'] = true;
        $code_blocks['UnrecognizedStatement'] = true;
        $code_blocks['PluginIssue'] = true;

        // these are deprecated
        $code_blocks['TypeCoercion'] = true;
        $code_blocks['MixedTypeCoercion'] = true;
        $code_blocks['MixedTypeCoercion'] = true;
        $code_blocks['MisplacedRequiredParam'] = true;

        $documented_issues = array_keys($code_blocks);
        sort($documented_issues);

        $this->assertSame(implode("\n", $all_issues), implode("\n", $documented_issues));
    }

    /**
     * @dataProvider providerInvalidCodeParse
     * @small
     *
     * @param string $code
     * @param string $error_message
     * @param array<string> $error_levels
     * @param bool $check_references
     *
     * @return void
     */
    public function testInvalidCode($code, $error_message, $error_levels = [], $check_references = false)
    {
        if (strpos($this->getTestName(), 'SKIPPED-') !== false) {
            $this->markTestSkipped();
        }

        if ($check_references) {
            $this->project_analyzer->getCodebase()->reportUnusedCode();
        }

        foreach ($error_levels as $error_level) {
            $this->project_analyzer->getCodebase()->config->setCustomErrorLevel($error_level, Config::REPORT_SUPPRESS);
        }

        $this->expectException(\Psalm\Exception\CodeException::class);
        $this->expectExceptionMessageRegExp('/\b' . preg_quote($error_message, '/') . '\b/');

        $file_path = self::$src_dir_path . 'somefile.php';

        $this->addFile($file_path, $code);

        $context = new Context();
        $context->collect_references = $check_references;

        $this->analyzeFile($file_path, $context);

        if ($check_references) {
            $this->project_analyzer->checkClassReferences();
        }
    }

    /**
     * @return array<string,array{string,string,string[],bool}>
     */
    public function providerInvalidCodeParse()
    {
        $invalid_code_data = [];

        foreach (self::getCodeBlocksFromDocs() as $issue_name => $blocks) {
            switch ($issue_name) {
                case 'MissingThrowsDocblock':
                    continue 2;

                case 'UncaughtThrowInGlobalScope':
                    continue 2;

                case 'InvalidStringClass':
                    continue 2;

                case 'ForbiddenEcho':
                    continue 2;

                case 'PluginClass':
                    continue 2;

                case 'InvalidFalsableReturnType':
                    $ignored_issues = ['FalsableReturnStatement'];
                    break;

                case 'InvalidNullableReturnType':
                    $ignored_issues = ['NullableReturnStatement'];
                    break;

                case 'InvalidReturnType':
                    $ignored_issues = ['InvalidReturnStatement'];
                    break;

                case 'MixedInferredReturnType':
                    $ignored_issues = ['MixedReturnStatement'];
                    break;

                case 'MixedStringOffsetAssignment':
                    $ignored_issues = ['MixedAssignment'];
                    break;

                case 'ParadoxicalCondition':
                    $ignored_issues = ['MissingParamType'];
                    break;

                case 'UnusedClass':
                case 'UnusedMethod':
                    $ignored_issues = ['UnusedVariable'];
                    break;

                default:
                    $ignored_issues = [];
            }

            $invalid_code_data[$issue_name] = [
                '<?php' . "\n" . $blocks[0],
                $issue_name,
                $ignored_issues,
                strpos($issue_name, 'Unused') !== false || strpos($issue_name, 'Unevaluated') !== false,
            ];
        }

        return $invalid_code_data;
    }
}
