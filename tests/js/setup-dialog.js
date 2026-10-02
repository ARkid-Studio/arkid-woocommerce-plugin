/**
 * jsdom does not implement HTMLDialogElement's modal API, so every test that
 * opens the viewer would fail on a missing method. Provide the parts the
 * storefront script actually uses, including the `close` event that drives
 * focus restoration.
 */
if (typeof window !== 'undefined' && window.HTMLDialogElement) {
	if (!window.HTMLDialogElement.prototype.showModal) {
		window.HTMLDialogElement.prototype.showModal = function showModal() {
			this.open = true;
		};
	}
	if (!window.HTMLDialogElement.prototype.close) {
		window.HTMLDialogElement.prototype.close = function close() {
			this.open = false;
			this.dispatchEvent(new window.Event('close'));
		};
	}
}
