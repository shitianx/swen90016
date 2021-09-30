<?php

namespace VersionPress\Utils;

/**
 * This class contains several functions for handling a small query language that is used in search
 * and for ignored & frequently written entities in plugin definitions.
 *
 * Rules of the language:
 *
 * 1. Basic search with wildcard support:
 *     - `just text` – matches all words
 *     - `"just text"` – exact match
 *     - `w*ldcards`
 * 2. `operator:value` syntax for operators, with optional spaces after the colon.
 * 3. Logical OR when the same operator is repeated: `operator:value1 operator:value2`.
 * 4. Logical AND for multiple operators: `operator1:value operator2:value`.
 * 5. Everything is case insensitive: `"search term" author: Joe` is equivalent to `"SEarCH TErm" Author: JOE`.
 *
 * See examples in https://regex101.com/r/wT6zG3/6.
 *
 * Notes on case (in)sensitivity:
 *
 * - Rules are parsed as they are, maintaining their original letter casing.
 * - When constructing Git search command, the `-i` flag is added to achieve case insensitivity (so it's up to Git).
 * - When matching rules for ignored and frequently written entities, case insensitive comparisons are done.
 * - When constructing SQL restrictions, for example, `(field LIKE "value")`, values are passed without any
 *   case conversion and it's up to the configuration of MySQL how it treats it. By default, WordPress
 *   sets the case insensitive `utf8_general_ci` collation.
 *
 * DEVELOPER NOTE: When updating this code, also think about our public documentation related to this, currently in:
 *
 * - searching-history.md
 * - plugin-support.md
 */
class QueryLanguageUtils
{

    const VALUE_WILDCARD = 'VALUE_WILDCARD';
    const VALUE_STRING = 'VALUE_STRING';

    // https://regex101.com/r/wT6zG3/6 (query language)
    private static $queryRegex = "/(-)?(?:(\\S+):\\s*)?(?:'((?:[^'\\\\]|\\\\.)*)'|\"((?:[^\"\\\\]|\\\\.)*)\"|(\\S+))/";

    // https://regex101.com/r/pL2zA2/3 (support for * wildcard)
    private static $valueWildcardRegex = "/(?:\\\\\\\\)|(?:\\\\\\*)|(?:\\*)|(?:[^\\\\\\*]+)/";

    /**
     * Transforms queries into arrays for easier manipulation.
     * Example of transformation of one query (method works with list):
     *  query = some_field: value other_field: other_value
     *  array = ['some_field' => ['value'], 'other_field' => ['other_value']]
     *
     * @param $queries
     * @param $allowEmpty boolean Allow empty values
     * @return array
     */
    public static function createRulesFromQueries($queries, $allowEmpty = false)
    {
        $rules = [];
        foreach ($queries as $query) {
            preg_match_all(self::$queryRegex, $query, $matches, PREG_SET_ORDER);
            $isValidRule = count($matches) > 0;
            if (!$isValidRule) {
                continue;
            }

            $ruleParts = [];
            foreach ($matches as $match) {

                // If a match is for an `operator:value` query, $match[2] is the operator
                $key = empty($match[2]) ? 'text' : $match[2];

                // Depending on how the value was quoted, it's available in a different index
                $value =
                    isset($match[5]) ? // unquoted value
                    $match[5] :
                    (isset($match[4]) ? // double quotes
                    $match[4] :
                    (isset($match[3]) ? // single quotes
                    $match[3] :
                    ''));

                if ($value !== '' || $allowEmpty) {
                    if (!isset($ruleParts[$key])) {
                        $ruleParts[$key] = [];
                    }
                    $ruleParts[$key][] = $value;
                }
            }

            $rules[] = $ruleParts;
        }
        return $rules;
    }

    /**
     * Tests if entity satisfies at least one of given rules.
     *
     * @param $entity
     * @param $rules
     * @return bool
     */
    public static function entityMatchesSomeRule($entity, $rules)
    {
        $entity = array_change_key_case($entity, CASE_LOWER);
        return ArrayUtils::any($rules, function ($rule) use ($entity) {
            // check all parts of rule
            return ArrayUtils::all($rule, function ($values, $field) use ($entity) {
                $field = strtolower($field);

                if (!isset($entity[$field])) {
                    return false;
                }

                $value = $values[0]; // Use single value per field
                $valueTokens = QueryLanguageUtils::tokenizeValue($value);
                $isWildcard = QueryLanguageUtils::tokensContainWildcard($valueTokens);

                if ($isWildcard && preg_match(QueryLanguageUtils::tokensToRegex($valueTokens), $entity[$field])) {
                    return true;
                } elseif (strtolower(strval($entity[$field])) === strtolower($value)) {
                    return true;
                }

                return false;
            });
        });
    }

