import { Controller } from '@hotwired/stimulus';

const time = { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' };
const formats = {
    time,
    'time-seconds': { ...time, second: '2-digit' },
    datetime: { month: 'short', day: 'numeric', ...time },
    'datetime-seconds': { month: 'short', day: 'numeric', ...time, second: '2-digit' },
    date: { month: 'short', day: 'numeric', year: 'numeric' },
    day: { weekday: 'long', day: 'numeric', month: 'long' },
};

/* Shows a <time> in the viewer's time zone; the server renders it in UTC. */
export default class extends Controller {
    static values = { format: { type: String, default: 'datetime' } };

    connect() {
        const at = new Date(this.element.getAttribute('datetime'));
        if (Number.isNaN(at.getTime())) {
            return;
        }

        this.element.textContent = new Intl.DateTimeFormat('en', formats[this.formatValue] ?? formats.datetime).format(at);
        this.element.title = new Intl.DateTimeFormat('en', { dateStyle: 'full', timeStyle: 'long' }).format(at);
    }
}
