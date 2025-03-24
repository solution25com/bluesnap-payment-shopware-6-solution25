<?php

declare(strict_types=1);

namespace BlueSnap\Library\Constants;

enum TransactionStatuses: string
{
    case PENDING = 'pending';
    case PAID    = 'paid';
    case FAIL    = "fail";
    case REFUND  = "refund";
}
