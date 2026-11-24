<?php
// Ambil semua flash messages dari session dan tampilkan
$_flashes = $_SESSION['flash'] ?? [];
if (!empty($_flashes)):
    unset($_SESSION['flash']); // hapus setelah dibaca
?>
<div class="flash-container space-y-2 mb-4" role="alert" aria-live="polite">
<?php foreach ($_flashes as $_key => $_flash):
    $_type    = $_flash['type']    ?? 'info';
    $_message = $_flash['message'] ?? '';

    // Style per tipe
    $_styles = match ($_type) {
        'success' => [
            'wrap'  => 'bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-800 dark:text-green-300',
            'icon'  => 'text-green-500 dark:text-green-400',
            'close' => 'text-green-400 hover:text-green-600 dark:hover:text-green-200',
            'svg'   => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        ],
        'error', 'danger' => [
            'wrap'  => 'bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-300',
            'icon'  => 'text-red-500 dark:text-red-400',
            'close' => 'text-red-400 hover:text-red-600 dark:hover:text-red-200',
            'svg'   => '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>',
        ],
        'warning' => [
            'wrap'  => 'bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 text-yellow-800 dark:text-yellow-300',
            'icon'  => 'text-yellow-500 dark:text-yellow-400',
            'close' => 'text-yellow-400 hover:text-yellow-600 dark:hover:text-yellow-200',
            'svg'   => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        ],
        default => [
            'wrap'  => 'bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 text-blue-800 dark:text-blue-300',
            'icon'  => 'text-blue-500 dark:text-blue-400',
            'close' => 'text-blue-400 hover:text-blue-600 dark:hover:text-blue-200',
            'svg'   => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        ],
    };
?>
  <div data-flash-message
       class="flex items-start gap-3 px-4 py-3 rounded-xl <?= $_styles['wrap'] ?>">
    <!-- Icon -->
    <svg class="w-5 h-5 shrink-0 mt-0.5 <?= $_styles['icon'] ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <?= $_styles['svg'] ?>
    </svg>
    <!-- Message -->
    <p class="flex-1 text-sm font-medium leading-snug"><?= e($_message) ?></p>
    <!-- Close button -->
    <button data-flash-close
            class="shrink-0 ml-1 <?= $_styles['close'] ?> transition-colors"
            aria-label="Tutup pesan">
      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
        <line x1="18" y1="6" x2="6" y2="18"/>
        <line x1="6" y1="6" x2="18" y2="18"/>
      </svg>
    </button>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
