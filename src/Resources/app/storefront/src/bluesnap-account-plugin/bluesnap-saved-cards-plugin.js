import BlueSnapApi from '../services/BlueSnapApi';
import CDNLoader from '../services/CDNLoader';

/**
 * Saved-card management in the customer account.
 */
export default class BluesnapSavedCardsPlugin extends window.PluginBaseClass {
    static options = {
        scriptUrl: '',
        showFormLabel: '+ Add card',
        hideFormLabel: '- Hide form',
        removeConfirm: 'Remove this card?',
        requestFailed: 'The request could not be completed.',
        tokenFailed: 'The secure card form could not be loaded.',
        errorAlreadySaved: 'This card is already saved.',
        errorNotFound: 'This card could not be found.',
        ccnPlaceholder: 'Card number',
        expPlaceholder: 'MM / YY',
        cvvPlaceholder: 'CVV',
    };

    init() {
        this._bindElements();
        this._registerEvents();
        this._sdkLoaded = false;
        this._fieldsReady = false;
        this._busy = false;
    }

    _bindElements() {
        this.form = this.el.querySelector('[data-bluesnap-form]');
        this.toggleButton = this.el.querySelector('[data-bluesnap-toggle-form]');
        this.cancelButton = this.el.querySelector('[data-bluesnap-cancel-form]');
        this.submitButton = this.el.querySelector('[data-bluesnap-submit-card]');
        this.message = this.el.querySelector('[data-bluesnap-message]');
    }

