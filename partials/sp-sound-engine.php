<?php
/**
 * Averion — Social Proof Sound Engine (partial)
 * ----------------------------------------------------------------------------
 * Mendefinisikan window.spPlaySound(type) — 8 suara premium ter-sintesis via
 * Web Audio API (tanpa file audio). Dipakai oleh:
 *   - pages/admin/social-proof.php  (preview suara)
 *   - pages/admin/transactions.php  (notifikasi order baru)
 *
 * Catatan: social-proof.js (untuk halaman publik & embed eksternal) memuat
 * salinan engine yang sama secara inline karena tidak bisa meng-include PHP.
 * Jaga agar daftar suara di kedua tempat tetap sinkron.
 *
 * Idempotent: aman di-include lebih dari sekali pada satu halaman.
 */
?>
<script>
if (!window.spPlaySound) {
(function () {
  'use strict';

  // Satu tone sederhana dengan envelope attack/decay.
  function tone(ctx, start, freq, dur, type, vol, attack) {
    var osc = ctx.createOscillator();
    var gain = ctx.createGain();
    osc.type = type || 'sine';
    osc.frequency.setValueAtTime(freq, start);
    gain.gain.setValueAtTime(0.0001, start);
    gain.gain.exponentialRampToValueAtTime(vol, start + (attack || 0.005));
    gain.gain.exponentialRampToValueAtTime(0.0001, start + dur);
    osc.connect(gain).connect(ctx.destination);
    osc.start(start);
    osc.stop(start + dur + 0.02);
  }

  // Tone dengan sapuan frekuensi (untuk water_drop).
  function sweep(ctx, start, fromFreq, toFreq, dur, vol) {
    var osc = ctx.createOscillator();
    var gain = ctx.createGain();
    osc.type = 'sine';
    osc.frequency.setValueAtTime(fromFreq, start);
    osc.frequency.exponentialRampToValueAtTime(toFreq, start + dur);
    gain.gain.setValueAtTime(vol, start);
    gain.gain.exponentialRampToValueAtTime(0.0001, start + dur);
    osc.connect(gain).connect(ctx.destination);
    osc.start(start);
    osc.stop(start + dur + 0.02);
  }

  var V = 0.18; // volume default — sengaja lembut

  var sounds = {
    crystal_bell: function (ctx, t) {
      tone(ctx, t, 1046.5, 0.45, 'sine', V, 0.003);
      tone(ctx, t, 1318.5, 0.5, 'sine', V * 0.8, 0.003);
    },
    cash_register: function (ctx, t) {
      tone(ctx, t, 880, 0.12, 'triangle', V, 0.002);
      tone(ctx, t + 0.1, 1760, 0.14, 'triangle', V * 0.9, 0.002);
    },
    soft_chime: function (ctx, t) {
      tone(ctx, t, 523.25, 0.5, 'sine', V * 0.9, 0.02);
      tone(ctx, t + 0.12, 659.25, 0.5, 'sine', V * 0.9, 0.02);
      tone(ctx, t + 0.24, 783.99, 0.6, 'sine', V * 0.9, 0.02);
    },
    water_drop: function (ctx, t) {
      sweep(ctx, t, 800, 200, 0.25, V);
    },
    notification_pop: function (ctx, t) {
      tone(ctx, t, 600, 0.2, 'square', V * 0.7, 0.002);
    },
    gentle_ding: function (ctx, t) {
      tone(ctx, t, 880, 0.8, 'sine', V, 0.005);
    },
    double_tap: function (ctx, t) {
      tone(ctx, t, 440, 0.1, 'sine', V, 0.002);
      tone(ctx, t + 0.08, 550, 0.1, 'sine', V, 0.002);
    },
    success_chime: function (ctx, t) {
      tone(ctx, t, 659.25, 0.4, 'sine', V * 0.9, 0.01);
      tone(ctx, t + 0.06, 783.99, 0.4, 'sine', V * 0.9, 0.01);
      tone(ctx, t + 0.12, 987.77, 0.5, 'sine', V * 0.9, 0.01);
    }
  };

  window.spPlaySound = function (type) {
    try {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return;
      var ctx = new AC();
      var fn = sounds[type] || sounds.crystal_bell;
      fn(ctx, ctx.currentTime);
      setTimeout(function () { try { ctx.close(); } catch (e) {} }, 1500);
    } catch (e) {}
  };
})();
}
</script>
