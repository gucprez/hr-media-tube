import Alpine from 'alpinejs';

window.Alpine = Alpine;

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;

/**
 * Reduce la foto en el navegador (máx. 1600 px, JPEG 82 %) antes de subirla: ahorra datos móviles
 * y evita fallos por el límite de subida. El servidor vuelve a validar y re-codificar.
 */
async function compressImage(file, maxSide = 1600, quality = 0.82) {
    if (!file.type.startsWith('image/') || file.type === 'image/gif') return file;
    try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise((res) => canvas.toBlob(res, 'image/jpeg', quality));
        if (!blob || blob.size >= file.size) return file;
        return new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' });
    } catch {
        return file;
    }
}

/** Selector de fotos/documentos: cámara del celular, galería o archivo; con vista previa y quitar. */
Alpine.data('photoPicker', ({ multiple = true, max = 10, accept = 'image/*' } = {}) => ({
    files: [],
    previews: [],
    busy: false,
    max,
    multiple,
    accept,
    async add(event) {
        this.busy = true;
        for (const file of Array.from(event.target.files)) {
            if (this.files.length >= this.max) break;
            const f = await compressImage(file);
            this.files.push(f);
            this.previews.push(f.type.startsWith('image/') ? URL.createObjectURL(f) : null);
            if (!this.multiple) break;
        }
        event.target.value = '';
        this.sync();
        this.busy = false;
    },
    remove(i) {
        if (this.previews[i]) URL.revokeObjectURL(this.previews[i]);
        this.files.splice(i, 1);
        this.previews.splice(i, 1);
        this.sync();
    },
    sync() {
        const dt = new DataTransfer();
        this.files.forEach((f) => dt.items.add(f));
        this.$refs.real.files = dt.files;
    },
    size(f) {
        return f.size > 1048576 ? (f.size / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(f.size / 1024)) + ' KB';
    },
}));

/**
 * Líneas de un documento de inventario (entrada / salida / ajuste).
 * Toda la lógica de cajas↔unidades vive en el servidor: aquí solo se pide la vista previa.
 */
Alpine.data('docLines', ({ mode, urls, warehouse, initial = [] }) => ({
    mode,
    warehouse,
    lines: initial.map((l) => ({ ...l, q: '', open: false, results: [], preview: null, key: Math.random() })),
    timers: {},
    init() {
        if (!this.lines.length) this.add();
        this.lines.forEach((_, i) => this.refresh(i));
    },
    blank() {
        return { key: Math.random(), product: null, boxes: '', units: '', q: '', open: false, results: [], preview: null };
    },
    add() {
        this.lines.push(this.blank());
        this.$nextTick(() => document.querySelector(`[data-line="${this.lines.length - 1}"] input[type=search]`)?.focus());
    },
    remove(i) {
        this.lines.splice(i, 1);
        if (!this.lines.length) this.add();
    },
    async search(i) {
        const line = this.lines[i];
        clearTimeout(this.timers['s' + i]);
        this.timers['s' + i] = setTimeout(async () => {
            const url = new URL(urls.lookup, location.origin);
            url.searchParams.set('q', line.q);
            url.searchParams.set('warehouse_id', this.warehouse);
            url.searchParams.set('type', this.mode);
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            line.results = res.ok ? await res.json() : [];
            line.open = true;
        }, 220);
    },
    pick(i, product) {
        const line = this.lines[i];
        line.product = product;
        line.open = false;
        line.q = '';
        line.boxes = '';
        line.units = '';
        this.refresh(i);
        this.$nextTick(() => document.querySelector(`[data-line="${i}"] input[data-qty]`)?.focus());
    },
    refresh(i) {
        const line = this.lines[i];
        if (!line || !line.product) return;
        if (this.mode === 'adjustment' && line.boxes === '' && line.units === '') {
            line.preview = null;
            return;
        }
        clearTimeout(this.timers['p' + i]);
        this.timers['p' + i] = setTimeout(async () => {
            const res = await fetch(urls.preview, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
                body: JSON.stringify({
                    type: this.mode,
                    warehouse_id: this.warehouse,
                    lines: [{ product_id: line.product.id, boxes: line.boxes, units: line.units }],
                }),
            });
            if (res.ok) line.preview = (await res.json())[0] ?? null;
        }, 250);
    },
    refreshAll() {
        this.lines.forEach((_, i) => this.refresh(i));
    },
    showBoxes(line) {
        return line.product && line.product.mode !== 'units';
    },
    showUnits(line) {
        return line.product && line.product.mode !== 'boxes';
    },
}));

/** Búsqueda global con resultados en vivo (agrupados por módulo). */
Alpine.data('globalSearch', (url) => ({
    q: '',
    open: false,
    results: [],
    async run() {
        if (this.q.trim().length < 2) {
            this.results = [];
            this.open = false;
            return;
        }
        const res = await fetch(`${url}?q=${encodeURIComponent(this.q)}`, { headers: { Accept: 'application/json' } });
        this.results = res.ok ? await res.json() : [];
        this.open = this.results.length > 0;
    },
}));

Alpine.start();