    _registerEvents() {
        this.toggleButton?.addEventListener('click', () => this._toggleForm());
        this.cancelButton?.addEventListener('click', () => this._hideForm());
        this.submitButton?.addEventListener('click', () => this._submitCard());

        this.el.querySelectorAll('[data-bluesnap-remove-card]').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.stopPropagation();
                this._removeCard(button.closest('[data-bluesnap-card]'), button);
            });
        });

        document.addEventListener('click', () => this._disarmRemoval());

        this.el.querySelectorAll('[data-bluesnap-preferred-card]').forEach((button) => {
            button.addEventListener('click', () => this._setPreferred(button.closest('[data-bluesnap-card]')));
        });
    }

    async _toggleForm() {
        if (this.form?.classList.contains('d-none')) {
            await this._showForm();
            return;
        }

        this._hideForm();
    }

    async _showForm() {
        this._clearMessage();
        this.form?.classList.remove('d-none');

        if (this.toggleButton) {
            this.toggleButton.textContent = this.options.hideFormLabel;
            this.toggleButton.classList.add('is-open');
        }

        await this._mountHostedFields();
    }

    _hideForm() {
        this.form?.classList.add('d-none');

        if (this.toggleButton) {
            this.toggleButton.textContent = this.options.showFormLabel;
            this.toggleButton.classList.remove('is-open');
        }

        this._clearFieldErrors();
        this._clearMessage();
    }

    _loadSdk() {
        if (this._sdkLoaded) {
            return Promise.resolve(true);
        }

        if (!this._sdkPromise) {
            this._sdkPromise = new Promise((resolve) => {
                new CDNLoader(this.options.scriptUrl).loadScript(
                    () => {
                        this._sdkLoaded = true;
                        resolve(true);
                    },
                    () => {
                        this._sdkPromise = null;
                        resolve(false);
                    }
                );
            });
        }

        return this._sdkPromise;
    }

    async _mountHostedFields() {
        const loaded = await this._loadSdk();

        if (!loaded || typeof bluesnap === 'undefined') {
            this._showMessage(this.options.tokenFailed, 'danger');
            return;
        }

        const result = await BlueSnapApi.createSavedCardToken();
        if (!result || !result.success || typeof result.message !== 'string') {
            this._showMessage(this.options.tokenFailed, 'danger');
            return;
        }
        this._pfToken = result.message;

        bluesnap.hostedPaymentFieldsCreate({
            token: this._pfToken,
            onFieldEventHandler: {
                onError: (tagId, errorCode, errorDescription) => {
                    this._setFieldError(tagId, `${errorCode}: ${errorDescription}`);
                },
                onValid: (tagId) => this._clearFieldError(tagId),
                onFocus: (tagId) => this._clearFieldError(tagId),
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
            ccnPlaceHolder: this.options.ccnPlaceholder,
            expPlaceHolder: this.options.expPlaceholder,
            cvvPlaceHolder: this.options.cvvPlaceholder,
        });

        this._fieldsReady = true;
    }

    _submitCard() {
        if (this._busy) {
            return;
        }

        if (!this._fieldsReady || !this._pfToken || typeof bluesnap === 'undefined') {
            this._showMessage(this.options.tokenFailed, 'danger');
            return;
        }

        this._clearMessage();
        this._clearFieldErrors();
        this._setBusy(true);

        bluesnap.hostedPaymentFieldsSubmitData(async (callback) => {
            if (callback.error) {
                callback.error.forEach((error) => {
                    this._setFieldError(error.tagId, `${error.errorCode}: ${error.errorDescription}`);
                });
                this._setBusy(false);
                return;
            }

            const result = await BlueSnapApi.addSavedCard(this._pfToken);

            if (!result || !result.success) {
                this._showMessage(this._errorText(result), 'danger');
                this._fieldsReady = false;
                this._pfToken = null;
                await this._mountHostedFields();
                this._setBusy(false);
                return;
            }

            window.location.reload();
        });
    }

    async _removeCard(card, button) {
        const cardKey = card?.dataset.bluesnapCardKey;
        if (!cardKey || this._busy) {
            return;
        }

        // Removing a card is irreversible, so the first click only asks for confirmation.
        if (this._armedRemoval !== button) {
            this._armRemoval(button);
            return;
        }

        this._disarmRemoval();
        this._setBusy(true);
        const result = await BlueSnapApi.removeSavedCard(cardKey);

        if (!result || !result.success) {
            this._showMessage(this._errorText(result), 'danger');
            this._setBusy(false);
            return;
        }

        window.location.reload();
    }

    _armRemoval(button) {
        this._disarmRemoval();

        this._armedRemoval = button;
        this._armedRemovalLabel = button.getAttribute('aria-label');
        button.classList.add('is-confirming');
        button.setAttribute('aria-label', this.options.removeConfirm);
        button.setAttribute('title', this.options.removeConfirm);
    }

    _disarmRemoval() {
        const button = this._armedRemoval;
        if (!button) {
            return;
        }

        button.classList.remove('is-confirming');
        button.setAttribute('aria-label', this._armedRemovalLabel);
        button.setAttribute('title', this._armedRemovalLabel);
        this._armedRemoval = null;
    }

    async _setPreferred(card) {
        const cardKey = card?.dataset.bluesnapCardKey;
        if (!cardKey || this._busy) {
            return;
        }

        this._setBusy(true);
        const result = await BlueSnapApi.setPreferredSavedCard(cardKey);

        if (!result || !result.success) {
            this._showMessage(this._errorText(result), 'danger');
            this._setBusy(false);
            return;
        }

        window.location.reload();
    }

    _errorText(result) {
        if (result?.status === 409) {
            return this.options.errorAlreadySaved;
        }

        if (result?.status === 404) {
            return this.options.errorNotFound;
        }

        const message = result?.message;

        if (typeof message === 'string' && message !== '') {
            return message;
        }

        return this.options.requestFailed;
    }

    _setFieldError(tagId, text) {
        const error = this.el.querySelector(`[data-bluesnap-error="${tagId}"]`);
        const field = this.el.querySelector(`[data-bluesnap="${tagId}"]`);

        field?.classList.add('is-invalid');

        if (error) {
            error.textContent = text;
            error.classList.remove('d-none');
        }
    }

    _clearFieldError(tagId) {
        const error = this.el.querySelector(`[data-bluesnap-error="${tagId}"]`);
        const field = this.el.querySelector(`[data-bluesnap="${tagId}"]`);

        field?.classList.remove('is-invalid');

        if (error) {
            error.textContent = '';
            error.classList.add('d-none');
        }
    }

    _clearFieldErrors() {
        this.el.querySelectorAll('[data-bluesnap-error]').forEach((error) => {
            this._clearFieldError(error.dataset.bluesnapError);
        });
    }

    _showMessage(text, variant) {
        if (!this.message) {
            return;
        }

        this.message.textContent = text;
        this.message.className = `bluesnap-saved-cards-alert alert alert-${variant}`;
    }

    _clearMessage() {
        if (!this.message) {
            return;
        }

        this.message.textContent = '';
        this.message.className = 'bluesnap-saved-cards-alert alert d-none';
    }

    _setBusy(busy) {
        this._busy = busy;

        if (this.submitButton) {
            this.submitButton.disabled = busy;
        }

        this.el.querySelectorAll('[data-bluesnap-remove-card], [data-bluesnap-preferred-card]').forEach((button) => {
            button.disabled = busy;
        });
    }
}
