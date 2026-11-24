<?php
// ════════════════════════════════════════════════════════════════
// Audio Interaksi Form Checkout (Social Proof).
// Memutar suara (sp_sound_type) saat pengunjung fokus ke field isian di
// form checkout — berlaku di checkout publik maupun login. Reuse engine
// window.spPlaySound dari partials/sp-sound-engine.php.
// Aktif hanya bila sp_checkout_interaction_enabled = '1'.
// ════════════════════════════════════════════════════════════════
if (!function_exists('getSetting') || getSetting('sp_checkout_interaction_enabled', '0') !== '1') {
    return;
}
require __DIR__ . '/sp-sound-engine.php'; // emit window.spPlaySound (idempotent)
$spCkSound = (string) getSetting('sp_sound_type', 'crystal_bell');
?>
<script>
(function () {
  var SND = <?= json_encode($spCkSound) ?>;
  var last = 0;
  function play() {
    var now = Date.now();
    if (now - last < 300) return;            // throttle anti rapid-fire
    last = now;
    if (window.spPlaySound) window.spPlaySound(SND);
  }
  function bind() {
    var sel = 'input[type=text],input[type=email],input[type=tel],input[type=number],'
            + 'input[type=search],input[type=password],input:not([type]),textarea,select';
    document.querySelectorAll(sel).forEach(function (el) {
      if (el.closest('nav,aside,[data-sp-no-sound]')) return; // jangan di sidebar/nav
      if (el.dataset.spBound) return;
      el.dataset.spBound = '1';
      el.addEventListener('focus', play);
    });
  }
  if (document.readyState !== 'loading') bind();
  else document.addEventListener('DOMContentLoaded', bind);
})();
</script>
