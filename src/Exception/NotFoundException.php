<?php

namespace Nemo64\RestToSql\Exception;

class NotFoundException extends ApiRelatedException
{
    public function getStatusCode(): int
    {
        return 404;
    }
}