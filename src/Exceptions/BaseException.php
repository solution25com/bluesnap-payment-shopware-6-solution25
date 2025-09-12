<?php

namespace solu1BluesnapPayment\Exceptions;

use Exception;

class BaseException extends Exception
{
    protected string $customMessage;

    public function __construct($logger, $message = "An error occurred", $code = 0, Exception $previous = null)
    {
        $this->customMessage = $message;
        $logger->info($message);
        parent::__construct($message, $code, $previous);
    }

    public function getCustomMessage()
    {
        return $this->customMessage;
    }
}
