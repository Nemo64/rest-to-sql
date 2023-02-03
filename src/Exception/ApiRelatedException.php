<?php

namespace Nemo64\RestToSql\Exception;

abstract class ApiRelatedException extends \Exception
{
    abstract public function getStatusCode(): int;
}