<?php

namespace BlueSnap\Exceptions;

use Exception;

class SavedCardException extends BaseException
{
    public function __construct($logger, $message = "Error while managing the saved card", $code = 400, ?Exception $previous = null)
    {
        parent::__construct($logger, $message, $code, $previous);
    }
}
