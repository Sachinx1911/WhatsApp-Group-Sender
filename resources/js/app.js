import './bootstrap';

// Modal/drawer events carry the name as a string ($dispatch('open-modal', 'x')),
// an array (Livewire positional params) or an object ({ name: 'x' }).
window.modalName = (detail) => (Array.isArray(detail) ? detail[0] : detail?.name ?? detail);

// Open modals/drawers, newest last. Escape closes only the top one, wherever the focus is.
window.modalStack = [];
window.trackModal = (name, open) => {
    const index = window.modalStack.lastIndexOf(name);
    if (index !== -1) window.modalStack.splice(index, 1);
    if (open) window.modalStack.push(name);
};
window.isTopModal = (name) => window.modalStack.at(-1) === name;

// WhatsApp formatting for live previews. Mirrors App\Support\WhatsAppFormatter::toHtml().
const escapeHtml = (text) =>
    text.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[c]);

window.formatWhatsApp = (text) => {
    let html = escapeHtml(text ?? '');
    html = html.replace(/```([\s\S]+?)```/g, '<code class="wa-mono">$1</code>');
    html = html.replace(/`([^`\n]+)`/g, '<code class="wa-code">$1</code>');
    for (const [marker, tag] of [['\\*', 'strong'], ['_', 'em'], ['~', 'del']]) {
        const pattern = new RegExp(
            `(^|[\\s(>\\[.,!?:;"'])${marker}(?=\\S)(.+?)(?<=\\S)${marker}(?=$|[\\s)<\\].,!?:;"'])`,
            'gmu',
        );
        html = html.replace(pattern, `$1<${tag}>$2</${tag}>`);
    }
    return html.replace(/\n/g, '<br>');
};

// Settings → Appearance: apply at once, without a reload.
window.addEventListener('appearance-changed', (event) => {
    const detail = Array.isArray(event.detail) ? event.detail[0] : event.detail;
    const root = document.documentElement;
    root.classList.toggle('compact-tables', !!detail.compactTables);
    root.classList.toggle('sidebar-collapsed', !!detail.sidebarCollapsed);
    try {
        localStorage.setItem('sidebar-collapsed', detail.sidebarCollapsed ? '1' : '0');
    } catch (e) {}
});

// Desktop notifications (Settings → Notifications). Shown only when the browser allowed it.
window.addEventListener('desktop-notify', (event) => {
    const detail = Array.isArray(event.detail) ? event.detail[0] : event.detail;
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    const notification = new Notification(detail.title, { body: detail.body ?? '', tag: `education-hub-${detail.id}` });
    notification.onclick = () => {
        window.focus();
        if (detail.url) window.location = detail.url;
    };
});

// Character count as a person sees it (an emoji counts once). Matches WhatsAppFormatter::length().
window.countCharacters = (text) => {
    if (!text) return 0;
    if (window.Intl?.Segmenter) return [...new Intl.Segmenter().segment(text)].length;
    return [...text].length;
};

// Alpine is bundled with Livewire; register stores and components before it starts.
document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    // Sidebar: collapsed (desktop, remembered) and open (mobile drawer).
    Alpine.store('sidebar', {
        mobileOpen: false,

        toggle() {
            if (window.matchMedia('(min-width: 1024px)').matches) {
                const collapsed = document.documentElement.classList.toggle('sidebar-collapsed');
                try {
                    localStorage.setItem('sidebar-collapsed', collapsed ? '1' : '0');
                } catch (e) {}
            } else {
                this.mobileOpen = !this.mobileOpen;
            }
        },
    });

    // Toast stack (see components/ui/toasts.blade.php).
    Alpine.data('toasts', (initial = null) => ({
        items: [],
        nextId: 1,

        init() {
            if (initial) this.push(initial);
        },

        push(detail) {
            const toast = Array.isArray(detail) ? detail[0] : detail;
            const id = this.nextId++;
            this.items.push({ id, type: toast.type ?? 'info', message: toast.message ?? '', visible: true });
            setTimeout(() => this.dismiss(id), toast.type === 'error' ? 7000 : 4000);
        },

        dismiss(id) {
            const toast = this.items.find((t) => t.id === id);
            if (!toast) return;
            toast.visible = false;
            setTimeout(() => (this.items = this.items.filter((t) => t.id !== id)), 250);
        },
    }));

    // Message textarea with a WhatsApp formatting toolbar and live preview.
    // `text` is entangled with a Livewire property (deferred until the next request).
    Alpine.data('messageEditor', (text) => ({
        text,

        get length() {
            return window.countCharacters(this.text);
        },

        get preview() {
            return window.formatWhatsApp(this.text);
        },

        // Wrap the selection in a WhatsApp marker, e.g. *bold*. With no selection, insert the pair.
        wrap(marker) {
            const area = this.$refs.textarea;
            const { selectionStart: start, selectionEnd: end, value } = area;
            const selected = value.slice(start, end);
            this.text = value.slice(0, start) + marker + selected + marker + value.slice(end);
            this.$nextTick(() => {
                area.focus();
                area.setSelectionRange(start + marker.length, end + marker.length);
            });
        },

        insert(snippet) {
            const area = this.$refs.textarea;
            const { selectionStart: start, selectionEnd: end, value } = area;
            this.text = value.slice(0, start) + snippet + value.slice(end);
            this.$nextTick(() => {
                area.focus();
                area.setSelectionRange(start + snippet.length, start + snippet.length);
            });
        },
    }));

    // Dashboard "Today's Sending Activity" chart. ApexCharts is loaded on demand.
    Alpine.data('activityChart', (data) => ({
        chart: null,

        async init() {
            const { default: ApexCharts } = await import('apexcharts');

            this.chart = new ApexCharts(this.$refs.canvas, {
                chart: {
                    type: 'bar',
                    height: 280,
                    stacked: true,
                    fontFamily: 'Poppins, sans-serif',
                    toolbar: { show: false },
                    animations: { speed: 300 },
                },
                series: data.series,
                colors: ['#2563EB', '#EF4444'],
                xaxis: {
                    categories: data.labels,
                    axisBorder: { show: false },
                    axisTicks: { show: false },
                    labels: { style: { colors: '#64748B', fontSize: '11px' } },
                },
                yaxis: {
                    forceNiceScale: true,
                    min: 0,
                    labels: { style: { colors: '#64748B', fontSize: '11px' }, formatter: (v) => Math.round(v) },
                },
                plotOptions: { bar: { columnWidth: '55%', borderRadius: 4, borderRadiusApplication: 'end' } },
                grid: { borderColor: '#E2E8F0', strokeDashArray: 4 },
                dataLabels: { enabled: false },
                legend: { show: false },
                tooltip: { shared: true, intersect: false },
                states: { hover: { filter: { type: 'darken', value: 0.9 } } },
            });

            this.chart.render();
        },

        update(detail) {
            const payload = Array.isArray(detail) ? detail[0] : detail;
            this.chart?.updateOptions({ xaxis: { categories: payload.chart.labels }, series: payload.chart.series });
        },

        destroy() {
            this.chart?.destroy();
        },
    }));
});
