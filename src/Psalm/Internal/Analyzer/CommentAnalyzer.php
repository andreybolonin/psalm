<?php
namespace Psalm\Internal\Analyzer;

use PhpParser;
use Psalm\Aliases;
use Psalm\Codebase;
use Psalm\DocComment;
use Psalm\Exception\DocblockParseException;
use Psalm\Exception\IncorrectDocblockException;
use Psalm\Exception\TypeParseTreeException;
use Psalm\FileSource;
use Psalm\Internal\Scanner\ClassLikeDocblockComment;
use Psalm\Internal\Scanner\FunctionDocblockComment;
use Psalm\Internal\Scanner\VarDocblockComment;
use Psalm\Internal\Type\ParseTree;
use Psalm\Type;
use function trim;
use function substr_count;
use function strlen;
use function preg_replace;
use function str_replace;
use function preg_match;
use function count;
use function reset;
use function preg_split;
use const PREG_SPLIT_DELIM_CAPTURE;
use const PREG_SPLIT_NO_EMPTY;
use function array_shift;
use function implode;
use function substr;
use function strpos;
use function strtolower;
use function in_array;
use function explode;
use function array_merge;
use const PREG_OFFSET_CAPTURE;
use function rtrim;

/**
 * @internal
 */
class CommentAnalyzer
{
    const TYPE_REGEX = '(\??\\\?[\(\)A-Za-z0-9_&\<\.=,\>\[\]\-\{\}:|?\\\\]*|\$[a-zA-Z_0-9_]+)';

    /**
     * @param  array<string, array<string, array{Type\Union}>>|null   $template_type_map
     * @param  array<string, array<int, array{0: string, 1: int}>> $type_aliases
     *
     * @throws DocblockParseException if there was a problem parsing the docblock
     *
     * @return VarDocblockComment[]
     * @psalm-suppress MixedArrayAccess
     */
    public static function getTypeFromComment(
        PhpParser\Comment\Doc $comment,
        FileSource $source,
        Aliases $aliases,
        array $template_type_map = null,
        ?array $type_aliases = null
    ) {
        $parsed_docblock = DocComment::parsePreservingLength($comment);

        return self::arrayToDocblocks(
            $comment,
            $parsed_docblock,
            $source,
            $aliases,
            $template_type_map,
            $type_aliases
        );
    }

    /**
     * @param  array<string, array<string, array{Type\Union}>>|null   $template_type_map
     * @param  array<string, array<int, array{0: string, 1: int}>> $type_aliases
     * @param array{description:string, specials:array<string, array<int, string>>} $parsed_docblock
     *
     * @return VarDocblockComment[]
     *
     * @throws DocblockParseException if there was a problem parsing the docblock
     */
    public static function arrayToDocblocks(
        PhpParser\Comment\Doc $comment,
        array $parsed_docblock,
        FileSource $source,
        Aliases $aliases,
        array $template_type_map = null,
        ?array $type_aliases = null
    ) : array {
        $var_id = null;

        $var_type_tokens = null;
        $original_type = null;

        $var_comments = [];

        $comment_text = $comment->getText();

        if (!isset($parsed_docblock['specials']['var']) && !isset($parsed_docblock['specials']['psalm-var'])) {
            return [];
        }

        $var_line_number = $comment->getLine();

        if ($parsed_docblock) {
            $all_vars = (isset($parsed_docblock['specials']['var']) ? $parsed_docblock['specials']['var'] : [])
                + (isset($parsed_docblock['specials']['psalm-var']) ? $parsed_docblock['specials']['psalm-var'] : []);

            foreach ($all_vars as $offset => $var_line) {
                $var_line = trim($var_line);

                if (!$var_line) {
                    continue;
                }

                $type_start = null;
                $type_end = null;

                $line_parts = self::splitDocLine($var_line);

                $line_number = $comment->getLine() + substr_count($comment_text, "\n", 0, $offset);

                if ($line_parts && $line_parts[0]) {
                    $type_start = $offset + $comment->getFilePos();
                    $type_end = $type_start + strlen($line_parts[0]);

                    $line_parts[0] = preg_replace('@^[ \t]*\*@m', '', $line_parts[0]);
                    $line_parts[0] = preg_replace('/,\n\s+\}/', '}', $line_parts[0]);
                    $line_parts[0] = str_replace("\n", '', $line_parts[0]);

                    if ($line_parts[0] === ''
                        || ($line_parts[0][0] === '$'
                            && !preg_match('/^\$this(\||$)/', $line_parts[0]))
                    ) {
                        throw new IncorrectDocblockException('Misplaced variable');
                    }

                    try {
                        $var_type_tokens = Type::fixUpLocalType(
                            $line_parts[0],
                            $aliases,
                            $template_type_map,
                            $type_aliases
                        );
                    } catch (TypeParseTreeException $e) {
                        throw new DocblockParseException($line_parts[0] . ' is not a valid type');
                    }

                    $original_type = $line_parts[0];

                    $var_line_number = $line_number;

                    if (count($line_parts) > 1 && $line_parts[1][0] === '$') {
                        $var_id = $line_parts[1];
                    }
                }

                if (!$var_type_tokens || !$original_type) {
                    continue;
                }

                try {
                    $defined_type = Type::parseTokens($var_type_tokens, null, $template_type_map ?: []);
                } catch (TypeParseTreeException $e) {
                    throw new DocblockParseException(
                        $line_parts[0] .
                        ' is not a valid type' .
                        ' (from ' .
                        $source->getFilePath() .
                        ':' .
                        $comment->getLine() .
                        ')'
                    );
                }

                $defined_type->setFromDocblock();

                $var_comment = new VarDocblockComment();
                $var_comment->type = $defined_type;
                $var_comment->original_type = $original_type;
                $var_comment->var_id = $var_id;
                $var_comment->line_number = $var_line_number;
                $var_comment->type_start = $type_start;
                $var_comment->type_end = $type_end;
                $var_comment->deprecated = isset($parsed_docblock['specials']['deprecated']);
                $var_comment->internal = isset($parsed_docblock['specials']['internal']);
                if (isset($parsed_docblock['specials']['psalm-internal'])) {
                    $psalm_internal = reset($parsed_docblock['specials']['psalm-internal']);
                    if ($psalm_internal) {
                        $var_comment->psalm_internal = $psalm_internal;
                    } else {
                        throw new DocblockParseException('psalm-internal annotation used without specifying namespace');
                    }
                    $var_comment->psalm_internal = reset($parsed_docblock['specials']['psalm-internal']);

                    if (!$var_comment->internal) {
                            throw new DocblockParseException('@psalm-internal annotation used without @internal');
                    }
                }

                $var_comments[] = $var_comment;
            }
        }

        return $var_comments;
    }

