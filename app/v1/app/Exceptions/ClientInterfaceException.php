<?php

namespace App\Exceptions;

use Exception;

class ClientInterfaceException extends Exception
{
    public static function cliException($message): self
    {
        return new self($message);
    }

    public static function errorGeneratingCsr(): self
    {
        return new self('An error occurred while generating the CSR.');
    }
}
