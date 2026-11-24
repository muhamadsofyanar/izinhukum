/**
 * Averion — App JS
 * Vanilla JS helpers, tidak ada jQuery/Alpine dependency
 */

// ─── Format mata uang (mengikuti setting global window.APP_CURRENCY) ──────────
// Fallback IDR bila config belum di-inject. Angka TIDAK dikonversi — hanya format.
window.fmtMoney = function (n) {
    var c = window.APP_CURRENCY || { symbol: 'Rp', decimals: 0, thousand: '.', decimal: ',' };
    var num = Number(n) || 0;
    var neg = num < 0;
    num = Math.abs(num);
    var fixed = num.toFixed(c.decimals);
    var parts = fixed.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, c.thousand);
    var out = parts.join(c.decimal);
    return c.symbol + ' ' + (neg ? '-' : '') + out;
};

// ─── Dark Mode ────────────────────────────────────────────────

function toggleDarkMode() {
    const html = document.documentElement;
    const isDark = html.classList.toggle('dark');
    const val = isDark ? '1' : '0';
    localStorage.setItem('averion_dark', val);
    // Cookie agar server render <html class="dark"> sejak awal → anti kedip.
    document.cookie = 'averion_dark=' + val + ';path=/;max-age=31536000;samesite=lax';
}

function initDarkMode() {
    const saved = localStorage.getItem('averion_dark');
    if (saved === '1') {
        document.documentElement.classList.add('dark');
    } else if (saved === '0') {
        document.documentElement.classList.remove('dark');
    }
    // Jika belum ada preferensi: ikuti class dari server (dari settings dark_mode_default)
}

// ─── Dropdown ─────────────────────────────────────────────────

function initDropdowns() {
    // Tutup semua dropdown saat klik di luar
    document.addEventListener('click', function (e) {
        document.querySelectorAll('[data-dropdown]').forEach(function (dropdown) {
            if (!dropdown.contains(e.target)) {
                const menu = dropdown.querySelector('[data-dropdown-menu]');
                if (menu) menu.classList.add('hidden');
            }
        });
    });

    // Toggle dropdown saat klik trigger
    document.querySelectorAll('[data-dropdown-trigger]').forEach(function (trigger) {
        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            const menu = trigger.closest('[data-dropdown]').querySelector('[data-dropdown-menu]');
            if (menu) menu.classList.toggle('hidden');
        });
    });
}

// ─── Sidebar Mobile ───────────────────────────────────────────

function initSidebarToggle() {
    const toggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (!toggle || !sidebar) return;

    function openSidebar() {
        sidebar.classList.remove('-translate-x-full');
        if (overlay) overlay.classList.remove('hidden');
        document.body.classList.add('overflow-hidden'); // cegah scroll body saat sidebar terbuka
    }
    function closeSidebar() {
        sidebar.classList.add('-translate-x-full');
        if (overlay) overlay.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }

    toggle.addEventListener('click', function () {
        if (sidebar.classList.contains('-translate-x-full')) openSidebar();
        else closeSidebar();
    });

    if (overlay) overlay.addEventListener('click', closeSidebar);

    // Tutup sidebar otomatis saat klik menu di mobile (sebelum navigasi).
    sidebar.querySelectorAll('a[href]').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth < 1024) closeSidebar();
        });
    });

    // Saat resize ke desktop: overlay & body-lock dibersihkan (sidebar tampil via lg:translate-x-0).
    window.addEventListener('resize', function () {
        if (window.innerWidth >= 1024) {
            if (overlay) overlay.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }
    });
}

// ─── Flash Messages ───────────────────────────────────────────

function initFlashMessages() {
    const flashes = document.querySelectorAll('[data-flash-message]');
    if (!flashes.length) return;

    // Auto-dismiss setelah 4 detik
    setTimeout(function () {
        flashes.forEach(function (el) {
            el.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            el.style.opacity = '0';
            el.style.transform = 'translateY(-8px)';
            setTimeout(function () { el.remove(); }, 300);
        });
    }, 4000);

    // Tombol close manual
    document.querySelectorAll('[data-flash-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const msg = btn.closest('[data-flash-message]');
            if (msg) {
                msg.style.transition = 'opacity 0.2s ease';
                msg.style.opacity = '0';
                setTimeout(function () { msg.remove(); }, 200);
            }
        });
    });
}

