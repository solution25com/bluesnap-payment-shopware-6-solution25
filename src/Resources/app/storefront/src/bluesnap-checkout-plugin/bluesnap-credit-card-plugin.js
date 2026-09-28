import BlueSnapApi from '../services/BlueSnapApi';
import CDNLoader from '../services/CDNLoader';

/**
 * Credit card payment at checkout: a stored card or a new one, submitted through Shopware's own
 * order button.
 */
export default class BluesnapCreditCardPlugin extends window.PluginBaseClass {
    static options = {
        confirmFormId: 'confirmOrderForm',
        parentCreditCardWrapperId: 'bluesnap-credit-card',
        /** Regions of the confirm page that carry cart totals and line items. */
        cartRegionSelectors: ['.checkout-aside-container', '.confirm-product'],
    };

    init() {
        this.wrapper = document.getElementById(this.options.parentCreditCardWrapperId);
        this.confirmOrderForm = document.forms[this.options.confirmFormId];

        this._bindOrderForm(false);

        new CDNLoader(this.wrapper.getAttribute('data-script-url')).loadScript(
            () => {
                this._registerElements();
                this._registerEvents();
                this._applyCardChoice();
                this._ensureHostedFields();
            },
            () => {
                this._registerElements();
                this._showError(this.genericErrorText);
            }
        );
    }

