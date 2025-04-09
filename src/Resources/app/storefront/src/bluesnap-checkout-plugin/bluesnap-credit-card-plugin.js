import BlueSnapApi from "../services/BlueSnapApi";

export default class BluesnapCreditCardPlugin extends window.PluginBaseClass {
  static options = {
    confirmFormId: 'confirmOrderForm',
    parentCreditCardWrapperId: 'bluesnap-credit-card',
  };

  init() {
    this._registerElements();
    this._registerEvents();
    this._showForm();
  }

  _registerElements() {
    this.confirmOrderForm = document.forms[this.options.confirmFormId];
    this.parentCreditCardWrapper = document.getElementById(this.options.parentCreditCardWrapperId);
    this.pfToken = this.parentCreditCardWrapper.getAttribute('data-pf-token');
    this.vaultedId = this.parentCreditCardWrapper.getAttribute('data-vaulted-shopper-id');
    this.securedFirstName = this.parentCreditCardWrapper.getAttribute('data-secured-firstName');
    this.securedLastName = this.parentCreditCardWrapper.getAttribute('data-secured-lastName');
    this.securedAmount = this.parentCreditCardWrapper.getAttribute('data-secured-amount');
    this.securedCurrency = this.parentCreditCardWrapper.getAttribute('data-secured-currency');
    this.shopperCardTyp = this.parentCreditCardWrapper.getAttribute("data-card-type");
    this.cardNumberDom = document.getElementById("bluesnap-card-number");
    this.cardHolderDom = document.getElementById("bluesnap-card-holder");
    this.cardHolderDomLastName = document.getElementById("bluesnap-card-holder-lastname");
    this.saveCardCheckbox = document.getElementById("bluesnap-is-save-card");
    this.savedCardForm = document.getElementById("bluesnap-saved-card-form");
    this.checkoutForm = document.getElementById("bluesnap-checkout-form");
    this.firstName = document.getElementById("bluesnap-first-name").value
    this.lastName = document.getElementById("bluesnap-last-name").value
    this.saveCard = document.getElementById("bluesnap-save-card")?.checked || null
    this.threeDS = !!this.parentCreditCardWrapper.getAttribute('data-three-d-secure');


    this.cardUrl = {
      "AMEX": "https://files.readme.io/97e7acc-Amex.png",
      "DINERS": "https://files.readme.io/8c73810-Diners_Club.png",
      "DISCOVER": "https://files.readme.io/caea86d-Discover.png",
      "JCB": "https://files.readme.io/e076aed-JCB.png",
      "MASTERCARD": "https://files.readme.io/5b7b3de-Mastercard.png",
      "VISA": "https://files.readme.io/9018c4f-Visa.png"
    };

    this.threeDSecureObj = {
      amount: parseFloat(this.securedAmount),
      currency: this.securedCurrency,
      billingFirstName: this.securedFirstName,
      billingLastName: this.securedLastName
    };

    this.blueSnapObject = {
      '3DS': this.threeDS,
      token: this.pfToken,
      onFieldEventHandler: {
        onFocus: (tagId) => {
          this._changeImpactedElement(tagId, "hosted-field-valid hosted-field-invalid", "hosted-field-focus");
        },
        onBlur: (tagId) => {
          this._changeImpactedElement(tagId, "hosted-field-focus");
        },
        onError: (tagId, errorCode, errorDescription, eventOrigin) => {
          this._changeImpactedElement(tagId, "hosted-field-valid hosted-field-focus", "hosted-field-invalid");
          const helpElement = document.getElementById(tagId + "-help");
          if (helpElement) {
            helpElement.classList.remove('helper-text-green');
            helpElement.textContent = errorCode + " - " + errorDescription + " - " + eventOrigin;
          }
        },
        onType: (tagId, cardType, cardData) => {

          const cardLogoImg = document.querySelector("#card-logo > img");
          if (cardLogoImg) {
            cardLogoImg.src = this.cardUrl[cardType];
          }

          if (cardData) {
            const helpElement = document.getElementById(tagId + "-help");
            if (helpElement) {
              helpElement.classList.add('helper-text-green');
              helpElement.textContent = JSON.stringify(cardData);
            }
          }
        },
        onValid: (tagId) => {
          this._changeImpactedElement(tagId, "hosted-field-focus hosted-field-invalid", "hosted-field-valid");
          const helpElement = document.getElementById(tagId + "-help");
          if (helpElement) {
            helpElement.textContent = "";
          }
        }
      },
      //styling is optional
      style: {
        // Styling all inputs
        "input": {
          "font-size": "14px",
          "font-family": "Helvetica Neue,Helvetica,Arial,sans-serif",
          "line-height": "1.42857143",
          "color": "#555"
        },
        // Styling a specific field
        /*"#ccn": {},*/
        // Styling Hosted Payment Field input state
        ":focus": {
          "color": "#555"
        }
      },
      ccnPlaceHolder: "Card number*",
      cvvPlaceHolder: "CVV*",
      expPlaceHolder: "MM / YY*"
    }

    bluesnap.hostedPaymentFieldsCreate(this.blueSnapObject);
  }


