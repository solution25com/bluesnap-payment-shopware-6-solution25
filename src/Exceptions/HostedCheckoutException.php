<?php

namespace BlueSnap\Exceptions;

use BlueSnap\Exceptions\BaseException;
use Exception;

class HostedCheckoutException extends BaseException
{
    public function __construct($logger, $message = "Error while making making hosted checkout request", $code = 400, Exception $previous = null)
    {
        parent::__construct($logger, $message, $code, $previous);
    }
}
