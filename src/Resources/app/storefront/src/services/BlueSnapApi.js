class BlueSnapApi {
    endpoints = {
        capture: async (body) => await this._makeRequest('/capture', 'POST', body),
        googleCapture: async (body) => await this._makeRequest('/google-capture', 'POST', body),
        appleCreateWallet: async (body) => await this._makeRequest('/apple-create-wallet', 'POST', body),
        appleCapture: async (body) => await this._makeRequest('/apple-capture', 'POST', body),
        vaultedShopper: async (body) => await this._makeRequest('/vaulted-shopper', 'POST', body),
        updateVaultedShopper: async (vaultedShopperId, body) => await this._makeRequest(`/update-vaulted-shopper/` + vaultedShopperId, 'PUT', body),
        calculateSurcharge: async (body) => await this._makeRequest('/calculate-surcharge', 'POST', body),
        getPfToken: async () => await this._makeRequest('/get-pf-token', 'GET'),
        selectSavedCard: async (cardKey) => await this._makeRequest('/bluesnap/saved-cards/select', 'POST', { cardKey }),
        listSavedCards: async () => await this._makeRequest('/bluesnap/saved-cards', 'GET'),
        createSavedCardToken: async () => await this._makeRequest('/bluesnap/saved-cards/token', 'POST', {}),
        addSavedCard: async (pfToken) => await this._makeRequest('/bluesnap/saved-cards', 'POST', { pfToken }),
        removeSavedCard: async (cardKey) => await this._makeRequest(`/bluesnap/saved-cards/${cardKey}/delete`, 'POST', {}),
        setPreferredSavedCard: async (cardKey) => await this._makeRequest(`/bluesnap/saved-cards/${cardKey}/preferred`, 'POST', {}),
    };

    async _makeRequest(url, method, body) {
        const headers = new Headers();
        headers.append("Content-Type", "application/json");

        const requestOptions = {
            method: method,
            headers: headers,
        };
        if (body) {
            requestOptions.body = JSON.stringify(body);
        }
        try {
            const response = await fetch(url, requestOptions);
            const data = await response.json();

            if (data && typeof data === 'object') {
                data.status = response.status;
            }

            return data;
        } catch (err) {
            console.error(JSON.stringify(err, null, 2));
        }
    }
}

export default new BlueSnapApi().endpoints;