    /**
     * Converts rule (array) to query string to be used as an argument for `git log`.
     *
     * @param $rawRule array
     * @return string
     */
    public static function createGitLogQueryFromRule($rawRule)
    {
        $query = '-i --all-match';

        $rule = [];
        foreach ($rawRule as $key => $array) {
            $escapedKey = self::escapeGitLogArgument($key);
            $rule[$escapedKey] = ($key === 'date')
                ? $rule[$escapedKey] = $array
                : array_map([QueryLanguageUtils::class, 'escapeGitLogArgument'], $array);
        }

        if (!empty($rule['author'])) {
            foreach ($rule['author'] as $value) {
                // name and email
                if (strpos($value, '@') && strpos($value, '<')) {
                    $query .= ' --author="^' . $value . '$"';
                } else {
                    // email only
                    if (strpos($value, '@')) {
                        $query .= ' --author="^.* <' . $value . '>$"';
                    } else {
                        // name only
                        $query .= ' --author="^' . $value . ' <.*>$"';
                    }
                }
            }
        }

        if (!empty($rule['date'])) {
            foreach ($rule['date'] as $value) {
                $val = preg_replace('/\s+/', '', $value);

                $bounds = explode('..', $val);
                if (count($bounds) > 1) {
                    if ($bounds[0] !== '*') {
                        $query .= ' --after=' . date('Y-m-d', strtotime($bounds[0] . ' -1 day'));
                    }
                    if ($bounds[1] !== '*') {
                        $query .= ' --before=' . date('Y-m-d', strtotime($bounds[1] . ' +1 day'));
                    }
                    continue;
                }

                if (in_array(($op = substr($val, 0, 2)), ['<=', '>='])) {
                    $date = substr($val, 2);
                } else {
                    if (in_array(($op = substr($val, 0, 1)), ['<', '>'])) {
                        $date = substr($val, 1);
                    } else {
                        $op = '';
                        $date = $val;
                    }
                };

                if ($op === '>=') {
                    $query .= ' --after=' . date('Y-m-d', strtotime($date . ' -1 day'));
                } else {
                    if ($op === '>') {
                        $query .= ' --after=' . date('Y-m-d', strtotime($date));
                    } else {
                        if ($op === '<=') {
                            $query .= ' --before=' . date('Y-m-d', strtotime($date));
                        } else {
                            if ($op === '<') {
                                $query .= ' --before=' . date('Y-m-d', strtotime($date . '-1 day'));
                            } else {
                                $query .= ' --after=' . date('Y-m-d', strtotime($date . ' -1 day'));
                                $query .= ' --before=' . date('Y-m-d', strtotime($date));
                            }
                        }
                    }
                }
            }
        }

        if (!empty($rule['before'])) {
            foreach ($rule['before'] as $value) {
                $query .= ' --before=' . date('Y-m-d', strtotime($value));
            }
        }

        if (!empty($rule['after'])) {
            foreach ($rule['after'] as $value) {
                $query .= ' --after=' . date('Y-m-d', strtotime($value));
            }
        }

        if (!empty($rule['action']) || !empty($rule['vp-action'])) {
            $vpAction = [];
            if (!empty($rule['action'])) {
                $action = array_filter($rule['action'], function ($val) {
                    return strpos($val, '/') === false;
                });
                $vpAction = array_diff($rule['action'], $action);
            }
            if (!empty($rule['vp-action'])) {
                $vpAction = array_merge($vpAction, $rule['vp-action']);
            }
            if (!empty($vpAction)) {
                $query .= ' --grep="^VP-Action: \(' . implode('\|', $vpAction) . '\)\(/.*\)\?$"';
            }
        }

        $scope = array_merge(
            !empty($rule['entity']) ? $rule['entity'] : [],
            !empty($rule['scope']) ? $rule['scope'] : []
        );
        if (!empty($scope) || !empty($action) || !empty($rule['vpid'])) {
            $query .= ' --grep="^VP-Action: ' .
                (empty($scope) ? '.*' : '\(' . implode('\|', $scope) . '\)') . '/' .
                (empty($action) ? '.*' : '\(' . implode('\|', $action) . '\)') .
                (empty($rule['vpid']) ? '\(/.*\)\?' : '/\(' . implode('\|', $rule['vpid']) . '\)') . '$"';
        }

        if (!empty($rule['text'])) {
            foreach ($rule['text'] as $value) {
                $query .= ' --grep="' . $value . '"';
            }
        }

        foreach ($rule as $key => $values) {
            if (in_array($key, ['author', 'date', 'before', 'after', 'entity', 'scope', 'vp-action', 'action', 'vpid', 'text'])) {
                continue;
            }

            if (strtolower(substr($key, 0, 5)) === 'x-vp-') {
                $prefix = '';
            } else {
                if (strtolower(substr($key, 0, 3)) === 'vp-') {
                    $prefix = '\(X-\)\?';
                } else {
                    $prefix = '\(X-VP-\|VP-\)';
                }
            }

            $query .= ' --grep="^' . $prefix . $key . ': \(' . implode('\|', $values) . '\)$"';
        }

        return $query;
    }