// ─── Custom Confirm Modal ─────────────────────────────────────

const _confirmVariants = {
    danger: {
        iconBg:  'bg-red-50 dark:bg-red-900/20',
        iconCls: 'text-red-600 dark:text-red-400',
        btnCls:  'bg-red-600 hover:bg-red-700',
        path:    'M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z',
    },
    warning: {
        iconBg:  'bg-yellow-50 dark:bg-yellow-900/20',
        iconCls: 'text-yellow-600 dark:text-yellow-400',
        btnCls:  'bg-yellow-500 hover:bg-yellow-600',
        path:    'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z',
    },
    info: {
        iconBg:  'bg-blue-50 dark:bg-blue-900/20',
        iconCls: 'text-blue-600 dark:text-blue-400',
        btnCls:  'bg-blue-600 hover:bg-blue-700',
        path:    'M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z',
    },
    success: {
        iconBg:  'bg-green-50 dark:bg-green-900/20',
        iconCls: 'text-green-600 dark:text-green-400',
        btnCls:  'bg-green-600 hover:bg-green-700',
        path:    'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    },
};

function showConfirmModal(options) {
    var opts = Object.assign({
        title:       'Konfirmasi',
        message:     'Apakah kamu yakin?',
        confirmText: 'Ya, Lanjutkan',
        cancelText:  'Batal',
        variant:     'danger',
    }, options);

    var modal      = document.getElementById('confirmModal');
    var backdrop   = document.getElementById('confirmModalBackdrop');
    var card       = document.getElementById('confirmModalCard');
    var titleEl    = document.getElementById('confirmModalTitle');
    var msgEl      = document.getElementById('confirmModalMessage');
    var confirmBtn = document.getElementById('confirmModalConfirmBtn');
    var cancelBtn  = document.getElementById('confirmModalCancelBtn');
    var iconWrap   = document.getElementById('confirmModalIconWrap');
    var iconEl     = document.getElementById('confirmModalIcon');

    if (!modal) {
        return Promise.resolve(window.confirm(opts.message));
    }

    var v = _confirmVariants[opts.variant] || _confirmVariants.danger;

    titleEl.textContent    = opts.title;
    msgEl.textContent      = opts.message;
    confirmBtn.textContent = opts.confirmText;
    cancelBtn.textContent  = opts.cancelText;

    iconWrap.className = 'w-8 h-8 shrink-0 rounded-lg flex items-center justify-center ' + v.iconBg;
    iconEl.className   = 'w-4 h-4 ' + v.iconCls;
    iconEl.querySelector('path').setAttribute('d', v.path);
    confirmBtn.className = 'px-3.5 py-1.5 text-[13px] font-semibold text-white rounded-lg transition-colors ' + v.btnCls;

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    requestAnimationFrame(function () {
        backdrop.classList.replace('opacity-0', 'opacity-100');
        card.classList.remove('scale-95', 'opacity-0');
        card.classList.add('scale-100', 'opacity-100');
    });

    return new Promise(function (resolve) {
        function cleanup(result) {
            backdrop.classList.replace('opacity-100', 'opacity-0');
            card.classList.add('scale-95', 'opacity-0');
            card.classList.remove('scale-100', 'opacity-100');
            setTimeout(function () {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }, 200);
            confirmBtn.removeEventListener('click', onYes);
            cancelBtn.removeEventListener('click', onNo);
            backdrop.removeEventListener('click', onNo);
            document.removeEventListener('keydown', onKey);
            resolve(result);
        }
        function onYes() { cleanup(true); }
        function onNo()  { cleanup(false); }
        function onKey(e) {
            if (e.key === 'Escape') { e.preventDefault(); cleanup(false); }
            if (e.key === 'Enter')  { e.preventDefault(); cleanup(true); }
        }
        confirmBtn.addEventListener('click', onYes);
        cancelBtn.addEventListener('click', onNo);
        backdrop.addEventListener('click', onNo);
        document.addEventListener('keydown', onKey);
    });
}

async function confirmAndSubmit(formId, options) {
    if (await showConfirmModal(options)) {
        document.getElementById(formId).submit();
    }
}

async function confirmAndRun(options, callback) {
    if (await showConfirmModal(options)) {
        if (typeof callback === 'function') callback();
    }
}

// ─── Toast ringan (pengganti alert() bawaan browser) ─────────
function showToast(message, type) {
    var colors = { success: 'bg-green-600', error: 'bg-red-600', info: 'bg-gray-900 dark:bg-gray-700' };
    var wrap = document.getElementById('appToastWrap');
    if (!wrap) {
        wrap = document.createElement('div');
        wrap.id = 'appToastWrap';
        wrap.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:2147483600;display:flex;flex-direction:column;gap:8px;align-items:flex-end;pointer-events:none;';
        document.body.appendChild(wrap);
    }
    var t = document.createElement('div');
    t.className = 'text-white text-[13px] font-medium px-3.5 py-2.5 rounded-lg shadow-lg ' + (colors[type] || colors.info);
    t.style.cssText = 'max-width:320px;opacity:0;transform:translateY(8px);transition:opacity .2s ease,transform .2s ease;';
    t.textContent = String(message);
    wrap.appendChild(t);
    requestAnimationFrame(function () { t.style.opacity = '1'; t.style.transform = 'translateY(0)'; });
    setTimeout(function () {
        t.style.opacity = '0'; t.style.transform = 'translateY(8px)';
        setTimeout(function () { t.remove(); if (wrap && !wrap.children.length) wrap.remove(); }, 250);
    }, 3200);
}

// ─── Format Rupiah (client-side) ─────────────────────────────

function formatRupiah(amount) {
    return 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.round(amount));
}

