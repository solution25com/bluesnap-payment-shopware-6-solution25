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
    const request = {
      countryCode: 'US',
      currencyCode: 'USD',
      supportedNetworks: ['visa', 'masterCard', 'amex', 'discover'],
      merchantCapabilities: ['supports3DS'],
      total: {label: 'BlueSnap', amount: this.totalPrice},
    };
    const session = new ApplePaySession(14, request);

    session.onvalidatemerchant = async (event) => {
      const validationURL = event.validationURL;
      const body = {
        "validationUrl": validationURL,
        "domainName": this.domain,
        "displayName": "BlueSnap"
      }

      const result = await BlueSnapApi.appleCreateWallet(body)
      if (!result || !result.success) {
        session.abort()
      }
      const parsedTokenObj = JSON.parse(result.message);
      session.completeMerchantValidation(parsedTokenObj);
    };

    session.onpaymentauthorized = async (event) => {
      const paymentToken = event.payment;

      const encodedPaymentToken = btoa(JSON.stringify(paymentToken));

      const body = {
        appleToken: encodedPaymentToken,
        email: this.userEmail
      }

      const flow = document.getElementById('bluesnap-google-pay').getAttribute('data-flow');

      session.completePayment(ApplePaySession.STATUS_SUCCESS);

      if (flow === 'order_payment') {
        const captureResult = await BlueSnapApi.appleCapture(body)

        if(captureResult && captureResult.success) {
          document.getElementById('bluesnap-transaction-id').value = JSON.parse(captureResult.message).transactionId;
          document.getElementById('confirmOrderForm').submit()
        }
      }
      else{
        document.getElementById('paymentData').value = JSON.stringify(body);
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
    this.userEmail = this.parentCreditCardWrapper.getAttribute(' data-user-email')
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
    if (!window.ApplePaySession || !window.ApplePaySession.canMakePayments()) {
      this.errorWrapper.classList.remove('d-none')
      return
    }

    const promise2 = window.ApplePaySession.canMakePaymentsWithActiveCard(
      this.merchantID + '-' + this.domain
    );

    promise2.then((canMakePaymentsWithActiveCard) => {
      if (!canMakePaymentsWithActiveCard) {
        this.errorWrapper.classList.remove('d-none')
        return
      }
      this.appleButton.classList.remove('visually-hidden')
    })
  }
}