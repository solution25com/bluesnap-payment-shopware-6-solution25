<?php

namespace BlueSnap\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class BlueSnapConfig
{
    private SystemConfigService $systemConfigService;
  public function __construct(SystemConfigService $systemConfigService)
    {
        $this->systemConfigService = $systemConfigService;
    }
    public function getConfig(string $configName, string $salesChannelId = ''): mixed
    {
        if ($salesChannelId) {
            return $this->systemConfigService->get('BlueSnap.config.' . trim($configName), $salesChannelId);
        }
        return $this->systemConfigService->get('BlueSnap.config.' . trim($configName));
    }

    public function Level23DataConfigs($salesChannelId, $customerGroupId): bool
    {

      $level23Mode = $this->getConfig('level23Mode', $salesChannelId);
      $level23CustomerGroups = $this->getConfig('level23CustomerGroups', $salesChannelId);

      if ($level23Mode === 'salesChannel') {
        return true;
      } elseif ($level23Mode === 'customerGroup' && !empty($level23CustomerGroups)) {
        return in_array($customerGroupId, $level23CustomerGroups, true);
      }

      return false;
    }

  public function getCardTransactionType(string $salesChannelId = ''): string
  {
    $transactionMode = $this->getConfig('authorizeAndCapture', $salesChannelId) ?? 'auth';
    return $transactionMode === 'auth' ? 'AUTH_ONLY' : 'AUTH_CAPTURE';
  }

}
