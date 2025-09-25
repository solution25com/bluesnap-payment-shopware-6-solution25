<?php

namespace BlueSnap\Core\Content\BlueSnap\SalesChannel;

use BlueSnap\Core\Content\BlueSnap\BlueSnapApiResponseStruct;
use Shopware\Core\System\SalesChannel\StoreApiResponse;

class BlueSnapApiResponse extends StoreApiResponse
{
    protected int $statusCode;
    public function __construct(BlueSnapApiResponseStruct $object, $statusCode = 200)
    {
        parent::__construct($object);
        $this->setStatusCode($statusCode);
    }
}
