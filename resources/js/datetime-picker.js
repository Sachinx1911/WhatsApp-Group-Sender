/**
 * Alpine component behind <x-ui.datetime-picker>: a Date field with a month calendar and
 * a Time field with a list of slots. Reads and writes "YYYY-MM-DDTHH:mm" (the
 * datetime-local format the backend validates) through an entangled Livewire property.
 */
export default function datetimePicker({ value, step = 15, maxDays = 60 }) {
    return {
        value,
        step,
        maxDays,
        open: null,
        date: '',
        time: '',
        view: null, // { y, m } of the month on screen

        init() {
            this.readValue();
            this.$watch('value', () => this.readValue());

            // Start on today so the usual case, "a bit later today", only needs a time.
            if (!this.date && !this.time) this.date = this.todayIso();
        },

        /** The first slot still ahead of the clock today: where the time list opens and what it highlights until a time is chosen. */
        suggestedSlot() {
            if (this.date && this.date !== this.todayIso()) return '07:00';
            const now = new Date();
            const mins = Math.ceil((now.getHours() * 60 + now.getMinutes() + 3) / this.step) * this.step;
            if (mins >= 24 * 60) return '23:45';
            return `${String(Math.floor(mins / 60)).padStart(2, '0')}:${String(mins % 60).padStart(2, '0')}`;
        },

        // --- syncing with the Livewire property ---
        readValue() {
            const m = /^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})$/.exec(this.value || '');

            // An empty value also arrives right after we wrote one ourselves because only
            // the date or only the time is chosen so far. Keep that half-made choice; only
            // an external clear (both were set) empties the fields.
            if (!m && (this.date === '') !== (this.time === '')) return;

            this.date = m ? m[1] : '';
            this.time = m ? m[2] : '';
            if (!this.view) {
                const base = this.date ? this.parse(this.date) : new Date();
                this.view = { y: base.getFullYear(), m: base.getMonth() };
            }
        },
        writeValue() {
            this.value = this.date && this.time ? `${this.date}T${this.time}` : '';
        },

        // --- popovers ---
        toggle(which) {
            this.open = this.open === which ? null : which;
            if (this.open === 'date' && this.date) {
                const d = this.parse(this.date);
                this.view = { y: d.getFullYear(), m: d.getMonth() };
            }
            if (this.open === 'time') this.$nextTick(() => this.scrollToTime());
        },
        closeAll() {
            this.open = null;
        },

        // --- calendar ---
        todayIso() {
            return this.iso(new Date());
        },
        iso(d) {
            return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        },
        parse(iso) {
            const [y, m, d] = iso.split('-').map(Number);
            return new Date(y, m - 1, d);
        },
        monthLabel() {
            return new Date(this.view.y, this.view.m, 1).toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });
        },
        dateLabel(iso) {
            return this.parse(iso).toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
        },
        maxDate() {
            const d = new Date();
            d.setDate(d.getDate() + this.maxDays);
            return d;
        },
        canGoPrev() {
            const t = new Date();
            return this.view.y > t.getFullYear() || (this.view.y === t.getFullYear() && this.view.m > t.getMonth());
        },
        canGoNext() {
            const x = this.maxDate();
            return this.view.y < x.getFullYear() || (this.view.y === x.getFullYear() && this.view.m < x.getMonth());
        },
        prevMonth() {
            if (!this.canGoPrev()) return;
            this.view = this.view.m === 0 ? { y: this.view.y - 1, m: 11 } : { y: this.view.y, m: this.view.m - 1 };
        },
        nextMonth() {
            if (!this.canGoNext()) return;
            this.view = this.view.m === 11 ? { y: this.view.y + 1, m: 0 } : { y: this.view.y, m: this.view.m + 1 };
        },
        cells() {
            const first = new Date(this.view.y, this.view.m, 1);
            const days = new Date(this.view.y, this.view.m + 1, 0).getDate();
            const today = this.todayIso();
            const max = this.iso(this.maxDate());
            const out = Array(first.getDay()).fill(null);
            for (let day = 1; day <= days; day++) {
                const iso = this.iso(new Date(this.view.y, this.view.m, day));
                out.push({ day, iso, today: iso === today, disabled: iso < today || iso > max });
            }
            return out;
        },
        pickDate(cell) {
            if (!cell || cell.disabled) return;
            this.date = cell.iso;
            // A time chosen for another day may be in the past today; drop it so it is re-chosen.
            if (this.time && this.slotDisabled(this.time)) this.time = '';
            this.writeValue();
            this.open = this.time ? null : 'time';
            if (this.open === 'time') this.$nextTick(() => this.scrollToTime());
        },
        clear() {
            this.date = this.todayIso();
            this.time = '';
            this.writeValue();
            this.open = null;
        },

        // --- time slots ---
        slots() {
            const out = [];
            for (let mins = 0; mins < 24 * 60; mins += this.step) {
                const h = Math.floor(mins / 60);
                const m = mins % 60;
                const value = `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
                out.push({ value, hm: this.hm(value), ampm: h < 12 ? 'AM' : 'PM', disabled: this.slotDisabled(value) });
            }
            return out;
        },
        slotDisabled(value) {
            if (!this.date || this.date !== this.todayIso()) return false;
            const [h, m] = value.split(':').map(Number);
            const now = new Date();
            return h * 60 + m <= now.getHours() * 60 + now.getMinutes() + 2;
        },
        hm(value) {
            const [h, m] = value.split(':').map(Number);
            return `${((h + 11) % 12) + 1}:${String(m).padStart(2, '0')}`;
        },
        timeLabel(value) {
            const [h] = value.split(':').map(Number);
            return `${this.hm(value)} ${h < 12 ? 'AM' : 'PM'}`;
        },
        pickTime(value) {
            if (this.slotDisabled(value)) return;
            this.time = value;
            this.writeValue();
            this.open = this.date ? null : 'date';
        },
        scrollToTime() {
            const list = this.$refs.slots;
            if (!list) return;
            const target = list.querySelector(`[data-slot="${this.time || this.suggestedSlot()}"]`);
            if (target) list.scrollTop = Math.max(0, target.offsetTop - list.clientHeight / 2 + target.offsetHeight / 2);
        },
    };
}
