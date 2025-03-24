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
}