// ─── Copy to Clipboard ───────────────────────────────────────

function copyLink(btn, text) {
    copyToClipboard(text, btn, 'Tersalin ✓');
}

function copyToClipboard(text, btnEl, successText) {
    navigator.clipboard.writeText(text).then(function () {
        const original = btnEl.textContent;
        btnEl.textContent = successText || 'Tersalin ✓';
        btnEl.disabled = true;
        setTimeout(function () {
            btnEl.textContent = original;
            btnEl.disabled = false;
        }, 2000);
    }).catch(function () {
        // Fallback untuk browser lama
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    });
}

// ─── Auto-slug dari nama produk ───────────────────────────────

function slugify(text) {
    return text
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '');
}

// ─── Loading State pada tombol submit ────────────────────────

function initSubmitButtons() {
    // Atribut data-loading dipasang di TOMBOL submit (checkout, modal produk, dsb),
    // bukan di <form>. Selector lama `form[data-loading]` cuma cocok utk pola lama
    // (mis. broadcast.php) → tombol checkout TAK PERNAH ter-disable, pembeli bisa
    // klik dua kali = order dobel. Sekarang keduanya didukung.
    document.querySelectorAll('[data-loading]').forEach(function (el) {
        var isForm = el.tagName === 'FORM';
        var form   = isForm ? el : (el.form || el.closest('form'));
        var btn    = isForm ? el.querySelector('[type="submit"]') : el;
        if (!form || !btn) return;

        form.addEventListener('submit', function () {
            // Jalur gateway (pages/public/checkout.php) & beberapa halaman admin
            // mengurus tombolnya sendiri — termasuk mengaktifkannya lagi saat gagal.
            // Handler mereka terdaftar lebih dulu, jadi tombol sudah disabled di sini:
            // jangan timpa labelnya.
            if (btn.disabled) return;
            btn.disabled = true;
            // Dukung 3 pola label: data-loading-text="..", data-loading="..", polos.
            var loadingText = btn.getAttribute('data-loading-text')
                           || btn.getAttribute('data-loading')
                           || 'Memproses...';
            btn.setAttribute('data-original-html', btn.innerHTML);
            // Spinner (CSS .btn-spinner, currentColor) + teks — indikator visual.
            var sp = document.createElement('span');
            sp.className = 'btn-spinner';
            sp.setAttribute('aria-hidden', 'true');
            btn.textContent = '';
            btn.appendChild(sp);
            btn.appendChild(document.createTextNode(loadingText));
        });
    });
}