  _registerEvents() {
    this.confirmOrderForm.addEventListener('click', this._onOrderSubmitButtonClick.bind(this));
    if (this.saveCardCheckbox) {
      this.saveCardCheckbox.addEventListener("change", () => this._showForm());
    }
  }

  async _showForm() {

    const isSaveCardChecked = this.saveCardCheckbox.checked;
    this.savedCardForm.classList.toggle('d-none', !isSaveCardChecked);
    this.checkoutForm.classList.toggle('d-none', isSaveCardChecked);

    if (isSaveCardChecked) {

      const shopperName = this.parentCreditCardWrapper.getAttribute('data-shopper-name');
      const shopperLastName = this.parentCreditCardWrapper.getAttribute('data-shopper-lastName');
      const Shopper4Digits = this.parentCreditCardWrapper.getAttribute('data-shopper-last-digits');

      this.cardNumberDom.innerText = `**** **** **** ${Shopper4Digits}`;
      this.cardHolderDom.innerText = shopperName
      this.cardHolderDomLastName.innerText = shopperLastName;
    }
  }

  async _updateVaultedShopperCard() {
    this.cardNumberDom = document.getElementById("bluesnap-card-number");
    this.cardHolderDom = document.getElementById("bluesnap-card-holder");

    const body = {
      pfToken: document.getElementById('bluesnap-credit-card').getAttribute('data-pf-token'),
      firstName: document.getElementById('bluesnap-credit-card').getAttribute('data-shopper-name'),
      lastName: document.getElementById('bluesnap-credit-card').getAttribute('data-shopper-lastName'),
      cardType: document.getElementById('bluesnap-credit-card').getAttribute('data-shopper-card-type'),
      cardLastFourDigits: document.getElementById('bluesnap-credit-card').getAttribute('data-shopper-last-digits'),
    };
    const result = await BlueSnapApi.updateVaultedShopper(this.vaultedId, body);
    if (result.success) {}
    else {
      console.error('Failed to update vaulted shopper:', result.message);
    }
  }


  _creditCardCapture() {
    bluesnap.hostedPaymentFieldsSubmitData(
      async (callback) => {
        if (callback.error != null) {
          const errorMessageSpan = document.getElementById('error-message');
          errorMessageSpan.style.display = 'none';

          const errorArray = callback.error;
          let errorMessages = [];

          for (let i in errorArray) {
            const error = errorArray[i];
            errorMessages.push(`${error.errorCode}: ${error.errorDescription}`);
          }
          errorMessageSpan.innerHTML = errorMessages.join('<br/>');
          errorMessageSpan.style.display = 'block';
          return;
        }
        if (this.threeDS === true) {
          if (callback.threeDSecure == null || callback.threeDSecure.authResult !== 'AUTHENTICATION_SUCCEEDED') {
            if (callback.threeDSecure?.authResult === 'AUTHENTICATION_UNAVAILABLE') {
              document.getElementById("bluesnap-loader").style.display = "none";
              document.querySelector('#error-message .error-alert p').innerText = `This card type does not support 3D Secure: ${callback.threeDSecure.authResult}`;
              document.getElementById("error-message").classList.remove('d-none');
              document.getElementById('bluesnap-checkout-form').classList.remove('d-none');
              return;
            }
          }
        }
        const saveCard = document.getElementById("bluesnap-save-card")?.checked || null;
        if (this.vaultedId && saveCard) {
          await this._updateVaultedShopperCard();
        }

        const pfToken = document.getElementById('bluesnap-credit-card').getAttribute('data-pf-token');

        const body = {
          "pfToken": pfToken,
          "firstName": document.getElementById("bluesnap-first-name").value,
          "lastName": document.getElementById("bluesnap-last-name").value,
          "saveCard": document.getElementById("bluesnap-save-card")?.checked || null,
          "cardType": callback.cardData.ccType,
          ...(this.threeDS === true && {
            threeDSecureReferenceId: callback.threeDSecure?.threeDSecureReferenceId,
            authResult: callback.threeDSecure?.authResult
          }),
        };
        document.getElementById('bluesnap-checkout-form').classList.add('d-none');
        document.getElementById("bluesnap-loader").style.display = 'block';

        const result = await BlueSnapApi.capture(body);
        if (result && result.success) {
          document.getElementById('bluesnap-transaction-id').value = JSON.parse(result.message).transactionId;
          document.getElementById('confirmOrderForm').submit();
        }
        else{
          const message = result.message;
          const parsedMessage = JSON.parse(message);
          const description = parsedMessage[0]?.description;
          document.getElementById("bluesnap-loader").style.display = "none";
          console.log(description);
          document.querySelector('#error-message .error-alert p').innerText = description.split('-')[0];
          document.getElementById('error-message').classList.remove('d-none');
          document.getElementById("error-message").classList.add('block');
          document.getElementById('bluesnap-checkout-form').classList.remove('d-none');
        }
      },
      this.threeDS ? this.threeDSecureObj : undefined
    );
  }

