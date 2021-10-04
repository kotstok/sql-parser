<?php

declare(strict_types=1);

namespace SqlParser\Lexer;

use SqlParser\Exceptions\InvalidParameterException;

class AbstractLexer
{
    protected $tokens;

    /**
     * @var string[]
     */
    protected static $splitters = [
        "<=>", "\r\n", "!=", ">=", "<=", "<>", "<<", ">>", ":=", "\\", "&&", "||", ":=",
        "/*", "*/", "--", ">", "<", "|", "=", "^", "(", ")", "\t", "\n", "'", "\"", "`",
        ",", "@", " ", "+", "-", "*", "/", ";"
    ];
    /**
     * @var int
     */
    protected $tokenSize;
    /**
     * @var int[]|string[]
     */
    protected $hashSet;

    /**
     * Constructor.
     *
     * It initializes some fields.
     */
    public function __construct()
    {
        $this->tokenSize = strlen(self::$splitters[0]); // should be the largest one
        $this->hashSet = array_flip(self::$splitters);
    }


    /**
     * Get the maximum length of a split token.
     *
     * @return int The number of characters for the largest split token.
     */
    public function getMaxLengthOfTokens(): int
    {
        return $this->tokenSize;
    }

    /**
     * Looks into the internal split token array and compares the given token with
     * the array content. It returns true, if the token will be found, false otherwise.
     */
    public function isToken($token): bool
    {
        return isset($this->hashSet[$token]);
    }

    /**
     * Ends the given string $haystack with the string $needle?
     *
     * @param string $haystack
     * @param string $needle
     *
     * @return boolean true, if the parameter $haystack ends with the character sequences $needle, false otherwise
     */
    protected function endsWith(string $haystack, string $needle): bool
    {
        $length = strlen($needle);
        if ($length == 0) {
            return true;
        }
        return (substr($haystack, -$length) === $needle);
    }

    /**
     * @throws InvalidParameterException
     */
    public function split($sql): array
    {
        if (!is_string($sql)) {
            throw new InvalidParameterException($sql);
        }

        $tokens = [];
        $token = "";

        $splitLen = $this->getMaxLengthOfTokens();
        $len = strlen($sql);
        $pos = 0;

        while ($pos < $len) {
            for ($i = $splitLen; $i > 0; $i--) {
                $substr = substr($sql, $pos, $i);
                if ($this->isToken($substr)) {
                    if ($token !== "") {
                        $tokens[] = $token;
                    }

                    $tokens[] = $substr;
                    $pos += $i;
                    $token = "";

                    continue 2;
                }
            }

            $token .= $sql[$pos];
            $pos++;
        }

        if ($token !== "") {
            $tokens[] = $token;
        }

        return $this->concatUserDefinedVariables(
            $this->concatComments(
                $this->balanceParenthesis(
                    $this->concatColReferences(
                        $this->balanceBackticks(
                            $this->concatEscapeSequences($tokens)
                        )
                    )
                )
            )
        );
    }

    protected function concatUserDefinedVariables(array $tokens): array
    {
        $i = 0;
        $cnt = count($tokens);
        $userdef = false;

        while ($i < $cnt) {
            if (!isset($tokens[$i])) {
                $i++;
                continue;
            }

            $token = $tokens[$i];

            if ($userdef !== false) {
                $tokens[$userdef] .= $token;
                unset($tokens[$i]);
                if ($token !== "@") {
                    $userdef = false;
                }
            }

            if ($userdef === false && $token === "@") {
                $userdef = $i;
            }

            $i++;
        }

        return array_values($tokens);
    }

    protected function concatComments($tokens): array
    {
        $i = 0;
        $cnt = count($tokens);
        $comment = false;

        while ($i < $cnt) {
            if (!isset($tokens[$i])) {
                $i++;
                continue;
            }

            $token = $tokens[$i];

            if ($comment !== false) {
                if ("\n" === $token || "\r\n" === $token) {
                    $comment = false;
                } else {
                    unset($tokens[$i]);
                    $tokens[$comment] .= $token;
                }
                if ("*/" === $token) {
                    $comment = false;
                }
            }

            if (($comment === false) && ($token === "--")) {
                $comment = $i;
            }

            if (($comment === false) && ($token === "/*")) {
                $comment = $i;
            }

            $i++;
        }

        return array_values($tokens);
    }

