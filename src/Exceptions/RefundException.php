<?php

namespace BlueSnap\Exceptions;

use BlueSnap\Exceptions\BaseException;
use Exception;

class RefundException extends BaseException
{
    public function __construct($logger, $message = "Error while making refund request", $code = 400, Exception $previous = null)
    {
        parent::__construct($logger, $message, $code, $previous);
    }
}
