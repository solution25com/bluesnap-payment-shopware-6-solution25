export default class CDNLoader {
    constructor(cdnUrl) {
        this.cdnUrl = cdnUrl;
    }

    /**
     * @param {Function} callback  invoked once the script has loaded
     * @param {Function} [onError] invoked when the script cannot be loaded; without it the failure is
     *                             only logged, which leaves callers awaiting the callback forever.
     */
    loadScript(callback, onError) {
        if (!this.cdnUrl) {
            console.error('BlueSnap: no script URL was provided');
            if (onError) onError(new Error('Missing script URL'));
            return;
        }

        const script = document.createElement('script');
        script.src = this.cdnUrl;
        script.onload = () => {
            if (callback) callback();
        };
        script.onerror = () => {
            console.error(`BlueSnap: failed to load script ${this.cdnUrl}`);
            if (onError) onError(new Error(`Failed to load ${this.cdnUrl}`));
        };
        document.body.appendChild(script);
    }
}
