<?php
// ─── Toggle Dark/Light (reusable) ─────────────────────────────────────────
// Toggle hari/malam kompak bergaya, warna langit mengikuti brand_color (white-label).
// Dipakai di topbar dashboard & halaman frontend publik. Logika tetap toggleDarkMode()
// (assets/js/app.js) — di sini HANYA tampilan. State siang/malam digerakkan oleh class
// `html.dark` via CSS, jadi benar sejak render server (anti-kedip), tanpa butuh JS.
// CSS dicetak sekali per request walau partial di-include berkali-kali.
if (!defined('AV_DTOGGLE_CSS')):
    define('AV_DTOGGLE_CSS', 1);
?>
<style>
  .av-dtoggle{all:unset;display:inline-block;cursor:pointer;line-height:0;border-radius:999px;-webkit-tap-highlight-color:transparent;}
  .av-dtoggle:focus-visible{outline:2px solid var(--brand-color);outline-offset:2px;}
  .av-dt-sky{position:relative;display:block;width:52px;height:26px;border-radius:999px;overflow:hidden;
    background:linear-gradient(135deg,var(--brand-color),color-mix(in srgb,var(--brand-color) 45%,#ffffff));
    box-shadow:inset 0 1px 3px rgba(0,0,0,.28);transition:background .35s ease;}
  .dark .av-dt-sky{background:linear-gradient(135deg,#283449,#0b1220);}
  /* Matahari / bulan */
  .av-dt-knob{position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;
    background:radial-gradient(circle at 35% 32%,#fffdf0,#ffd873);
    box-shadow:0 1px 3px rgba(0,0,0,.35), 0 0 6px rgba(255,214,102,.45);
    transition:transform .35s cubic-bezier(.4,0,.2,1),background .35s ease,box-shadow .35s ease;}
  .dark .av-dt-knob{transform:translateX(26px);background:radial-gradient(circle at 35% 32%,#f4f5f7,#c3c9d0);
    box-shadow:0 1px 3px rgba(0,0,0,.45);}
  /* Kawah bulan — hanya tampak saat gelap */
  .av-dt-knob::before,.av-dt-knob::after{content:"";position:absolute;border-radius:50%;
    background:rgba(120,130,142,.5);opacity:0;transition:opacity .35s ease;}
  .av-dt-knob::before{width:5px;height:5px;top:4px;left:10px;}
  .av-dt-knob::after{width:3px;height:3px;top:11px;left:5px;}
  .dark .av-dt-knob::before,.dark .av-dt-knob::after{opacity:1;}
  /* Awan (siang) di sisi kanan */
  .av-dt-clouds{position:absolute;right:5px;bottom:4px;width:22px;height:9px;opacity:1;transition:opacity .3s ease;}
  .av-dt-clouds::before,.av-dt-clouds::after{content:"";position:absolute;background:rgba(255,255,255,.9);border-radius:999px;}
  .av-dt-clouds::before{width:16px;height:6px;right:0;bottom:0;}
  .av-dt-clouds::after{width:9px;height:9px;right:8px;bottom:2px;}
  .dark .av-dt-clouds{opacity:0;}
  /* Bintang (malam) di sisi kiri */
  .av-dt-stars{position:absolute;left:9px;top:7px;width:1.6px;height:1.6px;border-radius:50%;background:#fff;opacity:0;transition:opacity .3s ease;
    box-shadow:5px 4px 0 0 #fff, 10px -2px 0 0 rgba(255,255,255,.95), 2px 9px 0 0 rgba(255,255,255,.8), 13px 8px 0 0 rgba(255,255,255,.7);}
  .dark .av-dt-stars{opacity:1;}
  @media (prefers-reduced-motion: reduce){.av-dt-knob,.av-dt-sky,.av-dt-clouds,.av-dt-stars,.av-dt-knob::before,.av-dt-knob::after{transition:none;}}
</style>
<?php endif; ?>
<button type="button" class="av-dtoggle" role="switch"
        aria-label="Ganti mode gelap/terang" title="Mode gelap / terang"
        onclick="toggleDarkMode()">
  <span class="av-dt-sky">
    <span class="av-dt-stars"></span>
    <span class="av-dt-clouds"></span>
    <span class="av-dt-knob"></span>
  </span>
</button>
