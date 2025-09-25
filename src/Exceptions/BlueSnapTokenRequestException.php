<?php

namespace BlueSnap\Exceptions;

use BlueSnap\Exceptions\BaseException;
use Exception;

class BlueSnapTokenRequestException extends BaseException
{
    public function __construct($logger, $message = "Error while getting pfToken", $code = 400, Exception $previous = null)
    {
        parent::__construct($logger, $message, $code, $previous);
    }
}