    /**
     * Refreshing the cart totals replaces the order form, detaching the element held here, so the
     * reference and the handlers are re-established against whatever form is in the page.
     */
    _bindOrderForm(withClickHandler) {
        const form = document.forms[this.options.confirmFormId];
        if (!form) {
            return;
        }

        this.confirmOrderForm = form;

        if (form.dataset.bluesnapSubmitGuard !== '1') {
            form.dataset.bluesnapSubmitGuard = '1';
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                console.error('BlueSnap: the order form was submitted without payment data; submission blocked');
            });
        }

        if (withClickHandler && form.dataset.bluesnapClickHandler !== '1') {
            form.dataset.bluesnapClickHandler = '1';
            form.addEventListener('click', this._onOrderSubmitButtonClick.bind(this));
        }
    }

    _registerElements() {
        this.confirmOrderForm = document.forms[this.options.confirmFormId];
        this.wrapper = document.getElementById(this.options.parentCreditCardWrapperId);

        this.vaultedId = this.wrapper.getAttribute('data-vaulted-shopper-id');
        this.securedAmount = this.wrapper.getAttribute('data-secured-amount');
        this.securedCurrency = this.wrapper.getAttribute('data-secured-currency');
        this.securedFirstName = this.wrapper.getAttribute('data-secured-firstName');
        this.securedLastName = this.wrapper.getAttribute('data-secured-lastName');
        this.flow = this.wrapper.getAttribute('data-flow');
        this.threeDS = this.wrapper.getAttribute('data-three-d-secure') === '1';
        this.isSurchargeActive = this.wrapper.getAttribute('data-is-surcharge-active') === '1';
        this.serverSelectedCardKey = this.wrapper.getAttribute('data-selected-card-key') || '';
        this.locale = document.documentElement.lang || 'en-GB';

        this.surchargeNoticeText = this.wrapper.getAttribute('data-surcharge-notice-text') || '';
        this.validationErrorText = this.wrapper.getAttribute('data-validation-error-text')
            || 'Please fill in all required fields.';
        this.genericErrorText = this.wrapper.getAttribute('data-generic-error-text')
            || 'The payment could not be processed. Please try again.';
        this.threeDSecureFailedText = this.wrapper.getAttribute('data-three-d-secure-failed-text')
            || 'The card could not be authenticated. Please try another card.';

        this.savedCardRadios = Array.from(this.wrapper.querySelectorAll('.bluesnap-saved-card-radio'));
        this.savedCardsChoice = this.wrapper.querySelector('[data-bluesnap-saved-cards-choice]');
        this.newCardSection = this.wrapper.querySelector('[data-bluesnap-new-card]');
        this.surchargePanel = this.wrapper.querySelector('[data-bluesnap-surcharge-confirm]');
        this.surchargePanelText = document.getElementById('bluesnap-surcharge-confirm-text');
        this.errorMessage = document.getElementById('bluesnap-error-message');
        this.loaderElement = document.getElementById('bluesnap-loader');

        this.threeDSecureObject = {
            amount: parseFloat(this.securedAmount),
            currency: this.securedCurrency,
            billingFirstName: this.securedFirstName,
            billingLastName: this.securedLastName,
        };

        this._pendingNewCard = null;
        this._pendingConfirm = null;
        this._hostedFieldsReady = false;
        this._calculatedSurcharge = null;
        this._busy = false;
    }

    _registerEvents() {
        this._bindOrderForm(true);

        this.savedCardRadios.forEach((radio) => {
            radio.addEventListener('change', () => this._onCardChoiceChange());
        });

        this.wrapper.querySelector('[data-bluesnap-surcharge-pay]')
            ?.addEventListener('click', () => this._pendingConfirm?.());

        this.wrapper.querySelector('[data-bluesnap-surcharge-cancel]')
            ?.addEventListener('click', () => this._cancelSurchargeConfirmation());
    }

    _createHostedFields(token) {
        this.hostedFieldsToken = token;

        if (typeof bluesnap === 'undefined' || !token) {
            this._showError(this.genericErrorText);
            console.error('BlueSnap: hosted payment fields cannot be created', { hasSdk: typeof bluesnap !== 'undefined', token });
            return;
        }

        try {
            this._buildHostedFields(token);
        } catch (error) {
            this._showError(this.genericErrorText);
            console.error('BlueSnap: hosted payment fields could not be created', error);
        }
    }

    _buildHostedFields(token) {
        bluesnap.hostedPaymentFieldsCreate({
            '3DS': this.threeDS,
            token: token,
            onFieldEventHandler: {
                onFocus: (tagId) => this._setFieldState(tagId, 'hosted-field-valid hosted-field-invalid', 'hosted-field-focus'),
                onBlur: (tagId) => this._setFieldState(tagId, 'hosted-field-focus'),
                onError: (tagId) => this._setFieldState(tagId, 'hosted-field-valid hosted-field-focus', 'hosted-field-invalid'),
                onValid: (tagId) => this._setFieldState(tagId, 'hosted-field-focus hosted-field-invalid', 'hosted-field-valid'),
            },
            style: {
                input: {
                    'font-size': '14px',
                    'font-family': 'Helvetica Neue,Helvetica,Arial,sans-serif',
                    'line-height': '1.42857143',
                    color: '#555',
                },
                ':focus': { color: '#555' },
            },
            ccnPlaceHolder: 'Card number*',
            cvvPlaceHolder: 'CVV*',
            expPlaceHolder: 'MM / YY*',
        });
    }

    /* --------------------------------------------------------------- card choice */

    _getSelectedCardRadio() {
        const checked = this.savedCardRadios.find((radio) => radio.checked);

        return checked && checked.value !== 'new' ? checked : null;
    }

    /** Shows or hides the new-card fields for the current choice, without talking to the server. */
    _applyCardChoice() {
        const selectedCard = this._getSelectedCardRadio();

        this.newCardSection?.classList.toggle('d-none', selectedCard !== null);
        this._hideSurchargeConfirmation();
        this._clearError();
    }

    async _onCardChoiceChange() {
        const selectedCard = this._getSelectedCardRadio();
        const cardKey = selectedCard ? selectedCard.value : '';

        this._applyCardChoice();
        this._discardPendingNewCard();
        this._discardCalculatedSurcharge();

        if (cardKey === this.serverSelectedCardKey) {
            await this._ensureHostedFields();

            return;
        }

        this._setBusy(true);

        const result = await BlueSnapApi.selectSavedCard(cardKey);
        if (!result || !result.success) {
            this._showError(this.genericErrorText);
            this._setBusy(false);
            return;
        }

        this.serverSelectedCardKey = cardKey;
        await this._refreshCartRegions();

        if (!selectedCard) {
            this._hostedFieldsReady = false;
            await this._ensureHostedFields();
        }

        this._setBusy(false);
    }

    /** Builds the hosted fields the first time the new-card entry becomes visible. */
    async _ensureHostedFields() {
        if (this._hostedFieldsReady || this._getSelectedCardRadio()) {
            return;
        }

        if (this.vaultedId) {
            await this._refreshHostedFields();
        } else {
            this._createHostedFields(this.wrapper.getAttribute('data-pf-token'));
        }

        this._hostedFieldsReady = true;
    }

    async _refreshHostedFields() {
        const result = await BlueSnapApi.createSavedCardToken();
        if (!result || !result.success || typeof result.message !== 'string') {
            this._showError(this.genericErrorText);
            return;
        }

        this.wrapper.setAttribute('data-pf-token', result.message);
        this._createHostedFields(result.message);
    }

    async _refreshCartRegions() {
        let html;
        try {
            const response = await fetch(window.location.href, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            html = await response.text();
        } catch (error) {
            console.error('BlueSnap: could not refresh the cart totals', error);
            return;
        }

        const doc = new DOMParser().parseFromString(html, 'text/html');

        this.options.cartRegionSelectors.forEach((selector) => {
            const fresh = doc.querySelector(selector);
            const current = document.querySelector(selector);
            if (fresh && current) {
                current.replaceWith(fresh);
            }
        });

        this._bindOrderForm(true);
        window.PluginManager?.initializePlugins?.();

        const freshWrapper = doc.getElementById(this.options.parentCreditCardWrapperId);
        if (!freshWrapper) {
            return;
        }

        ['data-surcharge-token', 'data-surcharge-amount', 'data-secured-amount'].forEach((attribute) => {
            this.wrapper.setAttribute(attribute, freshWrapper.getAttribute(attribute) || '');
        });

        this.securedAmount = this.wrapper.getAttribute('data-secured-amount');
        this.threeDSecureObject.amount = parseFloat(this.securedAmount);
    }

    /** @returns {Promise<boolean>} whether a surcharge is now known */
    async _calculateSurcharge(payload) {
        const result = await BlueSnapApi.calculateSurcharge({
            amount: parseFloat(this.securedAmount),
            ...payload,
        });

        if (!result?.success || !result?.message?.surchargeInfo) {
            this._showError(this._describeFailure(result));
            return false;
        }

        this._calculatedSurcharge = {
            token: result.message.surchargeInfo.surchargeToken || '',
            amount: parseFloat(result.message.surchargeInfo.surchargeAmount || 0) || 0,
            forCardKey: payload.cardKey ?? null,
        };

        return this._calculatedSurcharge.token !== '';
    }

    /* ------------------------------------------------------------------- payment */

    async _onOrderSubmitButtonClick(event) {
        event.preventDefault();

        if (this._busy || !this.savedCardRadios) {
            return;
        }

        if (!this.confirmOrderForm.checkValidity()) {
            this.confirmOrderForm.reportValidity();
            return;
        }

        if (this.surchargePanel && !this.surchargePanel.classList.contains('d-none')) {
            this._scrollIntoView();
            return;
        }

        this._clearError();
        this._scrollIntoView();

        const selectedCard = this._getSelectedCardRadio();

        if (selectedCard) {
            await this._paySavedCard(selectedCard);
            return;
        }

        await this._tokeniseNewCard();
    }

    async _paySavedCard(selectedCard) {
        if (this.isSurchargeActive && !this._surchargePayload(selectedCard.value).surchargeToken) {
            this._setBusy(true);
            const priced = await this._calculateSurcharge({ cardKey: selectedCard.value });
            this._setBusy(false);

            if (!priced) {
                return;
            }

            this._showSurchargeConfirmation(() => this._chargeSavedCard(selectedCard));

            return;
        }

        await this._chargeSavedCard(selectedCard);
    }

    async _chargeSavedCard(selectedCard) {
        this._hideSurchargeConfirmation();

        const body = {
            pfToken: this.wrapper.getAttribute('data-pf-token'),
            vaultedId: this.vaultedId,
            cardKey: selectedCard.value,
            amount: String(this.securedAmount),
            ...this._surchargePayload(selectedCard.value),
        };

        if (!this.threeDS) {
            await this._submitPayment(body, BlueSnapApi.vaultedShopper);
            return;
        }

        bluesnap.threeDsPaymentsSetup(this.wrapper.getAttribute('data-pf-token'), async (sdkResponse) => {
            if (sdkResponse.code != 1) {
                this._showError(sdkResponse.info?.errors || this.genericErrorText);
                this._setBusy(false);
                return;
            }

            body.threeDSecureReferenceId = sdkResponse.threeDSecure?.threeDSecureReferenceId;
            body.authResult = sdkResponse.threeDSecure?.authResult;

            await this._submitPayment(body, BlueSnapApi.vaultedShopper);
        });

        bluesnap.threeDsPaymentsSubmitData({
            last4Digits: selectedCard.getAttribute('data-bluesnap-card-last-four'),
            ccType: selectedCard.getAttribute('data-bluesnap-card-type'),
            amount: parseFloat(this.securedAmount) + parseFloat(this._surchargePayload(selectedCard.value).surchargeAmount || 0),
            currency: this.securedCurrency,
        });
    }

    /** Hands the card to BlueSnap once; the token is reused for the surcharge and the capture. */
    async _tokeniseNewCard() {
        if (!this._validateNewCardFields()) {
            return;
        }

        await this._ensureHostedFields();

        if (!this.hostedFieldsToken) {
            this._showError(this.genericErrorText);
            return;
        }

        if (this._pendingNewCard) {
            await this._payNewCard();
            return;
        }

        this._setBusy(true);

        bluesnap.hostedPaymentFieldsSubmitData(async (callback) => {
            if (callback.error != null) {
                this._showError(callback.error.map((e) => `${e.errorCode}: ${e.errorDescription}`).join(' '));
                this._setBusy(false);
                return;
            }


            if (this.threeDS && callback.threeDSecure?.authResult === 'AUTHENTICATION_FAILED') {
                this._showError(this.threeDSecureFailedText);
                this._setBusy(false);
                return;
            }

            this._pendingNewCard = {
                pfToken: this.hostedFieldsToken,
                cardType: callback.cardData?.ccType || 'CREDIT',
                lastFourDigits: callback.cardData?.last4Digits || '',
                threeDSecureReferenceId: callback.threeDSecure?.threeDSecureReferenceId,
                authResult: callback.threeDSecure?.authResult,
            };

            if (!this.isSurchargeActive) {
                await this._payNewCard();
                return;
            }

            await this._calculateSurchargeForNewCard();
        }, this.threeDS ? this.threeDSecureObject : undefined);
    }

    async _calculateSurchargeForNewCard() {
        const priced = await this._calculateSurcharge({
            pfToken: this._pendingNewCard.pfToken,
            cardType: this._pendingNewCard.cardType,
        });

        if (!priced) {
            this._discardPendingNewCard();
            this._setBusy(false);
            return;
        }

        await this._refreshCartRegions();
        this._showSurchargeConfirmation(() => this._payNewCard());
        this._setBusy(false);
    }

    async _payNewCard() {
        if (!this._pendingNewCard) {
            return;
        }

        this._setBusy(true);
        this._hideSurchargeConfirmation();

        const body = {
            pfToken: this._pendingNewCard.pfToken,
            amount: String(this.securedAmount),
            firstName: document.getElementById('bluesnap-first-name').value,
            lastName: document.getElementById('bluesnap-last-name').value,
            saveCard: document.getElementById('bluesnap-save-card')?.checked || false,
            cardType: this._pendingNewCard.cardType,
            lastFourDigits: this._pendingNewCard.lastFourDigits,
            ...this._surchargePayload(null),
        };

        if (this.threeDS) {
            body.threeDSecureReferenceId = this._pendingNewCard.threeDSecureReferenceId;
            body.authResult = this._pendingNewCard.authResult;
        }

        await this._submitPayment(body, BlueSnapApi.capture);
    }

    async _submitPayment(body, apiCall) {
        this._setBusy(true);
        this.newCardSection?.classList.add('d-none');
        this.loaderElement?.classList.remove('d-none');

        let failure;

        try {
            if (this.flow === 'order_payment') {
                document.getElementById('paymentData').value = JSON.stringify(body);
                this._submitOrderForm();

                return;
            }

            const result = await apiCall(body);

            if (result && result.success) {
                document.getElementById('bluesnap-transaction-id').value = JSON.parse(result.message).transactionId;
                this._submitOrderForm();

                return;
            }

            failure = this._describeFailure(result);
        } catch (error) {
            console.error('BlueSnap: the order could not be submitted', error);
            failure = this.genericErrorText;
        }

        this.loaderElement?.classList.add('d-none');
        this.newCardSection?.classList.toggle('d-none', this._getSelectedCardRadio() !== null);
        this._showError(failure);
        this._discardPendingNewCard();
        this._setBusy(false);
    }

    /** Submits the form currently in the document; a detached form submits silently to nothing. */
    _submitOrderForm() {
        this._bindOrderForm(true);
        this.confirmOrderForm.submit();
    }

    /**
     * A surcharge token is only valid for the card it was quoted for.
     *
     * @param {string|null} cardKey the stored card being charged, or null for a newly entered card
     */
    _surchargePayload(cardKey) {
        const quote = this._calculatedSurcharge;

        if (quote?.token && (quote.forCardKey ?? null) === (cardKey ?? null)) {
            return { surchargeToken: quote.token, surchargeAmount: String(quote.amount) };
        }

        if (cardKey !== null && cardKey === this.serverSelectedCardKey) {
            const token = this.wrapper.getAttribute('data-surcharge-token') || '';

            if (token) {
                return {
                    surchargeToken: token,
                    surchargeAmount: String(parseFloat(this.wrapper.getAttribute('data-surcharge-amount') || '0') || 0),
                };
            }
        }

        return {};
    }

    /* -------------------------------------------------------- surcharge disclosure */

    _showSurchargeConfirmation(onConfirm) {
        if ((this._calculatedSurcharge?.amount ?? 0) <= 0) {
            onConfirm();
            return;
        }

        if (!this.surchargePanel) {
            return;
        }

        this._pendingConfirm = onConfirm;

        const surcharge = this._calculatedSurcharge?.amount ?? 0;
        const total = parseFloat(this.securedAmount) + surcharge;

        if (this.surchargePanelText) {
            this.surchargePanelText.innerText = this.surchargeNoticeText
                .replace('%surcharge%', this._formatMoney(surcharge))
                .replace('%total%', this._formatMoney(total));
        }

        this.surchargePanel.classList.remove('d-none');
        this.newCardSection?.classList.add('d-none');
        this._scrollIntoView();
    }

    _hideSurchargeConfirmation() {
        this._pendingConfirm = null;
        this.surchargePanel?.classList.add('d-none');
    }

    /** Lets the customer go back and change the card; the tokenised card cannot be reused. */
    async _cancelSurchargeConfirmation() {
        const wasNewCard = this._pendingNewCard !== null;

        this._setBusy(true);
        this._hideSurchargeConfirmation();
        this._discardPendingNewCard();
        this._calculatedSurcharge = null;

        if (wasNewCard) {
            await BlueSnapApi.selectSavedCard('');
            await this._refreshCartRegions();
            await this._refreshHostedFields();
            this.newCardSection?.classList.remove('d-none');
        }

        this._setBusy(false);
    }

    _discardPendingNewCard() {
        this._pendingNewCard = null;
    }

    _discardCalculatedSurcharge() {
        this._calculatedSurcharge = null;
    }

    _formatMoney(value) {
        try {
            return new Intl.NumberFormat(this.locale, { style: 'currency', currency: this.securedCurrency })
                .format(value);
        } catch {
            return `${value.toFixed(2)} ${this.securedCurrency}`;
        }
    }

    /* ------------------------------------------------------------------ utilities */

    _validateNewCardFields() {
        let valid = true;

        ['bluesnap-first-name', 'bluesnap-last-name'].forEach((id) => {
            const input = document.getElementById(id);
            const filled = input && input.value.trim() !== '';
            input?.classList.toggle('is-invalid', !filled);
            valid = valid && filled;
        });

        if (!valid) {
            this._showError(this.validationErrorText);
        }

        return valid;
    }

    _describeFailure(result) {
        const message = result?.message;

        try {
            const parsed = typeof message === 'string' ? JSON.parse(message) : message;
            const description = parsed?.[0]?.description || parsed?.message;
            if (description) {
                return String(description).split('-')[0].trim();
            }
        } catch {
            if (typeof message === 'string' && message !== '') {
                return message;
            }
        }

        return this.genericErrorText;
    }

    _setFieldState(tagId, removeClass, addClass) {
        const element = this.wrapper.querySelector(`[data-bluesnap="${tagId}"]`);
        if (!element) {
            return;
        }

        if (removeClass) {
            element.classList.remove(...removeClass.split(' '));
        }
        if (addClass) {
            element.classList.add(...addClass.split(' '));
        }
    }

    _setBusy(busy) {
        this._busy = busy;
        this.savedCardRadios.forEach((radio) => {
            radio.disabled = busy || radio.closest('.bluesnap-saved-card-option--expired') !== null;
        });
    }

    _showError(text) {
        if (!this.errorMessage) {
            return;
        }

        this.errorMessage.querySelector('p').innerText = text;
        this.errorMessage.classList.remove('d-none');
        this._scrollIntoView();
    }

    _clearError() {
        this.errorMessage?.classList.add('d-none');
    }

    _scrollIntoView() {
        this.wrapper.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}
