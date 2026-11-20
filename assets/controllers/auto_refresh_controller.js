import { Controller } from '@hotwired/stimulus';
import { visit } from '@hotwired/turbo';

/* Reloads the page while it is shown; stops as soon as the user leaves it. */
export default class extends Controller {
    static values = { seconds: { type: Number, default: 2 } };

    connect() {
        this.stop = this.stop.bind(this);
        document.addEventListener('turbo:visit', this.stop);
        document.addEventListener('turbo:submit-start', this.stop);
        this.timer = setTimeout(() => visit(window.location.href, { action: 'replace' }), this.secondsValue * 1000);
    }

    disconnect() {
        this.stop();
    }

    stop() {
        clearTimeout(this.timer);
        document.removeEventListener('turbo:visit', this.stop);
        document.removeEventListener('turbo:submit-start', this.stop);
    }
}
