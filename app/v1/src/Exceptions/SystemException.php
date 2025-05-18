<?php

namespace App\Exceptions;

use Exception;

class SystemException extends Exception
{

    public static function apiException($message): self
    {
        return new self($message);
    }

}