    protected function isBacktick($token): bool
    {
        return ($token === "'" || $token === "\"" || $token === "`");
    }

    protected function balanceBackticks($tokens)
    {
        $i = 0;
        $cnt = count($tokens);
        while ($i < $cnt) {
            if (!isset($tokens[$i])) {
                $i++;
                continue;
            }

            $token = $tokens[$i];

            if ($this->isBacktick($token)) {
                $tokens = $this->balanceCharacter($tokens, $i, $token);
            }

            $i++;
        }

        return $tokens;
    }

    // backticks are not balanced within one token, so we have
    // to re-combine some tokens
    protected function balanceCharacter($tokens, $idx, $char): array
    {
        $token_count = count($tokens);
        $i = $idx + 1;
        while ($i < $token_count) {
            if (!isset($tokens[$i])) {
                $i++;
                continue;
            }

            $token = $tokens[$i];
            $tokens[$idx] .= $token;
            unset($tokens[$i]);

            if ($token === $char) {
                break;
            }

            $i++;
        }
        return array_values($tokens);
    }

    /**
     * This function concats some tokens to a column reference.
     * There are two different cases:
     *
     * 1. If the current token ends with a dot, we will add the next token
     * 2. If the next token starts with a dot, we will add it to the previous token
     */
    protected function concatColReferences($tokens): array
    {
        $cnt = count($tokens);
        $i = 0;
        while ($i < $cnt) {
            if (!isset($tokens[$i])) {
                $i++;
                continue;
            }

            if ($tokens[$i][0] === ".") {

                // concat the previous tokens, till the token has been changed
                $k = $i - 1;
                $len = strlen($tokens[$i]);
                while (($k >= 0) && ($len == strlen($tokens[$i]))) {
                    if (!isset($tokens[$k])) {
                        $k--;
                        continue;
                    }
                    $tokens[$i] = $tokens[$k] . $tokens[$i];
                    unset($tokens[$k]);
                    $k--;
                }
            }

            if ($this->endsWith($tokens[$i], '.') && !is_numeric($tokens[$i])) {

                // concat the next tokens, till the token has been changed
                $k = $i + 1;
                $len = strlen($tokens[$i]);
                while (($k < $cnt) && ($len == strlen($tokens[$i]))) {
                    if (!isset($tokens[$k])) {
                        $k++;
                        continue;
                    }
                    $tokens[$i] .= $tokens[$k];
                    unset($tokens[$k]);
                    $k++;
                }
            }

            $i++;
        }

        return array_values($tokens);
    }

    protected function concatEscapeSequences($tokens): array
    {
        $tokenCount = count($tokens);
        $i = 0;
        while ($i < $tokenCount) {
            if ($this->endsWith($tokens[$i], "\\")) {
                $i++;
                if (isset($tokens[$i])) {
                    $tokens[$i - 1] .= $tokens[$i];
                    unset($tokens[$i]);
                }
            }
            $i++;
        }
        return array_values($tokens);
    }

    protected function balanceParenthesis($tokens): array
    {
        $token_count = count($tokens);
        $i = 0;
        while ($i < $token_count) {
            if ($tokens[$i] !== '(') {
                $i++;
                continue;
            }
            $count = 1;
            for ($n = $i + 1; $n < $token_count; $n++) {
                $token = $tokens[$n];
                if ($token === '(') {
                    $count++;
                }
                if ($token === ')') {
                    $count--;
                }
                $tokens[$i] .= $token;
                unset($tokens[$n]);
                if ($count === 0) {
                    $n++;
                    break;
                }
            }
            $i = $n;
        }
        return array_values($tokens);
    }
}
