<?php

declare(strict_types=1);

namespace Arix\Views;

use RuntimeException;

use function array_slice;
use function in_array;
use function is_array;
use function is_string;
use function strlen;

class TemplateCompiler
{
    public const string VERSION = '4';

    public function compile(string $source): string
    {
        $compiled = '';
        // Native PHP blocks are never rewritten, including strings and comments.
        foreach (token_get_all($source) as $token) {
            $compiled .= is_array($token)
                ? ($token[0] === T_INLINE_HTML ? $this->compileHtml($token[1]) : $token[1])
                : $token;
        }

        return $compiled;
    }

    private function compileHtml(string $text): string
    {
        $output = '';
        $length = strlen($text);
        for ($index = 0; $index < $length;) {
            if (substr($text, $index, 4) === '{{--') {
                $end = strpos($text, '--}}', $index + 4);
                if ($end === false) {
                    throw new RuntimeException('Unclosed template comment.');
                }
                $index = $end + 4;
                continue;
            }
            if (substr($text, $index, 2) === '{{' || substr($text, $index, 3) === '{!!') {
                $raw = substr($text, $index, 3) === '{!!';
                [$expression, $index] = $this->expression($text, $index + ($raw ? 3 : 2), $raw ? '!!}' : '}}');
                $output .= $raw ? '<?= ' . $expression . ' ?>' : '<?= $__arixContext->escape(' . $expression . ') ?>';
                continue;
            }
            if (substr($text, $index, 2) === '@@') {
                $output .= '@';
                $index += 2;
                continue;
            }
            if ($text[$index] === '@' && preg_match('/\G@([a-zA-Z]+)/', $text, $match, 0, $index)) {
                $name = $match[1];
                $simple = [
                    'else' => '<?php else: ?>',
                    'endif' => '<?php endif; ?>',
                    'endforeach' => '<?php endforeach; ?>',
                    'endfor' => '<?php endfor; ?>',
                    'endwhile' => '<?php endwhile; ?>',
                    'endsection' => '<?php $__arixContext->endSection(); ?>',
                    'endcomponent' => '<?= $__arixContext->endComponent() ?>',
                    'endslot' => '<?php $__arixContext->endSlot(); ?>',
                ];
                $argumentNames = ['if', 'elseif', 'foreach', 'for', 'while', 'extends', 'section', 'yield', 'include', 'component', 'slot', 'json'];
                if (isset($simple[$name])) {
                    $output .= $simple[$name];
                    $index += strlen($match[0]);
                    continue;
                }
                if (in_array($name, $argumentNames, true)) {
                    $start = $index + strlen($match[0]);
                    while ($start < $length && ctype_space($text[$start])) {
                        $start++;
                    }
                    if (($text[$start] ?? '') !== '(') {
                        throw new RuntimeException('Expected arguments for @' . $name);
                    }
                    [$expression, $index] = $this->expression($text, $start + 1, ')');
                    $output .= match ($name) {
                        'if', 'elseif', 'foreach', 'for', 'while' => '<?php ' . $name . ' (' . $expression . '): ?>',
                        'extends' => '<?php $__arixContext->extend(' . $expression . '); ?>',
                        'section' => '<?php $__arixContext->startSection(' . $expression . '); ?>',
                        'yield' => '<?= $__arixContext->yieldSection(' . $expression . ') ?>',
                        'include' => '<?= $__arixContext->partial(' . $expression . ') ?>',
                        'component' => '<?php $__arixContext->startComponent(' . $expression . '); ?>',
                        'slot' => '<?php $__arixContext->startSlot(' . $expression . '); ?>',
                        'json' => '<?= $__arixContext->json(' . $expression . ') ?>',
                    };
                    continue;
                }
            }
            $output .= $text[$index++];
        }

        return $output;
    }

    private function expression(string $text, int $start, string $delimiter): array
    {
        $tokens = token_get_all('<?php ' . substr($text, $start));
        $position = $start;
        $stack = [];
        $quote = null;
        $heredoc = false;
        foreach (array_slice($tokens, 1) as $token) {
            if ($stack === [] && $quote === null && !$heredoc && substr($text, $position, strlen($delimiter)) === $delimiter) {
                $expression = trim(substr($text, $start, $position - $start));
                if ($expression === '') {
                    throw new RuntimeException('Empty template expression.');
                }

                return [$expression, $position + strlen($delimiter)];
            }
            if (is_string($token)) {
                if ($token === '"' || $token === '`') {
                    $quote = $quote === $token ? null : ($quote ?? $token);
                } elseif (in_array($token, ['(', '[', '{'], true)) {
                    $stack[] = match ($token) { '(' => ')', '[' => ']', '{' => '}' };
                } elseif (in_array($token, [')', ']', '}'], true)) {
                    if (array_pop($stack) !== $token) {
                        throw new RuntimeException('Unbalanced template expression.');
                    }
                }
                $position += strlen($token);
            } else {
                if ($token[0] === T_START_HEREDOC) {
                    $heredoc = true;
                } elseif ($token[0] === T_END_HEREDOC) {
                    $heredoc = false;
                }
                // Interpolated strings may contain curly-open tokens.
                if (in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $stack[] = '}';
                }
                $position += strlen($token[1]);
            }
        }

        throw new RuntimeException('Unclosed template expression.');
    }
}
