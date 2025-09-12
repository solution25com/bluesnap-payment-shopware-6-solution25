import './module/sw-order/view/sw-order-detail-general';
import './component/bluesnap-api-test';

import BluesnapApiTestService from './service/bluesnap-api-test.service';

Shopware.Service().register('bluesnapApiTestService', () => {
    return new BluesnapApiTestService(
        Shopware.Application.getContainer('init').httpClient,
        Shopware.Service('loginService')
    );
});