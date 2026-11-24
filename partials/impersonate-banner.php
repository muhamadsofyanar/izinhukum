<?php
// Banner mode pratinjau admin. Di-include dari topbar.php (DI ATAS <header>) saat
// $_SESSION['impersonating'] aktif. NORMAL FLOW (bukan fixed) → selalu mendorong
// topbar + konten ke bawah berapa pun tinggi teksnya, jadi menu burger di topbar
// tak pernah tertimpa (desktop & mobile).
?>
<div id="impersonate-banner" class="relative z-30 shrink-0 bg-amber-400 text-amber-950 px-4 py-2 flex items-center justify-between gap-3 text-sm font-medium shadow-sm border-b-2 border-amber-500">
  <div class="flex items-center gap-2 min-w-0">
    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
      <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
    </svg>
    <span class="truncate min-w-0">
      <strong>Mode Pratinjau Admin</strong><span class="hidden sm:inline"> — melihat sebagai</span>
      <strong><?= e($_SESSION['name'] ?? '') ?></strong>
      <span class="hidden sm:inline opacity-70">(<?= e($_SESSION['email'] ?? '') ?>)</span>
    </span>
  </div>
  <a href="<?= e(APP_URL) ?>/actions/admin/impersonate-stop?csrf_token=<?= e(generateCSRF()) ?>"
     class="flex items-center gap-1.5 bg-amber-950 text-amber-50 px-3 py-1.5 rounded-lg text-xs font-semibold hover:bg-amber-900 transition-colors whitespace-nowrap shrink-0">
    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
    </svg>
    Kembali ke Admin
  </a>
</div>
