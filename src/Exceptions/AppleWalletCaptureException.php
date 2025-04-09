<?php

namespace BlueSnap\Exceptions;

use BlueSnap\Exceptions\BaseException;
use Exception;

class AppleWalletCaptureException extends BaseException
{
    public function __construct($logger, $message = "Error while making apple pay capture", $code = 400, Exception $previous = null)
    {
        parent::__construct($logger, $message, $code, $previous);
    }
}
