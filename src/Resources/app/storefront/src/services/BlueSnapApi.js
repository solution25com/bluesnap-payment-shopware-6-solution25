class BlueSnapApi {

  endpoints = {
    capture: async (body) => await this._makeRequest('/capture', 'POST', body),
    googleCapture: async (body) => await this._makeRequest('/google-capture', 'POST', body),
    appleCreateWallet: async (body) => await this._makeRequest('/apple-create-wallet', 'POST', body),
    appleCapture: async (body) => await this._makeRequest('/apple-capture', 'POST', body),
    vaultedShopper: async (body) => await this._makeRequest('/vaulted-shopper', 'POST',body),
    updateVaultedShopper: async (vaultedShopperId, body) => await this._makeRequest(`/update-vaulted-shopper/` + vaultedShopperId, 'PUT', body),
  }

  async _makeRequest(url, method, body) {
    const headers = new Headers()
    headers.append("Content-Type", "application/json");

    const requestOptions = {
      method: method,
      headers: headers,
    };
    if (body) {
      requestOptions.body = JSON.stringify(body);
    }
    try {
      const response = await fetch(url, requestOptions)
      return await response.json();
    } catch (err) {
      console.log(JSON.stringify(err, null, 2));
    }
  }
}

export default new BlueSnapApi().endpoints;