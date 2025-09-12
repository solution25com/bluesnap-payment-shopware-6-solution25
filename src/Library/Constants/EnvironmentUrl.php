<?php

declare(strict_types=1);

namespace solu1BluesnapPayment\Library\Constants;

enum EnvironmentUrl: string
{
    case SANDBOX               = "https://sandbox.bluesnap.com";
    case LIVE                  = "https://ws.bluesnap.com";
    case CHECKOUT_LINK_SANDBOX = "https://sandpay.bluesnap.com";
    case CHECKOUT_LINK_LIVE    = "https://pay.bluesnap.com";
    case BLUESNAP_JS_SANDBOX   = 'https://sandpay.bluesnap.com/web-sdk/5/bluesnap.js';
    case BLUESNAP_JS_LIVE      = 'https://pay.bluesnap.com/web-sdk/5/bluesnap.js';
}
