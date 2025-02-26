import BlueSnapApi from "../services/BlueSnapApi";

export default class BluesnapGooglePayPlugin extends window.PluginBaseClass {

  static options = {
    confirmFormId: 'confirmOrderForm',
    parentWrapperId: 'bluesnap-apple-pay',
    appleButtonId: 'apple-pay-button',
    errorWrapperId: 'apple-pay-support-error',
  }

  init() {
    this._registerElements();
    this._registerEvents();
    this._displayButton();
  }

  _applePayClicked() {
    console.log('applePayClicked');
    const request = {
      countryCode: 'US',
      currencyCode: 'USD',
      supportedNetworks: ['visa', 'masterCard', 'amex', 'discover'],
      merchantCapabilities: ['supports3DS'],
      total: {label: 'BlueSnap', amount: this.totalPrice},
    };
    const session = new ApplePaySession(3, request);
    console.log('session', session);

    session.onvalidatemerchant = async (event) => {
      const validationURL = event.validationURL;
      const body = {
        "validationUrl": validationURL,
        "domainName": this.domain,
        "displayName": "BlueSnap"
      }

      const result = await BlueSnapApi.appleCreateWallet(body)
      if (!result || !result.success) {
        console.log('abort')
        session.abort()
      }
      const parsedTokenObj = JSON.parse(result.message);
      session.completeMerchantValidation(parsedTokenObj);
    };

    session.onpaymentauthorized = async (event) => {
      const paymentToken = event.payment;

      const encodedPaymentToken = btoa(JSON.stringify(paymentToken));

      console.log("Encoded Payment Token:", encodedPaymentToken);

      const body = {
        appleToken: encodedPaymentToken
      }
      const captureResult = await BlueSnapApi.appleCapture(body)
      console.log('captureResult', captureResult);

      session.completePayment(ApplePaySession.STATUS_SUCCESS);
      if(captureResult && captureResult.success) {
        document.getElementById('bluesnap-transaction-id').value = JSON.parse(captureResult.message).transactionId;
        document.getElementById('confirmOrderForm').submit()
      }
    };
    session.begin();
  }


  _registerElements() {
    this.appleButton = document.getElementById(this.options.appleButtonId);
    this.parentCreditCardWrapper = document.getElementById(this.options.parentWrapperId);
    this.form = document.getElementById(this.options.confirmFormId)

    this.totalPrice = this.parentCreditCardWrapper.getAttribute('data-total-price')
    this.pfToken = this.parentCreditCardWrapper.getAttribute('data-pf-token')
    this.merchantID = this.parentCreditCardWrapper.getAttribute('data-merchant-id')
    this.domain = this.parentCreditCardWrapper.getAttribute('data-domain-name')

    this.errorWrapper = document.getElementById(this.options.errorWrapperId)
  }

  _registerEvents() {
    this.appleButton.addEventListener('click', this._applePayClick.bind(this))
  }

  _applePayClick(event) {
    event.preventDefault();
    if (!this.form.checkValidity()) return;

    this._applePayClicked()

  }

  _displayButton() {
    if (!window.ApplePaySession || !ApplePaySession.canMakePayments()) {
      this.errorWrapper.classList.remove('d-none')
      console.log('cannotMakePayments')
      return
    }

    // const promise = ApplePaySession.applePayCapabilities(
    //   this.merchantID + '-' + this.options.domain
    // );
    //
    // promise.then(function(capabilities) {
    //   // Check whether the person has an active payment credential provisioned in Wallet.
    //   switch (capabilities.paymentCredentialStatus) {
    //     case "paymentCredentialsAvailable":
    //       console.log('paymentCredentialsAvailable')
    //       // Display an Apple Pay button and offer Apple Pay as the primary payment option.
    //       break;
    //     case "paymentCredentialStatusUnknown":
    //       console.log('paymentCredentialStatusUnknown')
    //       // Display an Apple Pay button and offer Apple Pay as a payment option.
    //       break;
    //     case "paymentCredentialsUnavailable":
    //       console.log('paymentCredentialsUnavailable')
    //       // Consider displaying an Apple Pay button.
    //       break;
    //     case "applePayUnsupported":
    //       console.log('applePayUnsupported')
    //       // Don't show an Apple Pay button or offer Apple Pay.
    //       break;
    //   }
    // })
    const promise = ApplePaySession.canMakePaymentsWithActiveCard(
      this.merchantID + '-' + this.domain
    );

    promise.then((canMakePaymentsWithActiveCard) => {
      if (!canMakePaymentsWithActiveCard) {
        this.errorWrapper.classList.remove('d-none')
        console.log('cardNotActive')
        return
      }
      this.appleButton.classList.remove('visually-hidden')
    })
  }
}