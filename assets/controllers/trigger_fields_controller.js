import { Controller } from '@hotwired/stimulus';

/* Shows only the fields of the selected trigger. */
export default class extends Controller {
    static targets = ['source'];

    connect() {
        this.toggle();
    }

    toggle() {
        const source = this.sourceTarget.value;
        this.element.querySelectorAll('[data-trigger-for]').forEach((group) => {
            group.hidden = !group.dataset.triggerFor.split(' ').includes(source);
        });
    }
}
