<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use InvalidArgumentException;

/**
 * Long-option parser for `php master/cli/<tool>.php <command> [--opt value]`.
 *
 * PHP's getopt() stops at the first non-option argument, so options written
 * after a sub-command (`render --host X`) were silently ignored. This parses
 * the arguments after the sub-command instead.
 */
final class CliOptions
{
    /**
     * @param list<string> $args arguments after the sub-command
     * @param list<string> $valueOptions options that take a value (--name value or --name=value)
     * @param list<string> $flagOptions options without a value
     * @return array<string, string|true>
     */
    public static function parse(array $args, array $valueOptions, array $flagOptions = []): array
    {
        $options = [];
        $count = count($args);
        for ($index = 0; $index < $count; $index++) {
            $arg = (string) $args[$index];
            if (!str_starts_with($arg, '--') || $arg === '--') {
                throw new InvalidArgumentException('unexpected argument: ' . $arg);
            }
            $name = substr($arg, 2);
            $value = null;
            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
            }
            if (in_array($name, $flagOptions, true)) {
                if ($value !== null) {
                    throw new InvalidArgumentException('--' . $name . ' takes no value');
                }
                $options[$name] = true;
                continue;
            }
            if (!in_array($name, $valueOptions, true)) {
                throw new InvalidArgumentException('unknown option: --' . $name);
            }
            if ($value === null) {
                if ($index + 1 >= $count || str_starts_with((string) $args[$index + 1], '--')) {
                    throw new InvalidArgumentException('--' . $name . ' needs a value');
                }
                $value = (string) $args[++$index];
            }
            $options[$name] = $value;
        }

        return $options;
    }
}
