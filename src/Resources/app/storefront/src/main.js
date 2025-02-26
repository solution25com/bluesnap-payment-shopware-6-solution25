import BluesnapCreditCardPlugin from './bluesnap-checkout-plugin/bluesnap-credit-card-plugin';
import BluesnapGooglePayPlugin from './bluesnap-checkout-plugin/bluesnap-google-pay-plugin';
import BluesnapApplePayPlugin from './bluesnap-checkout-plugin/bluesnap-apple-pay-plugin';

const PluginManager = window.PluginManager;
PluginManager.register('BluesnapCreditCardPlugin', BluesnapCreditCardPlugin, '[data-bluesnap-credit-card]');
PluginManager.register('BluesnapGooglePayPlugin', BluesnapGooglePayPlugin, '[data-bluesnap-google-pay]');
PluginManager.register('BluesnapApplePayPlugin', BluesnapApplePayPlugin, '[data-bluesnap-apple-pay]');