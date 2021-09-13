<?php

namespace SqlParser\Exceptions;

class InvalidParameterException extends \Exception
{
    public function __construct($argument)
    {
        parent::__construct("no SQL string to parse: \n" . $argument, 10);
    }
}