  async _onOrderSubmitButtonClick(event) {
    event.preventDefault();
    if (document.getElementById("bluesnap-is-save-card").checked) {
      await this._vaultedCapture()
    } else {
      this._creditCardCapture()
    }
  }


  async _vaultedCapture() {
    if (this.threeDS) {
      bluesnap.threeDsPaymentsSetup(this.pfToken, async (sdkResponse) => {
        const code = sdkResponse.code;
        if (code == 1) {
          const threeDSecure = sdkResponse.threeDSecure;
          const pfToken = document.getElementById('bluesnap-credit-card').getAttribute('data-pf-token');
          const vaultedId = document.getElementById('bluesnap-credit-card').getAttribute('data-vaulted-shopper-id');
          const body = {
            pfToken: pfToken,
            vaultedId: vaultedId,
            ...(this.threeDS === true && {
              threeDSecureReferenceId: threeDSecure?.threeDSecureReferenceId,
              authResult: threeDSecure?.authResult
            }),
          };
          const result = await BlueSnapApi.vaultedShopper(body);
          if (result && result.success) {
            const message = JSON.parse(result.message);
            document.getElementById('bluesnap-transaction-id').value = message.transactionId;
            document.getElementById('confirmOrderForm').submit();
          }

        } else {
          const errorsArray = sdkResponse.info.errors;
          document.querySelector('#error-message .error-alert p').innerText = sdkResponse.info.errors;
          document.getElementById('error-message').classList.remove('d-none')
          document.getElementById('error-message').classList.add('block');
          const warningsArray = sdkResponse.info.warnings;
          console.log('errorsArray', errorsArray);
        }
      });
    } else {

      const pfToken = document.getElementById('bluesnap-credit-card').getAttribute('data-pf-token');
      const vaultedId = document.getElementById('bluesnap-credit-card').getAttribute('data-vaulted-shopper-id');

      const body = {
        pfToken: pfToken,
        vaultedId: vaultedId,
      };

      document.getElementById('bluesnap-saved-card-form').classList.add('d-none');
      document.getElementById("bluesnap-loader").style.display = 'block';
      const result = await BlueSnapApi.vaultedShopper(body);

      if (result && result.success) {
        const message = JSON.parse(result.message);
        document.getElementById('bluesnap-transaction-id').value = message.transactionId;
        document.getElementById('confirmOrderForm').submit();
      }
    }

    const Shopper4Digits = this.parentCreditCardWrapper.getAttribute('data-shopper-last-digits');
    const cardType = document.getElementById('bluesnap-credit-card').getAttribute('data-shopper-card-type');
    const previouslyUsedCard = {
      "last4Digits": Shopper4Digits,
      "ccType": cardType,
      "amount": parseFloat(this.securedAmount),
      "currency": this.securedCurrency,
    };

    this.threeDS ? bluesnap.threeDsPaymentsSubmitData(previouslyUsedCard) : undefined;
  }

  _changeImpactedElement(tagId, removeClass, addClass) {
    removeClass = removeClass || "";
    addClass = addClass || "";
    const element = document.querySelector('[data-bluesnap="' + tagId + '"]');
    if (addClass) {
      element.classList.add(...addClass.split(' '));
    }
    if (removeClass) {
      element.classList.remove(...removeClass.split(' '));
    }

  }
}