    /**
     * Converts rule (array) to isolated (enclosed in brackets) part of SQL restriction.
     *
     * Example:
     *  rule = ['field' => ['value'], 'other_field' => ['with_prefix*']]
     *  output = (`field` = "value" AND `other_field` LIKE "with_prefix%")
     *
     * @param $rule array
     * @return string
     */
    public static function createSqlRestrictionFromRule($rule)
    {
        $restrictionParts = [];

        foreach ($rule as $field => $values) {
            $value = $values[0]; // Use single value per field
            $valueTokens = self::tokenizeValue($value);
            $isWildcard = self::tokensContainWildcard($valueTokens);
            $searchedValue = self::tokensToSqlString($valueTokens);

            $operator = $isWildcard ? 'LIKE' : '=';

            $escapedValue = str_replace('"', '\"', $searchedValue);

            if ($isWildcard) {
                $escapedValue = str_replace('_', '\_', $escapedValue);
            }

            $restrictionPart = sprintf('`%s` %s "%s"', $field, $operator, $escapedValue);
            $restrictionParts[] = $restrictionPart;
        }

        return sprintf('(%s)', join(' AND ', $restrictionParts));
    }

    /**
     * @param string $value The value to be escaped
     * @return string|NULL
     */
    private static function escapeGitLogArgument($value)
    {
        // https://regex101.com/r/yP4yN9/3
        // https://regex101.com/r/yM9wA2/3
        // https://regex101.com/r/fM7uL3/1
        $regex = ['/(\\\\|\$)/', '/(\.|\[)/', '/(\*)/'];
        $replacements = ['\\\\\\\\\\\\$1', '\\\\$1', '.$1'];
        return preg_replace($regex, $replacements, $value);
    }

    /**
     * Splits value into tokens.
     * Tokens:
     *   *    => VALUE_WILDCARD,
     *   else => VALUE_STRING
     *
     * @param $value
     * @return array
     */
    private static function tokenizeValue($value)
    {
        preg_match_all(self::$valueWildcardRegex, $value, $matches);
        $tokens = [];

        foreach ($matches[0] as $valuePart) {
            if ($valuePart === '*') {
                $tokens[] = [
                    'type' => self::VALUE_WILDCARD
                ];
            } else {
                if ($valuePart === '\*') {
                    $tokens[] = [
                        'type' => self::VALUE_STRING,
                        'value' => '*',
                    ];
                } else {
                    if ($valuePart === '\\\\') {
                        $tokens[] = [
                            'type' => self::VALUE_STRING,
                            'value' => '\\',
                        ];
                    } else {
                        $tokens[] = [
                            'type' => self::VALUE_STRING,
                            'value' => $valuePart,
                        ];
                    }
                }
            }
        }

        return $tokens;
    }

    /**
     * Converts tokens to regular expression.
     * Wildcard is replaced by '.*'.
     *
     * For tokens from value 'prefix*' it returns '/^prefix.*$/'.
     *
     * @param $valueTokens
     * @return string
     */
    private static function tokensToRegex($valueTokens)
    {
        $regexDelimiter = '/';
        $regexFromValue = join('', array_map(function ($token) use ($regexDelimiter) {
            return QueryLanguageUtils::tokenToRegex($token, $regexDelimiter);
        }, $valueTokens));

        return sprintf('%s^%s$%s', $regexDelimiter, $regexFromValue, $regexDelimiter);
    }

    private static function tokenToRegex($token, $delimiter)
    {
        return $token['type'] === self::VALUE_WILDCARD ? '.*' : preg_quote($token['value'], $delimiter);
    }

    private static function tokensToSqlString($valueTokens)
    {
        return join('', array_map([QueryLanguageUtils::class, 'tokenToSqlString'], $valueTokens));
    }

    private static function tokenToSqlString($token)
    {
        return $token['type'] === self::VALUE_WILDCARD ? '%' : $token['value'];
    }

    private static function tokensContainWildcard($valueTokens)
    {
        return array_search(self::VALUE_WILDCARD, array_column($valueTokens, 'type')) !== false;
    }
}
