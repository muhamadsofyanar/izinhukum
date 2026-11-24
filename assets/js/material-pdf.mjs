const viewer = document.querySelector('[data-material-pdf]');

if (viewer) {
    showPdf(viewer).catch(() => {
        viewer.querySelector('[data-pdf-status]').textContent = 'PDF tidak bisa ditampilkan di sini. Buka PDF di tab baru.';
    });
}

async function showPdf(viewer) {
    const canvas = viewer.querySelector('canvas');
    const frame = canvas.parentElement;
    const controls = viewer.querySelector('[data-pdf-controls]');
    const previous = controls.querySelector('[data-pdf-prev]');
    const next = controls.querySelector('[data-pdf-next]');
    const status = viewer.querySelector('[data-pdf-status]');
    const jump = controls.querySelector('[data-pdf-jump]');
    const numberField = jump.querySelector('input');
    const fullscreen = controls.querySelector('[data-pdf-fullscreen]');
    const container = viewer.parentElement;
    const dialog = container.querySelector('[data-pdf-dialog]');

    status.textContent = 'Memuat PDF…';

    const assets = new URL('../vendor/pdfjs-6.5.438/', import.meta.url);
    const { getDocument, GlobalWorkerOptions } = await import(new URL('pdf.min.mjs', assets).href);
    GlobalWorkerOptions.workerSrc = new URL('pdf.worker.min.mjs', assets).href;
    const pdf = await getDocument({
        url: viewer.dataset.materialPdf,
        cMapUrl: new URL('cmaps/', assets).href,
        standardFontDataUrl: new URL('standard_fonts/', assets).href,
        iccUrl: new URL('iccs/', assets).href,
        wasmUrl: new URL('wasm/', assets).href,
        isEvalSupported: false,
    }).promise;

    let pageNumber = 1;
    let renderTask;
    let renderId = 0;
    let previousWidth = 0;
    let previousHeight = 0;

    async function renderPage() {
        const width = frame.clientWidth;
        const heightLimit = dialog.open ? frame.clientHeight : Infinity;
        if (!width || !heightLimit) return;

        const id = ++renderId;
        const number = pageNumber;
        previous.disabled = number === 1;
        next.disabled = number === pdf.numPages;
        if (document.activeElement !== numberField) numberField.value = number;

        if (renderTask) {
            const active = renderTask;
            active.cancel();
            // The next task must wait until PDF.js releases this canvas.
            await active.promise.catch(() => {});
        }

        try {
            const page = await pdf.getPage(number);
            if (id !== renderId) return;

            const natural = page.getViewport({ scale: 1 });
            const scale = Math.min(width / natural.width, heightLimit / natural.height);
            const density = Math.min(
                window.devicePixelRatio || 1,
                2,
                4096 / (Math.max(natural.width, natural.height) * scale),
            );
            const viewport = page.getViewport({ scale: scale * density });
            canvas.width = Math.max(1, Math.floor(viewport.width));
            canvas.height = Math.max(1, Math.floor(viewport.height));
            canvas.style.width = `${Math.min(width, natural.width * scale)}px`;

            renderTask = page.render({ canvas, viewport });
            await renderTask.promise;
            if (id !== renderId) return;

            canvas.hidden = false;
            status.classList.add('sr-only');
            const pageLabel = `Halaman ${number} dari ${pdf.numPages}`;
            if (status.textContent !== pageLabel) status.textContent = pageLabel;
            canvas.setAttribute('aria-label', pageLabel);
        } catch (error) {
            if (id !== renderId || error.name === 'RenderingCancelledException') return;

            canvas.hidden = true;
            status.classList.remove('sr-only');
            status.textContent = 'Halaman ini tidak bisa ditampilkan. Buka PDF di tab baru.';
        } finally {
            if (id === renderId) renderTask = undefined;
        }
    }

    previous.addEventListener('click', () => {
        if (pageNumber > 1) {
            pageNumber--;
            renderPage();
        }
    });
    next.addEventListener('click', () => {
        if (pageNumber < pdf.numPages) {
            pageNumber++;
            renderPage();
        }
    });
    numberField.max = pdf.numPages;
    numberField.value = pageNumber;
    controls.querySelector('[data-pdf-total]').textContent = pdf.numPages;
    function jumpToPage() {
        const number = numberField.valueAsNumber;
        if (Number.isInteger(number) && number >= 1 && number <= pdf.numPages && number !== pageNumber) {
            pageNumber = number;
            renderPage();
        }
    }
    jump.addEventListener('submit', (event) => {
        event.preventDefault();
        jumpToPage();
    });
    numberField.addEventListener('change', jumpToPage);
    numberField.addEventListener('blur', () => { numberField.value = pageNumber; });

    if (typeof dialog.showModal === 'function') {
        const fullscreenIcon = fullscreen.firstElementChild;
        fullscreen.hidden = false;
        fullscreen.addEventListener('click', () => {
            if (dialog.open) {
                dialog.close();
                return;
            }
            dialog.showModal();
            dialog.append(viewer);
            fullscreenIcon.textContent = '×';
            fullscreen.setAttribute('aria-label', 'Keluar layar penuh');
            fullscreen.title = 'Keluar layar penuh';
            fullscreen.focus({ preventScroll: true });
            renderPage();
        });
        dialog.addEventListener('close', () => {
            container.insertBefore(viewer, dialog);
            fullscreenIcon.textContent = '⛶';
            fullscreen.setAttribute('aria-label', 'Layar penuh');
            fullscreen.title = 'Layar penuh';
            fullscreen.focus({ preventScroll: true });
            renderPage();
        });
    }
    controls.hidden = false;

    new ResizeObserver(() => {
        const width = frame.clientWidth;
        const height = dialog.open ? frame.clientHeight : 0;
        if (width === previousWidth && height === previousHeight) return;
        previousWidth = width;
        previousHeight = height;
        renderPage();
    }).observe(frame);
}
