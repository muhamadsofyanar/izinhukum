/* Averion AI Chat Widget — Vanilla JS (tanpa library eksternal). */
(function () {
  'use strict';

  var widget = document.getElementById('averion-chat-widget');
  if (!widget) return;

  var AverionChat = {
    history: [],          // {role: 'user'|'model', content: string}
    isOpen: false,
    isTyping: false,
    welcomeShown: false,
    maxHistory: 10
  };

  // ─── Config dari data attributes ───────────────────────────────
  var cfg = {
    theme:    widget.dataset.theme || 'classic',
    botName:  widget.dataset.botName || 'Asisten',
    welcome:  widget.dataset.welcome || 'Halo! Ada yang bisa saya bantu?',
    waNumber: widget.dataset.waNumber || '',
    showWa:   widget.dataset.showWa === '1',
    endpoint: widget.dataset.endpoint || '/actions/chat/send',
    focusProduct: widget.dataset.focusProduct || '',
    quickReplies: []
  };
  try {
    var qr = JSON.parse(widget.dataset.quickReplies || '[]');
    if (Array.isArray(qr)) cfg.quickReplies = qr.slice(0, 5);
  } catch (e) { cfg.quickReplies = []; }

  // ─── Elemen ────────────────────────────────────────────────────
  var panel    = document.getElementById('averion-chat-panel');
  var trigger  = document.getElementById('averion-chat-trigger');
  var closeBtn = document.getElementById('averion-chat-close');
  var sendBtn  = document.getElementById('averion-chat-send');
  var input    = document.getElementById('averion-chat-input');
  var messages = document.getElementById('averion-chat-messages');
  var chipsEl  = document.getElementById('averion-chat-chips');
  var waEl     = document.getElementById('averion-chat-wa');
  var waLink   = document.getElementById('averion-chat-wa-link');

  // ─── Endpoint streaming (turunan dari endpoint send) + CSS kursor ──
  cfg.streamEndpoint = (cfg.endpoint || '/actions/chat/send').replace(/\/send$/, '/stream');
  cfg.leadEndpoint   = (cfg.endpoint || '/actions/chat/send').replace(/\/send$/, '/lead');
  if (!document.getElementById('averion-stream-style')) {
    var stStyle = document.createElement('style');
    stStyle.id = 'averion-stream-style';
    stStyle.textContent = '.averion-bubble-streaming::after{content:"▋";display:inline-block;animation:averion-blink .7s step-end infinite;color:#6b7280;font-size:.85em;margin-left:1px}@keyframes averion-blink{0%,100%{opacity:1}50%{opacity:0}}';
    document.head.appendChild(stStyle);
  }

  // ─── Fungsi ────────────────────────────────────────────────────
  function openPanel() {
    panel.classList.remove('hidden');
    // paksa reflow agar transition jalan
    void panel.offsetHeight;
    panel.classList.add('averion-chat-open');
    widget.classList.add('averion-chat-open');
    AverionChat.isOpen = true;
    if (!AverionChat.welcomeShown) {
      appendMessage('bot', cfg.welcome);
      AverionChat.welcomeShown = true;
    }
    if (input) { try { input.focus(); } catch (e) {} }
  }

  function closePanel() {
    panel.classList.remove('averion-chat-open');
    widget.classList.remove('averion-chat-open');
    AverionChat.isOpen = false;
    setTimeout(function () {
      if (!AverionChat.isOpen) panel.classList.add('hidden');
    }, 200);
  }

  function togglePanel() {
    if (AverionChat.isOpen) closePanel();
    else openPanel();
  }

  function appendMessage(role, content) {
    var bubble = document.createElement('div');
    if (role === 'user') {
      bubble.style.cssText = 'align-self:flex-end;background:var(--averion-chat-bg);color:var(--averion-chat-text);border-radius:14px 14px 2px 14px;padding:8px 12px;font-size:13px;max-width:80%;line-height:1.45;word-wrap:break-word;white-space:pre-wrap';
    } else {
      bubble.style.cssText = 'align-self:flex-start;background:#f3f4f6;color:#374151;border-radius:14px 14px 14px 2px;padding:8px 12px;font-size:13px;max-width:80%;line-height:1.45;word-wrap:break-word;white-space:pre-wrap';
    }
    bubble.textContent = content;   // JANGAN innerHTML — cegah XSS
    messages.appendChild(bubble);
    messages.scrollTop = messages.scrollHeight;
  }

  // ─── Bubble bot streaming (diisi chunk per chunk) ──────────────
  function createEmptyBotBubble() {
    var bubble = document.createElement('div');
    bubble.className = 'averion-bubble-streaming';
    bubble.style.cssText = 'align-self:flex-start;background:#f3f4f6;color:#374151;border-radius:14px 14px 14px 2px;padding:8px 12px;font-size:13px;max-width:80%;line-height:1.45;word-wrap:break-word;white-space:pre-wrap';
    messages.appendChild(bubble);
    messages.scrollTop = messages.scrollHeight;
    return bubble;
  }

  function appendTextToBubble(bubbleEl, text) {
    bubbleEl.textContent += text;   // JANGAN innerHTML — cegah XSS
  }

  // ─── CTA: ubah URL di jawaban bot jadi tombol/link yang aman ───
  // Dibangun murni via DOM node (createTextNode/createElement) — TANPA
  // innerHTML — sehingga tetap kebal XSS. URL same-origin (mis. link
  // checkout produk) dirender sebagai tombol "Lihat / Beli"; URL lain
  // sebagai link teks biasa.
  function decorateBotBubble(bubbleEl) {
    var text = bubbleEl.textContent || '';
    if (text.indexOf('http') === -1) return;
    var re = /https?:\/\/[^\s<>"')\]]+/g;
    if (!re.test(text)) return;
    re.lastIndex = 0;

    bubbleEl.textContent = '';
    var last = 0, m;
    while ((m = re.exec(text)) !== null) {
      var url = m[0].replace(/[.,;:!?]+$/, ''); // buang tanda baca ekor
      if (m.index > last) bubbleEl.appendChild(document.createTextNode(text.slice(last, m.index)));
      var a = document.createElement('a');
      a.href = url;
      a.rel  = 'noopener';
      var sameOrigin = url.indexOf(window.location.origin + '/') === 0 || url === window.location.origin;
      if (sameOrigin) {
        a.textContent = url.indexOf('checkout') !== -1 ? 'Beli Sekarang' : 'Buka Halaman';
        a.style.cssText = 'display:inline-block;margin:4px 0;padding:6px 14px;border-radius:8px;background:var(--averion-chat-bg);color:var(--averion-chat-text);font-size:12px;font-weight:600;text-decoration:none';
      } else {
        a.href = url; a.target = '_blank';
        a.textContent = url;
        a.style.cssText = 'color:inherit;text-decoration:underline;word-break:break-all';
      }
      bubbleEl.appendChild(a);
      last = m.index + m[0].length;
    }
    if (last < text.length) bubbleEl.appendChild(document.createTextNode(text.slice(last)));
  }

  function scrollMessagesToBottom() {
    messages.scrollTop = messages.scrollHeight;
  }

  // ─── Lead capture: form nama + WA setelah pesan ke-2 ───────────
  // Sekali saja: skip atau sukses disimpan di localStorage (tak muncul lagi).
  var leadFormShown = false;
  function maybeShowLeadForm() {
    if (leadFormShown) return;
    if (localStorage.getItem('averion_chat_lead') || localStorage.getItem('averion_chat_lead_token')) return;
    var userMsgs = AverionChat.history.filter(function (h) { return h.role === 'user'; }).length;
    if (userMsgs < 2) return;
    leadFormShown = true;

    var box = document.createElement('div');
    box.style.cssText = 'align-self:flex-start;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:12px;font-size:13px;max-width:88%;box-shadow:0 1px 3px rgba(0,0,0,.06)';

    var title = document.createElement('p');
    title.textContent = 'Biar kami bisa bantu lebih lanjut, boleh tinggalkan nama & WhatsApp?';
    title.style.cssText = 'margin:0 0 8px;color:#374151;line-height:1.4';
    box.appendChild(title);

    var inputCss = 'width:100%;box-sizing:border-box;border:1px solid #e5e7eb;border-radius:8px;padding:7px 10px;font-size:13px;margin-bottom:6px;outline:none;color:#111827;background:#fff';
    var nameIn = document.createElement('input');
    nameIn.type = 'text'; nameIn.placeholder = 'Nama kamu'; nameIn.maxLength = 120; nameIn.style.cssText = inputCss;
    var waIn = document.createElement('input');
    waIn.type = 'tel'; waIn.placeholder = 'No. WhatsApp (08xxx)'; waIn.maxLength = 20; waIn.style.cssText = inputCss;
    box.appendChild(nameIn); box.appendChild(waIn);

    var err = document.createElement('p');
    err.style.cssText = 'display:none;margin:0 0 6px;color:#dc2626;font-size:12px';
    box.appendChild(err);

    var row = document.createElement('div');
    row.style.cssText = 'display:flex;gap:8px;align-items:center';
    var submit = document.createElement('button');
    submit.type = 'button'; submit.textContent = 'Kirim';
    submit.style.cssText = 'flex:1;border:none;border-radius:8px;padding:8px 0;font-size:13px;font-weight:600;cursor:pointer;background:var(--averion-chat-bg);color:var(--averion-chat-text)';
    var skip = document.createElement('button');
    skip.type = 'button'; skip.textContent = 'Nanti saja';
    skip.style.cssText = 'border:none;background:none;color:#9ca3af;font-size:12px;cursor:pointer;padding:8px 6px';
    row.appendChild(submit); row.appendChild(skip);
    box.appendChild(row);

    skip.addEventListener('click', function () {
      localStorage.setItem('averion_chat_lead', 'skip');
      box.remove();
    });

    submit.addEventListener('click', function () {
      var nm = nameIn.value.trim(), wa = waIn.value.trim();
      if (nm.length < 2) { err.textContent = 'Isi nama dulu ya.'; err.style.display = 'block'; return; }
      if (!/^[0-9+\s-]{9,20}$/.test(wa)) { err.textContent = 'Nomor WhatsApp belum benar.'; err.style.display = 'block'; return; }
      err.style.display = 'none';
      submit.disabled = true; submit.textContent = 'Mengirim...';

      fetch(cfg.leadEndpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          name: nm, whatsapp: wa,
          history: JSON.stringify(AverionChat.history.slice(-30))
        })
      })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
        .then(function (res) {
          if (res.ok && res.d && res.d.success && res.d.token) {
            localStorage.setItem('averion_chat_lead_token', res.d.token);
            localStorage.setItem('averion_chat_lead', 'done');
            box.remove();
            appendMessage('bot', 'Terima kasih, ' + nm + '! Kalau butuh bantuan lanjutan, tim kami bisa menghubungi kamu via WhatsApp.');
          } else {
            err.textContent = (res.d && res.d.error) || 'Gagal mengirim. Coba lagi.';
            err.style.display = 'block';
            submit.disabled = false; submit.textContent = 'Kirim';
          }
        })
        .catch(function () {
          err.textContent = 'Gagal terhubung. Coba lagi.';
          err.style.display = 'block';
          submit.disabled = false; submit.textContent = 'Kirim';
        });
    });

    messages.appendChild(box);
    messages.scrollTop = messages.scrollHeight;
  }

  function showTypingIndicator() {
    var wrap = document.createElement('div');
    wrap.id = 'averion-typing';
    wrap.style.cssText = 'align-self:flex-start;background:#f3f4f6;border-radius:14px 14px 14px 2px;padding:10px 14px;display:flex;gap:4px;align-items:center';
    for (var i = 0; i < 3; i++) {
      var dot = document.createElement('span');
      dot.className = 'averion-typing-dot';
      dot.style.animationDelay = (i * 160) + 'ms';
      wrap.appendChild(dot);
    }
    messages.appendChild(wrap);
    messages.scrollTop = messages.scrollHeight;
  }

  function hideTypingIndicator() {
    var t = document.getElementById('averion-typing');
    if (t) t.remove();
  }

  function autoResizeTextarea() {
    if (!input) return;
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 80) + 'px';
  }

  function showWaFallback() {
    if (!waEl || !waLink || !cfg.waNumber) return;
    waLink.href = 'https://wa.me/' + cfg.waNumber + '?text=' + encodeURIComponent('Halo, saya butuh bantuan lebih lanjut.');
    waEl.style.display = 'block';
    messages.scrollTop = messages.scrollHeight;
  }

  function sendMessage(overrideText) {
    var text = (overrideText != null ? overrideText : (input ? input.value : '')).trim();
    if (!text || AverionChat.isTyping) return;

    if (chipsEl) chipsEl.style.display = 'none';

    appendMessage('user', text);
    AverionChat.history.push({ role: 'user', content: text });
    if (input) { input.value = ''; autoResizeTextarea(); }

    AverionChat.isTyping = true;
    if (sendBtn) sendBtn.disabled = true;
    showTypingIndicator();

    var botBubble = null;     // dibuat saat chunk pertama datang
    var fullReply = '';
    var finished  = false;

    function ensureBubble() {
      if (!botBubble) { hideTypingIndicator(); botBubble = createEmptyBotBubble(); }
      return botBubble;
    }
    function finalize() {
      if (finished) return;
      finished = true;
      AverionChat.isTyping = false;
      if (sendBtn) sendBtn.disabled = false;
      if (botBubble) {
        botBubble.classList.remove('averion-bubble-streaming'); // kursor hilang
        decorateBotBubble(botBubble); // URL → tombol CTA (aman, DOM node)
      }
      maybeShowLeadForm(); // form nama+WA setelah pesan ke-2 (sekali)
    }
    function pushHistory() {
      AverionChat.history.push({ role: 'model', content: fullReply });
      if (AverionChat.history.length > AverionChat.maxHistory * 2) {
        AverionChat.history = AverionChat.history.slice(-AverionChat.maxHistory);
      }
    }

    fetch(cfg.streamEndpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        message: text,
        history: JSON.stringify(AverionChat.history.slice(-AverionChat.maxHistory)),
        lead_token: localStorage.getItem('averion_chat_lead_token') || '',
        focus_product_id: cfg.focusProduct || ''
      })
    })
      .then(function (response) {
        if (!response.ok || !response.body || !response.body.getReader) throw new Error('stream unsupported');

        var reader  = response.body.getReader();
        var decoder = new TextDecoder();
        var buffer  = '';

        function handleEvent(ev, dataStr) {
          var d;
          try { d = JSON.parse(dataStr); } catch (e) { return; }
          if (ev === 'chunk' && typeof d.text === 'string') {
            fullReply += d.text;
            appendTextToBubble(ensureBubble(), d.text);
            scrollMessagesToBottom();
          } else if (ev === 'done') {
            ensureBubble();
            if (!fullReply && typeof d.full_reply === 'string' && d.full_reply) {
              fullReply = d.full_reply;
              appendTextToBubble(botBubble, d.full_reply);
            }
            pushHistory();
            finalize();
            if (d.show_wa) showWaFallback();
          } else if (ev === 'error') {
            hideTypingIndicator();
            var msg = (d && d.message) ? d.message : 'Maaf, terjadi kesalahan. Coba lagi ya.';
            if (fullReply) { appendTextToBubble(ensureBubble(), '\n\n' + msg); }
            else { ensureBubble().textContent = msg; }
            finalize();
          }
        }

        function processBuffer() {
          var parts = buffer.split('\n\n');
          buffer = parts.pop(); // sisakan blok terakhir yang mungkin belum lengkap
          parts.forEach(function (block) {
            var ev = 'message', dataStr = '';
            block.split('\n').forEach(function (line) {
              line = line.replace(/\r$/, '');
              if (line.indexOf('event:') === 0) ev = line.slice(6).trim();
              else if (line.indexOf('data:') === 0) dataStr += line.slice(5).trim();
            });
            if (dataStr) handleEvent(ev, dataStr);
          });
        }

        function pump() {
          return reader.read().then(function (res) {
            if (res.done) {
              if (buffer.indexOf('\n\n') !== -1) processBuffer();
              if (!finished) finalize();
              return;
            }
            buffer += decoder.decode(res.value, { stream: true });
            processBuffer();
            return pump();
          });
        }
        return pump();
      })
      .catch(function () {
        hideTypingIndicator();
        if (!fullReply) {
          ensureBubble().textContent = 'Koneksi terputus. Periksa internet kamu ya.';
        }
        finalize();
      });
  }

  // ─── Render quick reply chips (elegant) ────────────────────────
  if (chipsEl && cfg.quickReplies.length) {
    cfg.quickReplies.forEach(function (label) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'averion-chip';
      btn.textContent = label;
      btn.setAttribute('data-chip', label);
      btn.style.cssText = 'background:var(--averion-chat-light);border:1px solid var(--averion-chat-lighter);color:var(--averion-chat-color);border-radius:20px;font-size:12px;padding:5px 12px;cursor:pointer;font-family:inherit';
      chipsEl.appendChild(btn);
    });
    chipsEl.addEventListener('click', function (e) {
      var t = e.target.closest('[data-chip]');
      if (t) sendMessage(t.getAttribute('data-chip'));
    });
  }

  // ─── Event listeners ───────────────────────────────────────────
  if (trigger)  trigger.addEventListener('click', togglePanel);
  if (closeBtn) closeBtn.addEventListener('click', closePanel);
  if (sendBtn)  sendBtn.addEventListener('click', function () { sendMessage(); });
  if (input) {
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    });
    input.addEventListener('input', autoResizeTextarea);
  }

  // Klik di luar widget → tutup
  document.addEventListener('click', function (e) {
    if (AverionChat.isOpen && !widget.contains(e.target)) closePanel();
  });
})();
