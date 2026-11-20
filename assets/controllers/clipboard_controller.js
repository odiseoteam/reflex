import { Controller } from '@hotwired/stimulus';

/* Copies the source text and shows a check for a moment. */
export default class extends Controller {
    static targets = ['source', 'button', 'copyIcon', 'done'];

    async copy() {
        const text = this.sourceTarget.textContent.trim();
        try {
            await navigator.clipboard.writeText(text);
        } catch {
            // The Clipboard API needs HTTPS or localhost
            this.copyBySelection(text);
        }

        this.show(true);
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.show(false), 2000);
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    copyBySelection(text) {
        const area = document.createElement('textarea');
        area.value = text;
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.append(area);
        area.select();
        document.execCommand('copy');
        area.remove();
    }

    show(copied) {
        // SVG elements have no hidden property, only the attribute
        this.copyIconTarget.toggleAttribute('hidden', copied);
        this.doneTarget.toggleAttribute('hidden', !copied);
        this.buttonTarget.title = copied ? 'Copied' : 'Copy';
        this.buttonTarget.setAttribute('aria-label', copied ? 'Copied' : 'Copy to clipboard');
    }
}
