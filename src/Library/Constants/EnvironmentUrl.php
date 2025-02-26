<?php

declare(strict_types=1);

namespace BlueSnap\Library\Constants;

enum EnvironmentUrl: string
{
  case SANDBOX = "https://sandbox.bluesnap.com";
  case LIVE = "https://ws.bluesnap.com";
  case CHECKOUT_LINK = "https://sandpay.bluesnap.com";

}