// ─── Accordion generik (tab WhatsApp & Payment di /admin/integrations) ─

function initAccordion(cfg) {
    var btns = document.querySelectorAll(cfg.container + ' .' + cfg.toggleClass);
    if (btns.length === 0) return;

    btns.forEach(function (btn) {
        var section  = btn.dataset[cfg.dataAttr];
        var content  = document.getElementById(cfg.contentPrefix + section);
        var chevron  = document.getElementById(cfg.chevronPrefix + section);
        if (!content || !chevron) return;

        var saved      = localStorage.getItem(cfg.storePrefix + section);
        var autoExpand = content.dataset.autoExpand === '1';
        // Auto-expand hanya jika ada flag eksplisit (mis. OTP/gateway aktif) & belum ada preferensi tersimpan.
        var shouldOpen = saved === 'open' || (saved === null && autoExpand);

        if (shouldOpen) {
            // Nilai besar karena panel mungkin masih hidden saat init (tab aktif lain),
            // sehingga scrollHeight = 0 tidak bisa diandalkan.
            content.style.maxHeight = '9999px';
            chevron.style.transform = 'rotate(180deg)';
        }

        btn.addEventListener('click', function () {
            var isOpen = content.style.maxHeight && content.style.maxHeight !== '0px';
            if (isOpen) {
                content.style.maxHeight = '0px';
                chevron.style.transform = 'rotate(0deg)';
                localStorage.setItem(cfg.storePrefix + section, 'closed');
            } else {
                // Panel pasti visible saat klik, scrollHeight sudah akurat.
                content.style.maxHeight = content.scrollHeight + 'px';
                chevron.style.transform = 'rotate(180deg)';
                localStorage.setItem(cfg.storePrefix + section, 'open');
            }
        });
    });
}

function initIntegrationAccordions() {
    initAccordion({
        container: '#whatsapp-accordion', toggleClass: 'wa-accordion-toggle',
        dataAttr: 'waSection', contentPrefix: 'wa-content-', chevronPrefix: 'wa-chevron-', storePrefix: 'wa-acc-'
    });
    initAccordion({
        container: '#payment-accordion', toggleClass: 'pay-accordion-toggle',
        dataAttr: 'paySection', contentPrefix: 'pay-content-', chevronPrefix: 'pay-chevron-', storePrefix: 'pay-acc-'
    });
}

// ─── Init Semua ───────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', function () {
    initDarkMode();
    initDropdowns();
    initSidebarToggle();
    initFlashMessages();
    initSubmitButtons();
    initIntegrationAccordions();
});