    /**
     * @param  Aliases          $aliases
     * @param  array<string, array<int, array{0: string, 1: int}>> $type_aliases
     *
     * @throws DocblockParseException if there was a problem parsing the docblock
     *
     * @return array<string, array<int, array{0: string, 1: int}>>
     */
    public static function getTypeAliasesFromComment(
        PhpParser\Comment\Doc $comment,
        Aliases $aliases,
        array $type_aliases = null
    ) {
        $parsed_docblock = DocComment::parsePreservingLength($comment);

        if (!isset($parsed_docblock['specials']['psalm-type'])) {
            return [];
        }

        return self::getTypeAliasesFromCommentLines(
            $parsed_docblock['specials']['psalm-type'],
            $aliases,
            $type_aliases
        );
    }

    /**
     * @param  array<string>    $type_alias_comment_lines
     * @param  Aliases          $aliases
     * @param  array<string, array<int, array{0: string, 1: int}>> $type_aliases
     *
     * @throws DocblockParseException if there was a problem parsing the docblock
     *
     * @return array<string, array<int, array{0: string, 1: int}>>
     */
    private static function getTypeAliasesFromCommentLines(
        array $type_alias_comment_lines,
        Aliases $aliases,
        array $type_aliases = null
    ) {
        $type_alias_tokens = [];

        foreach ($type_alias_comment_lines as $var_line) {
            $var_line = trim($var_line);

            if (!$var_line) {
                continue;
            }

            $var_line = preg_replace('/[ \t]+/', ' ', preg_replace('@^[ \t]*\*@m', '', $var_line));

            $var_line_parts = preg_split('/( |=)/', $var_line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

            if (!$var_line_parts) {
                continue;
            }

            $type_alias = array_shift($var_line_parts);

            if (!isset($var_line_parts[0])) {
                continue;
            }

            if ($var_line_parts[0] === ' ') {
                array_shift($var_line_parts);
            }

            if ($var_line_parts[0] === '=') {
                array_shift($var_line_parts);
            }

            if (!isset($var_line_parts[0])) {
                continue;
            }

            if ($var_line_parts[0] === ' ') {
                array_shift($var_line_parts);
            }

            $type_string = str_replace("\n", '', implode('', $var_line_parts));

            $type_string = preg_replace('/>[^>^\}]*$/', '>', $type_string);
            $type_string = preg_replace('/\}[^>^\}]*$/', '}', $type_string);

            try {
                $type_tokens = Type::fixUpLocalType(
                    $type_string,
                    $aliases,
                    null,
                    $type_alias_tokens + $type_aliases
                );
            } catch (TypeParseTreeException $e) {
                throw new DocblockParseException($type_string . ' is not a valid type');
            }

            $type_alias_tokens[$type_alias] = $type_tokens;
        }

        return $type_alias_tokens;
    }

    /**
     * @param  int     $line_number
     *
     * @throws DocblockParseException if there was a problem parsing the docblock
     *
     * @return FunctionDocblockComment
     * @psalm-suppress MixedArrayAccess
     */
    public static function extractFunctionDocblockInfo(PhpParser\Comment\Doc $comment)
    {
        $parsed_docblock = DocComment::parsePreservingLength($comment);

        $comment_text = $comment->getText();

        $info = new FunctionDocblockComment();

        if (isset($parsed_docblock['specials']['return']) || isset($parsed_docblock['specials']['psalm-return'])) {
            /** @var array<int, string> */
            $return_specials = isset($parsed_docblock['specials']['psalm-return'])
                ? $parsed_docblock['specials']['psalm-return']
                : $parsed_docblock['specials']['return'];

            self::extractReturnType(
                $comment,
                $return_specials,
                $info
            );
        }

        if (isset($parsed_docblock['specials']['param']) || isset($parsed_docblock['specials']['psalm-param'])) {
            $all_params =
                (isset($parsed_docblock['specials']['param'])
                    ? $parsed_docblock['specials']['param']
                    : [])
                + (isset($parsed_docblock['specials']['psalm-param'])
                    ? $parsed_docblock['specials']['psalm-param']
                    : []);

            /** @var string $param */
            foreach ($all_params as $offset => $param) {
                $line_parts = self::splitDocLine($param);

                if (count($line_parts) === 1 && isset($line_parts[0][0]) && $line_parts[0][0] === '$') {
                    continue;
                }

                if (count($line_parts) > 1) {
                    if (preg_match('/^&?(\.\.\.)?&?\$[A-Za-z0-9_]+,?$/', $line_parts[1])
                        && $line_parts[0][0] !== '{'
                    ) {
                        $line_parts[1] = str_replace('&', '', $line_parts[1]);

                        $line_parts[1] = preg_replace('/,$/', '', $line_parts[1]);

                        $start = $offset + $comment->getFilePos();
                        $end = $start + strlen($line_parts[0]);

                        $line_parts[0] = preg_replace('@^[ \t]*\*@m', '', $line_parts[0]);
                        $line_parts[0] = preg_replace('/,\n\s+\}/', '}', $line_parts[0]);
                        $line_parts[0] = str_replace("\n", '', $line_parts[0]);

                        if ($line_parts[0] === ''
                            || ($line_parts[0][0] === '$'
                                && !preg_match('/^\$this(\||$)/', $line_parts[0]))
                        ) {
                            throw new IncorrectDocblockException('Misplaced variable');
                        }

                        $info->params[] = [
                            'name' => trim($line_parts[1]),
                            'type' => $line_parts[0],
                            'line_number' => $comment->getLine() + substr_count($comment_text, "\n", 0, $offset),
                            'start' => $start,
                            'end' => $end,
                        ];
                    }
                } else {
                    throw new DocblockParseException('Badly-formatted @param');
                }
            }
        }

        if (isset($parsed_docblock['specials']['param-out'])) {
            /** @var string $param */
            foreach ($parsed_docblock['specials']['param-out'] as $offset => $param) {
                $line_parts = self::splitDocLine($param);

                if (count($line_parts) === 1 && isset($line_parts[0][0]) && $line_parts[0][0] === '$') {
                    continue;
                }

                if (count($line_parts) > 1) {
                    if (!preg_match('/\[[^\]]+\]/', $line_parts[0])
                        && preg_match('/^(\.\.\.)?&?\$[A-Za-z0-9_]+,?$/', $line_parts[1])
                        && $line_parts[0][0] !== '{'
                    ) {
                        if ($line_parts[1][0] === '&') {
                            $line_parts[1] = substr($line_parts[1], 1);
                        }

                        $line_parts[0] = str_replace("\n", '', preg_replace('@^[ \t]*\*@m', '', $line_parts[0]));

                        if ($line_parts[0] === ''
                            || ($line_parts[0][0] === '$'
                                && !preg_match('/^\$this(\||$)/', $line_parts[0]))
                        ) {
                            throw new IncorrectDocblockException('Misplaced variable');
                        }

                        $line_parts[1] = preg_replace('/,$/', '', $line_parts[1]);

                        $info->params_out[] = [
                            'name' => trim($line_parts[1]),
                            'type' => str_replace("\n", '', $line_parts[0]),
                            'line_number' => $comment->getLine() + substr_count($comment_text, "\n", 0, $offset),
                        ];
                    }
                } else {
                    throw new DocblockParseException('Badly-formatted @param');
                }
            }
        }

        if (isset($parsed_docblock['specials']['global'])) {
            foreach ($parsed_docblock['specials']['global'] as $offset => $global) {
                $line_parts = self::splitDocLine($global);

                if (count($line_parts) === 1 && isset($line_parts[0][0]) && $line_parts[0][0] === '$') {
                    continue;
                }

                if (count($line_parts) > 1) {
                    if (!preg_match('/\[[^\]]+\]/', $line_parts[0])
                        && preg_match('/^(\.\.\.)?&?\$[A-Za-z0-9_]+,?$/', $line_parts[1])
                        && $line_parts[0][0] !== '{'
                    ) {
                        if ($line_parts[1][0] === '&') {
                            $line_parts[1] = substr($line_parts[1], 1);
                        }

                        if ($line_parts[0][0] === '$' && !preg_match('/^\$this(\||$)/', $line_parts[0])) {
                            throw new IncorrectDocblockException('Misplaced variable');
                        }

                        $line_parts[1] = preg_replace('/,$/', '', $line_parts[1]);

                        $info->globals[] = [
                            'name' => $line_parts[1],
                            'type' => $line_parts[0],
                            'line_number' => $comment->getLine() + substr_count($comment_text, "\n", 0, $offset),
                        ];
                    }
                } else {
                    throw new DocblockParseException('Badly-formatted @param');
                }
            }
        }

        if (isset($parsed_docblock['specials']['deprecated'])) {
            $info->deprecated = true;
        }

        if (isset($parsed_docblock['specials']['internal'])) {
            $info->internal = true;
        }

        if (isset($parsed_docblock['specials']['psalm-internal'])) {
            $psalm_internal = reset($parsed_docblock['specials']['psalm-internal']);
            if ($psalm_internal) {
                $info->psalm_internal = $psalm_internal;
            } else {
                throw new DocblockParseException('@psalm-internal annotation used without specifying namespace');
            }
            $info->psalm_internal = reset($parsed_docblock['specials']['psalm-internal']);

            if (! $info->internal) {
                throw new DocblockParseException('@psalm-internal annotation used without @internal');
            }
        }



        if (isset($parsed_docblock['specials']['psalm-suppress'])) {
            foreach ($parsed_docblock['specials']['psalm-suppress'] as $suppress_entry) {
                $info->suppress[] = preg_split('/[\s]+/', $suppress_entry)[0];
            }
        }

        if (isset($parsed_docblock['specials']['throws'])) {
            foreach ($parsed_docblock['specials']['throws'] as $throws_entry) {
                $throws_class = preg_split('/[\s]+/', $throws_entry)[0];

                if (!$throws_class) {
                    throw new IncorrectDocblockException('Unexpectedly empty @throws');
                }

                $info->throws[] = $throws_class;
            }
        }

        if (strpos(strtolower($parsed_docblock['description']), '@inheritdoc') !== false
            || isset($parsed_docblock['specials']['inheritdoc']) || isset($parsed_docblock['specials']['inheritDoc'])) {
            $info->inheritdoc = true;
        }

        if (isset($parsed_docblock['specials']['template']) || isset($parsed_docblock['specials']['psalm-template'])) {
            $all_templates
                = (isset($parsed_docblock['specials']['template'])
                    ? $parsed_docblock['specials']['template']
                    : [])
                + (isset($parsed_docblock['specials']['psalm-template'])
                    ? $parsed_docblock['specials']['psalm-template']
                    : []);

            foreach ($all_templates as $template_line) {
                $template_type = preg_split('/[\s]+/', preg_replace('@^[ \t]*\*@m', '', $template_line));

                $template_name = array_shift($template_type);

                if (count($template_type) > 1
                    && in_array(strtolower($template_type[0]), ['as', 'super', 'of'], true)
                ) {
                    $template_modifier = strtolower(array_shift($template_type));
                    $info->templates[] = [
                        $template_name,
                        $template_modifier,
                        implode(' ', $template_type),
                        false
                    ];
                } else {
                    $info->templates[] = [$template_name, null, null, false];
                }
            }
        }

        if (isset($parsed_docblock['specials']['template-covariant'])
            || isset($parsed_docblock['specials']['psalm-template-covariant'])
        ) {
            $all_templates =
                (isset($parsed_docblock['specials']['template-covariant'])
                    ? $parsed_docblock['specials']['template-covariant']
                    : [])
                + (isset($parsed_docblock['specials']['psalm-template-covariant'])
                    ? $parsed_docblock['specials']['psalm-template-covariant']
                    : []);

            foreach ($all_templates as $template_line) {
                $template_type = preg_split('/[\s]+/', preg_replace('@^[ \t]*\*@m', '', $template_line));

                $template_name = array_shift($template_type);

                if (count($template_type) > 1
                    && in_array(strtolower($template_type[0]), ['as', 'super', 'of'], true)
                ) {
                    $template_modifier = strtolower(array_shift($template_type));
                    $info->templates[] = [
                        $template_name,
                        $template_modifier,
                        implode(' ', $template_type),
                        true
                    ];
                } else {
                    $info->templates[] = [$template_name, null, null, true];
                }
            }
        }

        if (isset($parsed_docblock['specials']['template-typeof'])) {
            foreach ($parsed_docblock['specials']['template-typeof'] as $template_typeof) {
                $typeof_parts = preg_split('/[\s]+/', preg_replace('@^[ \t]*\*@m', '', $template_typeof));

                if (count($typeof_parts) < 2 || $typeof_parts[1][0] !== '$') {
                    throw new IncorrectDocblockException('Misplaced variable');
                }

                $info->template_typeofs[] = [
                    'template_type' => $typeof_parts[0],
                    'param_name' => substr($typeof_parts[1], 1),
                ];
            }
        }

        if (isset($parsed_docblock['specials']['psalm-assert'])) {
            foreach ($parsed_docblock['specials']['psalm-assert'] as $assertion) {
                $assertion_parts = preg_split('/[\s]+/', preg_replace('@^[ \t]*\*@m', '', $assertion));

                if (count($assertion_parts) < 2 || $assertion_parts[1][0] !== '$') {
                    throw new IncorrectDocblockException('Misplaced variable');
                }

                $info->assertions[] = [
                    'type' => $assertion_parts[0],
                    'param_name' => substr($assertion_parts[1], 1),
                ];
            }
        }

        if (isset($parsed_docblock['specials']['psalm-assert-if-true'])) {
            foreach ($parsed_docblock['specials']['psalm-assert-if-true'] as $assertion) {
                $assertion_parts = preg_split('/[\s]+/', preg_replace('@^[ \t]*\*@m', '', $assertion));

                if (count($assertion_parts) < 2 || $assertion_parts[1][0] !== '$') {
                    throw new IncorrectDocblockException('Misplaced variable');
                }

                $info->if_true_assertions[] = [
                    'type' => $assertion_parts[0],
                    'param_name' => substr($assertion_parts[1], 1),
                ];
            }
        }

        if (isset($parsed_docblock['specials']['psalm-assert-if-false'])) {
            foreach ($parsed_docblock['specials']['psalm-assert-if-false'] as $assertion) {
                $assertion_parts = preg_split('/[\s]+/', preg_replace('@^[ \t]*\*@m', '', $assertion));

                if (count($assertion_parts) < 2 || $assertion_parts[1][0] !== '$') {
                    throw new IncorrectDocblockException('Misplaced variable');
                }

                $info->if_false_assertions[] = [
                    'type' => $assertion_parts[0],
                    'param_name' => substr($assertion_parts[1], 1),
                ];
            }
        }

        $info->variadic = isset($parsed_docblock['specials']['psalm-variadic']);
        $info->ignore_nullable_return = isset($parsed_docblock['specials']['psalm-ignore-nullable-return']);
        $info->ignore_falsable_return = isset($parsed_docblock['specials']['psalm-ignore-falsable-return']);

        return $info;
    }

    /**
     * @param array<int, string> $return_specials
     * @return void
     */
    private static function extractReturnType(
        PhpParser\Comment\Doc $comment,
        array $return_specials,
        FunctionDocblockComment $info
    ) {
        foreach ($return_specials as $offset => $return_block) {
            $return_lines = explode("\n", $return_block);

            if (!trim($return_lines[0])) {
                return;
            }

            $return_block = trim($return_block);

            if (!$return_block) {
                return;
            }

            $line_parts = self::splitDocLine($return_block);

            if ($line_parts[0][0] !== '{') {
                if ($line_parts[0][0] === '$' && !preg_match('/^\$this(\||$)/', $line_parts[0])) {
                    throw new IncorrectDocblockException('Misplaced variable');
                }

                $start = $offset + $comment->getFilePos();
                $end = $start + strlen($line_parts[0]);

                $line_parts[0] = str_replace("\n", '', preg_replace('@^[ \t]*\*@m', '', $line_parts[0]));

                $info->return_type = str_replace("\n", '', array_shift($line_parts));
                $info->return_type_description = $line_parts ? implode(' ', $line_parts) : null;

                $info->return_type_line_number
                    = $comment->getLine() + substr_count($comment->getText(), "\n", 0, $offset);
                $info->return_type_start = $start;
                $info->return_type_end = $end;
            } else {
                throw new DocblockParseException('Badly-formatted @return type');
            }

            break;
        }
    }

    /**
     * @throws DocblockParseException if there was a problem parsing the docblock
     *
     * @return ClassLikeDocblockComment
     * @psalm-suppress MixedArrayAccess
     */
    public static function extractClassLikeDocblockInfo(\PhpParser\Node $node, PhpParser\Comment\Doc $comment)
    {
        $parsed_docblock = DocComment::parsePreservingLength($comment);

        $info = new ClassLikeDocblockComment();

        if (isset($parsed_docblock['specials']['template']) || isset($parsed_docblock['specials']['psalm-template'])) {
            $all_templates
                = (isset($parsed_docblock['specials']['template'])
                    ? $parsed_docblock['specials']['template']
                    : [])
                + (isset($parsed_docblock['specials']['psalm-template'])
                    ? $parsed_docblock['specials']['psalm-template']
                    : []);

            foreach ($all_templates as $template_line) {
                $template_type = preg_split('/[\s]+/', preg_replace('@^[ \t]*\*@m', '', $template_line));

                $template_name = array_shift($template_type);

                if (count($template_type) > 1
                    && in_array(strtolower($template_type[0]), ['as', 'super', 'of'], true)
                ) {
                    $template_modifier = strtolower(array_shift($template_type));
                    $info->templates[] = [
                        $template_name,
                        $template_modifier,
                        implode(' ', $template_type),
                        false
                    ];
                } else {
                    $info->templates[] = [$template_name, null, null, false];
                }
            }
        }

        if (isset($parsed_docblock['specials']['template-covariant'])
            || isset($parsed_docblock['specials']['psalm-template-covariant'])
        ) {
            $all_templates =
                (isset($parsed_docblock['specials']['template-covariant'])
                    ? $parsed_docblock['specials']['template-covariant']
                    : [])
                + (isset($parsed_docblock['specials']['psalm-template-covariant'])
                    ? $parsed_docblock['specials']['psalm-template-covariant']
                    : []);

            foreach ($all_templates as $template_line) {
                $template_type = preg_split('/[\s]+/', preg_replace('@^[ \t]*\*@m', '', $template_line));

                $template_name = array_shift($template_type);

                if (count($template_type) > 1
                    && in_array(strtolower($template_type[0]), ['as', 'super', 'of'], true)
                ) {
                    $template_modifier = strtolower(array_shift($template_type));
                    $info->templates[] = [
                        $template_name,
                        $template_modifier,
                        implode(' ', $template_type),
                        true
                    ];
                } else {
                    $info->templates[] = [$template_name, null, null, true];
                }
            }
        }

        if (isset($parsed_docblock['specials']['template-extends'])
            || isset($parsed_docblock['specials']['inherits'])
            || isset($parsed_docblock['specials']['extends'])
        ) {
            $all_inheritance = array_merge(
                $parsed_docblock['specials']['template-extends'] ?? [],
                $parsed_docblock['specials']['inherits'] ?? [],
                $parsed_docblock['specials']['extends'] ?? []
            );

            foreach ($all_inheritance as $template_line) {
                $info->template_extends[] = trim(preg_replace('@^[ \t]*\*@m', '', $template_line));
            }
        }

        if (isset($parsed_docblock['specials']['template-implements'])
            || isset($parsed_docblock['specials']['implements'])
        ) {
            $all_inheritance = array_merge(
                $parsed_docblock['specials']['template-implements'] ?? [],
                $parsed_docblock['specials']['implements'] ?? []
            );

            foreach ($all_inheritance as $template_line) {
                $info->template_implements[] = trim(preg_replace('@^[ \t]*\*@m', '', $template_line));
            }
        }

        if (isset($parsed_docblock['specials']['deprecated'])) {
            $info->deprecated = true;
        }

        if (isset($parsed_docblock['specials']['internal'])) {
            $info->internal = true;
        }

        if (isset($parsed_docblock['specials']['psalm-internal'])) {
            $psalm_internal = reset($parsed_docblock['specials']['psalm-internal']);
            if ($psalm_internal) {
                $info->psalm_internal = $psalm_internal;
            } else {
                throw new DocblockParseException('psalm-internal annotation used without specifying namespace');
            }

            if (! $info->internal) {
                throw new DocblockParseException('@psalm-internal annotation used without @internal');
            }
        }

        if (isset($parsed_docblock['specials']['psalm-seal-properties'])) {
            $info->sealed_properties = true;
        }

        if (isset($parsed_docblock['specials']['psalm-seal-methods'])) {
            $info->sealed_methods = true;
        }

        if (isset($parsed_docblock['specials']['psalm-override-property-visibility'])) {
            $info->override_property_visibility = true;
        }

        if (isset($parsed_docblock['specials']['psalm-override-method-visibility'])) {
            $info->override_method_visibility = true;
        }

        if (isset($parsed_docblock['specials']['psalm-suppress'])) {
            foreach ($parsed_docblock['specials']['psalm-suppress'] as $suppress_entry) {
                $info->suppressed_issues[] = preg_split('/[\s]+/', $suppress_entry)[0];
            }
        }

        if (isset($parsed_docblock['specials']['method']) || isset($parsed_docblock['specials']['psalm-method'])) {
            $all_methods
                = (isset($parsed_docblock['specials']['method'])
                    ? $parsed_docblock['specials']['method']
                    : [])
                + (isset($parsed_docblock['specials']['psalm-method'])
                    ? $parsed_docblock['specials']['psalm-method']
                    : []);

            foreach ($all_methods as $offset => $method_entry) {
                $method_entry = preg_replace('/[ \t]+/', ' ', trim($method_entry));

                $docblock_lines = [];

                $is_static = false;

                if (!preg_match('/^([a-z_A-Z][a-z_0-9A-Z]+) *\(/', $method_entry, $matches)) {
                    $doc_line_parts = self::splitDocLine($method_entry);

                    if ($doc_line_parts[0] === 'static' && !strpos($doc_line_parts[1], '(')) {
                        $is_static = true;
                        array_shift($doc_line_parts);
                    }

                    $docblock_lines[] = '@return ' . array_shift($doc_line_parts);

                    $method_entry = implode(' ', $doc_line_parts);
                }

                $method_entry = trim(preg_replace('/\/\/.*/', '', $method_entry));

                $end_of_method_regex = '/(?<!array\()\) ?(\: ?(\??[\\\\a-zA-Z0-9_]+))?/';

                if (preg_match($end_of_method_regex, $method_entry, $matches, PREG_OFFSET_CAPTURE)) {
                    $method_entry = substr($method_entry, 0, (int) $matches[0][1] + strlen((string) $matches[0][0]));
                }

                $method_entry = str_replace([', ', '( '], [',', '('], $method_entry);
                $method_entry = preg_replace('/ (?!(\$|\.\.\.|&))/', '', trim($method_entry));

                try {
                    $method_tree = ParseTree::createFromTokens(Type::tokenize($method_entry, false));
                } catch (TypeParseTreeException $e) {
                    throw new DocblockParseException($method_entry . ' is not a valid method');
                }

                if (!$method_tree instanceof ParseTree\MethodWithReturnTypeTree
                    && !$method_tree instanceof ParseTree\MethodTree) {
                    throw new DocblockParseException($method_entry . ' is not a valid method');
                }

                if ($method_tree instanceof ParseTree\MethodWithReturnTypeTree) {
                    $docblock_lines[] = '@return ' . Type::getTypeFromTree($method_tree->children[1]);
                    $method_tree = $method_tree->children[0];
                }

                if (!$method_tree instanceof ParseTree\MethodTree) {
                    throw new DocblockParseException($method_entry . ' is not a valid method');
                }

                $args = [];

                foreach ($method_tree->children as $method_tree_child) {
                    if (!$method_tree_child instanceof ParseTree\MethodParamTree) {
                        throw new DocblockParseException($method_entry . ' is not a valid method');
                    }

                    $args[] = ($method_tree_child->byref ? '&' : '')
                        . ($method_tree_child->variadic ? '...' : '')
                        . $method_tree_child->name
                        . ($method_tree_child->default != '' ? ' = ' . $method_tree_child->default : '');


                    if ($method_tree_child->children) {
                        $param_type = Type::getTypeFromTree($method_tree_child->children[0]);
                        $docblock_lines[] = '@param ' . $param_type . ' '
                            . ($method_tree_child->variadic ? '...' : '')
                            . $method_tree_child->name;
                    }
                }

                $function_string = 'function ' . $method_tree->value . '(' . implode(', ', $args) . ')';

                if ($is_static) {
                    $function_string = 'static ' . $function_string;
                }

                $function_docblock = $docblock_lines ? "/**\n * " . implode("\n * ", $docblock_lines) . "\n*/\n" : "";

                $php_string = '<?php class A { ' . $function_docblock . ' public ' . $function_string . '{} }';

                try {
                    $statements = \Psalm\Internal\Provider\StatementsProvider::parseStatements($php_string);
                } catch (\Exception $e) {
                    throw new DocblockParseException('Badly-formatted @method string ' . $method_entry);
                }

                if (!$statements[0] instanceof \PhpParser\Node\Stmt\Class_
                    || !isset($statements[0]->stmts[0])
                    || !$statements[0]->stmts[0] instanceof \PhpParser\Node\Stmt\ClassMethod
                ) {
                    throw new DocblockParseException('Badly-formatted @method string ' . $method_entry);
                }

                /** @var \PhpParser\Comment\Doc */
                $node_doc_comment = $node->getDocComment();

                $statements[0]->stmts[0]->setAttribute('startLine', $node_doc_comment->getLine());
                $statements[0]->stmts[0]->setAttribute('startFilePos', $node_doc_comment->getFilePos());
                $statements[0]->stmts[0]->setAttribute('endFilePos', $node->getAttribute('startFilePos'));

                if ($doc_comment = $statements[0]->stmts[0]->getDocComment()) {
                    $statements[0]->stmts[0]->setDocComment(
                        new \PhpParser\Comment\Doc(
                            $doc_comment->getText(),
                            $comment->getLine() + substr_count($comment->getText(), "\n", 0, $offset),
                            $node_doc_comment->getFilePos()
                        )
                    );
                }

                $info->methods[] = $statements[0]->stmts[0];
            }
        }

        self::addMagicPropertyToInfo($comment, $info, $parsed_docblock['specials'], 'property');
        self::addMagicPropertyToInfo($comment, $info, $parsed_docblock['specials'], 'psalm-property');
        self::addMagicPropertyToInfo($comment, $info, $parsed_docblock['specials'], 'property-read');
        self::addMagicPropertyToInfo($comment, $info, $parsed_docblock['specials'], 'property-write');

        return $info;
    }

    /**
     * @param ClassLikeDocblockComment $info
     * @param array<string, array<int, string>> $specials
     * @param 'property'|'psalm-property'|'property-read'|'property-write' $property_tag
     *
     * @throws DocblockParseException
     *
     * @return void
     */
    protected static function addMagicPropertyToInfo(
        PhpParser\Comment\Doc $comment,
        ClassLikeDocblockComment $info,
        array $specials,
        string $property_tag
    ) : void {
        $magic_property_comments = isset($specials[$property_tag]) ? $specials[$property_tag] : [];

        foreach ($magic_property_comments as $offset => $property) {
            $line_parts = self::splitDocLine($property);

            if (count($line_parts) === 1 && isset($line_parts[0][0]) && $line_parts[0][0] === '$') {
                continue;
            }

            if (count($line_parts) > 1) {
                if (preg_match('/^&?\$[A-Za-z0-9_]+,?$/', $line_parts[1])
                    && $line_parts[0][0] !== '{'
                ) {
                    $line_parts[1] = str_replace('&', '', $line_parts[1]);

                    $line_parts[1] = preg_replace('/,$/', '', $line_parts[1]);

                    $start = $offset + $comment->getFilePos();
                    $end = $start + strlen($line_parts[0]);

                    $line_parts[0] = str_replace("\n", '', preg_replace('@^[ \t]*\*@m', '', $line_parts[0]));

                    if ($line_parts[0] === ''
                        || ($line_parts[0][0] === '$'
                            && !preg_match('/^\$this(\||$)/', $line_parts[0]))
                    ) {
                        throw new IncorrectDocblockException('Misplaced variable');
                    }

                    $info->properties[] = [
                        'name' => trim($line_parts[1]),
                        'type' => $line_parts[0],
                        'line_number' => $comment->getLine() + substr_count($comment->getText(), "\n", 0, $offset),
                        'tag' => $property_tag,
                        'start' => $start,
                        'end' => $end,
                    ];
                }
            } else {
                throw new DocblockParseException('Badly-formatted @property');
            }
        }
    }

    /**
     * @param  string $return_block
     *
     * @throws DocblockParseException if an invalid string is found
     *
     * @return array<string>
     */
    public static function splitDocLine($return_block)
    {
        $brackets = '';

        $type = '';

        $expects_callable_return = false;

        $return_block = str_replace("\t", ' ', $return_block);

        $quote_char = null;
        $escaped = false;

        for ($i = 0, $l = strlen($return_block); $i < $l; ++$i) {
            $char = $return_block[$i];
            $next_char = $i < $l - 1 ? $return_block[$i + 1] : null;
            $last_char = $i > 0 ? $return_block[$i - 1] : null;

            if ($quote_char) {
                if ($char === $quote_char && $i > 1 && !$escaped) {
                    $quote_char = null;

                    $type .= $char;

                    continue;
                }

                if ($char === '\\' && !$escaped && ($next_char === $quote_char || $next_char === '\\')) {
                    $escaped = true;

                    $type .= $char;

                    continue;
                }

                $escaped = false;

                $type .= $char;

                continue;
            }

            if ($char === '"' || $char === '\'') {
                $quote_char = $char;

                $type .= $char;

                continue;
            }

            if ($char === ':' && $last_char === ')') {
                $expects_callable_return = true;

                $type .= $char;

                continue;
            }

            if ($char === '[' || $char === '{' || $char === '(' || $char === '<') {
                $brackets .= $char;
            } elseif ($char === ']' || $char === '}' || $char === ')' || $char === '>') {
                $last_bracket = substr($brackets, -1);
                $brackets = substr($brackets, 0, -1);

                if (($char === ']' && $last_bracket !== '[')
                    || ($char === '}' && $last_bracket !== '{')
                    || ($char === ')' && $last_bracket !== '(')
                    || ($char === '>' && $last_bracket !== '<')
                ) {
                    throw new DocblockParseException('Invalid string ' . $return_block);
                }
            } elseif ($char === ' ') {
                if ($brackets) {
                    $expects_callable_return = false;
                    $type .= ' ';
                    continue;
                }

                if ($next_char === '|' || $next_char === '&') {
                    $nexter_char = $i < $l - 2 ? $return_block[$i + 2] : null;

                    if ($nexter_char === ' ') {
                        ++$i;
                        $type .= $next_char . ' ';
                        continue;
                    }
                }

                if ($last_char === '|' || $last_char === '&') {
                    $type .= ' ';
                    continue;
                }

                if ($next_char === ':') {
                    ++$i;
                    $type .= ' :';
                    $expects_callable_return = true;
                    continue;
                }

                if ($expects_callable_return) {
                    $type .= ' ';
                    $expects_callable_return = false;
                    continue;
                }

                $remaining = trim(preg_replace('@^[ \t]*\* *@m', ' ', substr($return_block, $i + 1)));

                if ($remaining) {
                    /** @var array<string> */
                    return array_merge([rtrim($type)], preg_split('/[ \s]+/', $remaining));
                }

                return [$type];
            }

            $expects_callable_return = false;

            $type .= $char;
        }

        return [$type];
    }
}
