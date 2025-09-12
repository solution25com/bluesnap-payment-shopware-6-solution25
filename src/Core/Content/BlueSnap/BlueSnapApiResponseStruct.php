<?php

namespace solu1BluesnapPayment\Core\Content\BlueSnap;

use Shopware\Core\Framework\Struct\Struct;

class BlueSnapApiResponseStruct extends Struct
{
    protected mixed $message;
    protected bool $success;

    public function __construct(bool $success, mixed $message)
    {
        $this->success = $success;
        $this->message = $message;
    }
}
