<?php

declare(strict_types=1);

namespace solu1BluesnapPayment\Library\Constants;

enum TransactionStatuses: string
{
    case PENDING = 'pending';
    case PAID    = 'paid';
    case FAIL    = "fail";
    case REFUND  = "refund";
    case AUTHORIZED = 'authorized';
    case CANCELLED   = 'cancelled';
}