// ─── Soft Navigation: progress bar + prefetch on hover ────────
// Bikin perpindahan antar menu terasa halus tanpa ubah arsitektur MPA.
(function () {
    'use strict';
    var origin = location.origin;

    // Apakah <a> ini navigasi halaman normal (same-origin, GET, bukan endpoint
    // aksi/unduhan)? Mengembalikan URL bila layak, atau null.
    function navTarget(a) {
        if (!a || !a.getAttribute('href')) return null;
        if (a.target && a.target !== '_self') return null;
        if (a.hasAttribute('download') || a.dataset.noPrefetch !== undefined) return null;
        var url;
        try { url = new URL(a.href, location.href); } catch (e) { return null; }
        if (url.origin !== origin) return null;
        if (url.protocol !== 'http:' && url.protocol !== 'https:') return null;
        if (url.href === location.href) return null;
        if (url.pathname === location.pathname && url.hash) return null; // anchor di halaman sama
        var p = url.pathname.toLowerCase();
        // Lewati endpoint yang punya efek samping / unduhan / auth.
        if (p.indexOf('/actions/') !== -1 || p.indexOf('/auth/') !== -1 ||
            p.indexOf('/webhooks/') !== -1 || p.indexOf('export') !== -1 ||
            p.indexOf('logout') !== -1) return null;
        return url;
    }

    // ── Progress bar ──
    var bar, trickle, safety;
    function getBar() {
        bar = document.getElementById('nav-progress');
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'nav-progress';
            (document.body || document.documentElement).appendChild(bar);
        }
        return bar;
    }
    function startBar() {
        var b = getBar();
        b.classList.add('active');
        var w = 8; b.style.width = w + '%';
        clearInterval(trickle); clearTimeout(safety);
        trickle = setInterval(function () {
            w += (92 - w) * 0.10;
            b.style.width = w.toFixed(1) + '%';
            if (w >= 90) clearInterval(trickle);
        }, 160);
        safety = setTimeout(doneBar, 8000); // jaga-jaga bila navigasi batal
    }
    function doneBar() {
        clearInterval(trickle); clearTimeout(safety);
        if (!bar) return;
        bar.style.width = '100%';
        bar.classList.remove('active');
        setTimeout(function () { if (bar) bar.style.width = '0'; }, 280);
    }

    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var a = e.target.closest ? e.target.closest('a[href]') : null;
        if (a && navTarget(a)) startBar();
    });
    // Form non-AJAX (filter/simpan) yang benar-benar navigasi.
    document.addEventListener('submit', function (e) {
        if (e.defaultPrevented) return; // form AJAX sudah preventDefault → skip
        var f = e.target;
        if (f && f.tagName === 'FORM' && (!f.target || f.target === '_self')) startBar();
    });
    // Halaman baru tampil / kembali dari bfcache → reset bar.
    window.addEventListener('pageshow', doneBar);

    // ── Prefetch on hover (ala instant.page) ──
    var prefetched = {}, hoverTimer;
    function prefetch(url) {
        if (prefetched[url.href]) return;
        prefetched[url.href] = true;
        var l = document.createElement('link');
        l.rel = 'prefetch';
        l.href = url.href;
        document.head.appendChild(l);
    }
    document.addEventListener('mouseover', function (e) {
        var a = e.target.closest ? e.target.closest('a[href]') : null;
        if (!a) return;
        var url = navTarget(a);
        if (!url) return;
        clearTimeout(hoverTimer);
        hoverTimer = setTimeout(function () { prefetch(url); }, 65);
    });
    document.addEventListener('mouseout', function () { clearTimeout(hoverTimer); });
})();

// ─── PWA: tombol Install di topbar ───────────────────────────
// beforeinstallprompt ditangkap lebih awal di head.php (window.__pwaDeferred).
// Di sini: tampilkan/klik tombol #pwaInstallBtn. Android/desktop Chrome → prompt
// native; iOS/safari tanpa prompt → panduan Add to Home Screen via toast.
(function () {
    function btn() { return document.getElementById('pwaInstallBtn'); }
    function isStandalone() {
        return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
            || window.navigator.standalone === true; // iOS
    }
    function isIOS() {
        return /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;
    }

    window.pwaPromptInstall = function () {
        var d = window.__pwaDeferred;
        if (d) {
            d.prompt();
            if (d.userChoice && d.userChoice.then) {
                d.userChoice.then(function () {}).catch(function () {});
            }
            window.__pwaDeferred = null;
            var b = btn(); if (b) b.hidden = true;
            return;
        }
        // Tanpa prompt native (iOS, atau sudah pernah) → beri panduan.
        if (typeof showToast === 'function') {
            showToast(isIOS()
                ? 'Untuk memasang: ketuk tombol Bagikan di Safari, lalu pilih "Add to Home Screen".'
                : 'Buka menu browser (⋮), lalu pilih "Install" / "Add to Home screen".', 'info');
        }
    };

    window.addEventListener('appinstalled', function () {
        window.__pwaDeferred = null;
        var b = btn(); if (b) b.hidden = true;
        if (typeof showToast === 'function') showToast('Aplikasi berhasil dipasang.', 'success');
    });

    document.addEventListener('DOMContentLoaded', function () {
        var b = btn();
        if (!b) return;
        if (isStandalone()) { b.hidden = true; return; } // sudah jalan sebagai app
        // iOS tak memicu beforeinstallprompt → tetap tampilkan tombol (isinya panduan).
        if (isIOS()) b.hidden = false;
        // Android/desktop: tombol di-reveal oleh handler beforeinstallprompt (head.php).
    });
})();
