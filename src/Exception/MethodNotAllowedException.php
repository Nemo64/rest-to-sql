<?php

namespace Nemo64\RestToSql\Exception;

class MethodNotAllowedException extends ApiRelatedException
{
    public function getStatusCode(): int
    {
        return 405;
    }
}