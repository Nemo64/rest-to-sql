<?php

namespace Nemo64\RestToSql\Exception;

class InternalServerErrorException extends ApiRelatedException
{
    public function getStatusCode(): int
    {
        return 500;
    }
}