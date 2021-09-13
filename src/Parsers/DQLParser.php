<?php

declare(strict_types=1);

namespace SqlParser\Parsers;

use SqlParser\Lexer\AbstractLexer;

/**
 * Data Query Language
 *
 * SELECT
 */
class DQLParser extends AbstractLexer
{
    public $query;

    public function __construct(string $query)
    {
        parent::__construct();

        $this->query = $query;
    }

    public function isSelect(): bool
    {
        return false;
    }

    public function isQuery(): bool
    {
        return true;
    }
}
