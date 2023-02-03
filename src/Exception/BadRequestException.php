<?php

namespace Nemo64\RestToSql\Exception;

class BadRequestException extends ApiRelatedException
{
    public function getStatusCode(): int
    {
        return 400;
    }
}