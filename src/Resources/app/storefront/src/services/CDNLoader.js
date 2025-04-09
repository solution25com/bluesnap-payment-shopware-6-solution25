export default class CDNLoader {
  constructor(cdnUrl) {
    this.cdnUrl = cdnUrl;
  }
  loadScript(callback) {
    const script = document.createElement('script');
    script.src = this.cdnUrl;
    script.onload = () => {
      console.log('Script loaded successfully');
      if (callback) callback();
    };
    script.onerror = () => {
      console.error('Failed to load script');
    };
    document.body.appendChild(script);
  }
}
