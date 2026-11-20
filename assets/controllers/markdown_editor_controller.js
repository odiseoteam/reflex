import { Controller } from '@hotwired/stimulus';
import '@github/markdown-toolbar-element';

/* A textarea with a Markdown toolbar and a preview rendered by the server. */
export default class extends Controller {
    static targets = ['input', 'preview', 'toolbar', 'writeTab', 'previewTab'];
    static values = { url: String };

    write() {
        this.show(false);
        this.inputTarget.focus();
    }

    async preview() {
        this.previewTarget.style.minHeight = `${this.inputTarget.offsetHeight}px`;
        this.previewTarget.textContent = 'Loading preview…';
        this.show(true);

        const body = new FormData();
        body.append('text', this.inputTarget.value);
        try {
            const response = await fetch(this.urlValue, { method: 'POST', body });
            this.previewTarget.innerHTML = response.ok ? await response.text() : '<p>The preview is not available.</p>';
        } catch {
            this.previewTarget.textContent = 'The preview is not available.';
        }
    }

    show(preview) {
        this.inputTarget.hidden = preview;
        this.toolbarTarget.hidden = preview;
        this.previewTarget.hidden = !preview;
        this.writeTabTarget.setAttribute('aria-selected', String(!preview));
        this.previewTabTarget.setAttribute('aria-selected', String(preview));
    }
}
