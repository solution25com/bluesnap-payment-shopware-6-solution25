import CDNLoader from "../services/CDNLoader";
import BlueSnapApi from "../services/BlueSnapApi";

export default class BluesnapGooglePayPlugin extends window.PluginBaseClass {
  init() {

    const loader = new CDNLoader('https://pay.google.com/gp/p/js/pay.js');

    loader.loadScript(function () {
      const baseRequest = {
        apiVersion: 2,
        apiVersionMinor: 0
      };
      const allowedCardNetworks = ["AMEX", "DISCOVER", "INTERAC", "JCB", "MASTERCARD", "VISA"];
      const allowedCardAuthMethods = ["PAN_ONLY", "CRYPTOGRAM_3DS"];
      const tokenizationSpecification = {
        type: 'PAYMENT_GATEWAY',
        parameters: {
          "gateway": "bluesnap",
          "gatewayMerchantId": document.getElementById('bluesnap-google-pay').getAttribute('data-merchant-id'),
        }
      };
      const baseCardPaymentMethod = {
        type: 'CARD',
        parameters: {
          allowedAuthMethods: allowedCardAuthMethods,
          allowedCardNetworks: allowedCardNetworks
        }
      };
      const cardPaymentMethod = Object.assign(
        {},
        baseCardPaymentMethod,
        {
          tokenizationSpecification: tokenizationSpecification
        }
      );
      let paymentsClient = null;

      function getGoogleIsReadyToPayRequest() {
        return Object.assign(
          {},
          baseRequest,
          {
            allowedPaymentMethods: [baseCardPaymentMethod]
          }
        );
      }

      function getGooglePaymentDataRequest() {
        const paymentDataRequest = Object.assign({}, baseRequest);
        paymentDataRequest.allowedPaymentMethods = [cardPaymentMethod];
        paymentDataRequest.transactionInfo = getGoogleTransactionInfo();

        paymentDataRequest.merchantInfo = {
          merchantId: document.getElementById('bluesnap-google-pay').getAttribute('data-google-merchant-id'),
          merchantName: 'Example Merchant'
        };

        paymentDataRequest.emailRequired = true; // Request payer email
        paymentDataRequest.shippingAddressRequired = true; // Request shipping address

        paymentDataRequest.shippingAddressParameters = {
          phoneNumberRequired: true // Optional: Set to false if phone number is not needed
        };
        return paymentDataRequest;
      }

      function getGooglePaymentsClient() {
        if (paymentsClient === null) {
          const mode = document.getElementById('bluesnap-google-pay').getAttribute('data-mode');
          const environment = mode === "sandbox" ? "TEST" : "PRODUCTION";
          paymentsClient = new google.payments.api.PaymentsClient({environment: environment});
        }
        return paymentsClient;
      }


      function onGooglePayLoaded() {
        const paymentsClient = getGooglePaymentsClient();
        paymentsClient.isReadyToPay(getGoogleIsReadyToPayRequest())
          .then(function (response) {
            if (response.result) {
              addGooglePayButton();
              // @todo prefetch payment data to improve performance after confirming site functionality
              prefetchGooglePaymentData();
            }
          })
          .catch(function (err) {
            // show error in developer console for debugging
            console.error(err);
          });

      }

      function addGooglePayButton() {
        const paymentsClient = getGooglePaymentsClient();
        const button =
          paymentsClient.createButton({
            onClick: onGooglePaymentButtonClicked,
            allowedPaymentMethods: [baseCardPaymentMethod]
          });
        document.getElementById('container').appendChild(button);
      }

      function getGoogleTransactionInfo() {
        return {
          countryCode: "US",
          currencyCode: document.getElementById('bluesnap-google-pay').getAttribute('data-currency-code'),
          totalPriceStatus: 'FINAL',
          totalPrice: document.getElementById('bluesnap-google-pay').getAttribute('data-total-price'),
        };
      }

      function prefetchGooglePaymentData() {
        const paymentDataRequest = getGooglePaymentDataRequest();
        // transactionInfo must be set but does not affect cache
        paymentDataRequest.transactionInfo = {
          totalPriceStatus: 'NOT_CURRENTLY_KNOWN',
          currencyCode: document.getElementById('bluesnap-google-pay').getAttribute('data-currency-code'),
        };

        const paymentsClient = getGooglePaymentsClient();
        paymentsClient.prefetchPaymentData(paymentDataRequest);
      }

      function onGooglePaymentButtonClicked() {

        const form = document.getElementById('confirmOrderForm')
        if (!form.checkValidity()) return;

        const paymentDataRequest = getGooglePaymentDataRequest();
        paymentDataRequest.transactionInfo = getGoogleTransactionInfo();

        const paymentsClient = getGooglePaymentsClient();
        paymentsClient.loadPaymentData(paymentDataRequest)
          .then(function (paymentData) {
            processPayment(paymentData);
          })
          .catch(function (err) {
            console.error(err);
          });
      }

      async function processPayment(paymentData) {

        const paymentMethodData = paymentData.paymentMethodData;
        const cardInfo = paymentMethodData.info || {};

        const structuredPayload = {
          paymentMethodData: {
            description: paymentMethodData.description || '',
            tokenizationData: {
              type: paymentMethodData.tokenizationData.type,
              token: paymentMethodData.tokenizationData.token
            },
            info: {
              cardNetwork: cardInfo.cardNetwork,
              cardDetails: cardInfo.cardDetails,
            }
          },
          email: paymentData.email || null,
          googleTransactionId: paymentData.googleTransactionId || null,
          shippingAddress: paymentData.shippingAddress || null
        };

        Object.keys(structuredPayload).forEach(key => {
          if (structuredPayload[key] === null) delete structuredPayload[key];
        });

        const jsonString = JSON.stringify(structuredPayload);
        const gToken = b64EncodeUnicode(jsonString);

        const flow = document.getElementById('bluesnap-google-pay').getAttribute('data-flow');

        const body = {
          gToken,
          email: paymentData.email
        }
        if (flow === 'order_payment') {
          document.getElementById('paymentData').value = JSON.stringify(body);
          document.getElementById('confirmOrderForm').submit();
        } else {
          const result = await BlueSnapApi.googleCapture(body);
          if (result && result.success) {
            document.getElementById('bluesnap-transaction-id').value = JSON.parse(result.message).transactionId;
            document.getElementById('confirmOrderForm').submit();
          }
        }

      }


      function b64EncodeUnicode(str) {
        // first we use encodeURIComponent to get percent-encoded UTF-8,
        // then we convert the percent encodings into raw bytes which
        // can be fed into btoa.
        return btoa(encodeURIComponent(str).replace(/%([0-9A-F]{2})/g,
          function toSolidBytes(match, p1) {
            return String.fromCharCode('0x' + p1);
          }));
      }

      onGooglePayLoaded()
    });

  }


}

