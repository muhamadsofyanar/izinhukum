<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pageTitle   = 'Rekening Bank';
$topbarTitle = 'Rekening Bank';

$accounts = [];
try {
    $accounts = getDB()->query(
        "SELECT * FROM bank_accounts ORDER BY sort_order ASC, id ASC"
    )->fetchAll();
} catch (Exception $e) {
    error_log('bank-accounts load error: ' . $e->getMessage());
}

generateCSRF();
$csrf = $_SESSION['csrf_token'];
$bankPresets    = ['BCA', 'Mandiri', 'BNI', 'BRI', 'CIMB Niaga', 'Permata', 'Danamon', 'BSI', 'SeaBank'];
$ewalletPresets = ['GoPay', 'OVO', 'DANA', 'ShopeePay', 'LinkAja'];

// ─── Manual Payment Details: QR + instruksi global (tabel settings) ───
$manualQr          = getSetting('manual_qr_image'); // path relatif dari APP_URL, atau null
$manualInstruction = getSetting('manual_payment_instruction',
    'Boleh bayar QR Pay atau manual transfer. Lepas bayar, screenshot resit & WhatsApp untuk akses segera.');
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">
  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>
  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">
    <?php include __DIR__ . '/../../partials/topbar.php'; ?>
    <main class="flex-1 overflow-y-auto p-5 lg:p-6">
      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <div class="mb-5">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">Pembayaran Manual</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Atur QR Pay, instruksi, dan rekening tujuan yang tampil di halaman checkout.</p>
      </div>

      <!-- ═══ Detail Pembayaran Manual: QR + Instruksi ═══ -->
      <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-manual-payment-settings" enctype="multipart/form-data"
            class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 mb-5">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">QR Pay &amp; Instruksi</h3>
        <div class="grid md:grid-cols-[auto_1fr] gap-5">

          <!-- QR image -->
          <div>
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 uppercase mb-1.5">Gambar QR</label>
            <div class="w-40">
              <div class="aspect-square rounded-lg border border-dashed border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 overflow-hidden flex items-center justify-center">
                <?php if ($manualQr): ?>
                  <img src="<?= e(APP_URL) ?>/<?= e($manualQr) ?>" alt="QR Pay" class="w-full h-full object-contain">
                <?php else: ?>
                  <svg class="w-10 h-10 text-gray-300 dark:text-gray-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3M20 20h.01M17 20h.01M20 17h.01"/></svg>
                <?php endif; ?>
              </div>
              <input type="file" name="manual_qr" accept="image/jpeg,image/png"
                     class="block w-full text-xs text-gray-600 dark:text-gray-300 mt-2 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-gray-100 dark:file:bg-gray-800 file:text-gray-700 dark:file:text-gray-200 file:cursor-pointer cursor-pointer">
              <p class="text-[11px] text-gray-400 mt-1">JPG/PNG, maks 2MB.</p>
              <?php if ($manualQr): ?>
              <label class="inline-flex items-center gap-1.5 mt-1.5 text-[11px] text-red-500 cursor-pointer">
                <input type="checkbox" name="remove_qr" value="1" class="w-3.5 h-3.5 accent-red-500"> Hapus QR saat simpan
              </label>
              <?php endif; ?>
            </div>
          </div>

          <!-- Instruksi -->
          <div>
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 uppercase mb-1.5">Teks Instruksi <span class="text-gray-400 normal-case">(tampil di atas detail pembayaran)</span></label>
            <textarea name="manual_payment_instruction" rows="4" maxlength="1000"
                      class="w-full px-3 py-2 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white resize-none"><?= e($manualInstruction) ?></textarea>
            <div class="mt-3">
              <button type="submit" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Simpan QR &amp; Instruksi
              </button>
            </div>
          </div>
        </div>
      </form>

      <div class="flex flex-wrap items-center justify-between gap-4 mb-3">
        <div>
          <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Rekening &amp; E-Wallet</h3>
          <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Channel tujuan untuk pembayaran Manual Transfer — rekening bank maupun e-wallet (GoPay, OVO, DANA, dll).</p>
        </div>
        <button type="button" onclick="openBankModal()"
                class="shrink-0 inline-flex items-center gap-2 whitespace-nowrap px-4 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Tambah Channel
        </button>
      </div>

      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <?php if (empty($accounts)): ?>
        <div class="p-10 text-center">
          <p class="text-sm text-gray-500 dark:text-gray-400">Belum ada channel pembayaran. Klik "Tambah Channel" untuk menambahkan.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800/50 text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
              <tr>
                <th class="px-5 py-3 text-left font-semibold">Channel</th>
                <th class="px-5 py-3 text-left font-semibold">Tipe</th>
                <th class="px-5 py-3 text-left font-semibold">Nomor</th>
                <th class="px-5 py-3 text-left font-semibold">Atas Nama</th>
                <th class="px-5 py-3 text-left font-semibold">Status</th>
                <th class="px-5 py-3 text-right font-semibold">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <?php foreach ($accounts as $a):
                  $cType = $a['channel_type'] ?? 'bank';
              ?>
              <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/30">
                <td class="px-5 py-3">
                  <div class="flex items-center gap-2.5">
                    <?= paymentChannelIcon($cType, $a['bank_name']) ?>
                    <span class="font-medium text-gray-900 dark:text-white"><?= e($a['bank_name']) ?></span>
                  </div>
                </td>
                <td class="px-5 py-3">
                  <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?= $cType === 'ewallet' ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400' : 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' ?>">
                    <?= $cType === 'ewallet' ? 'E-Wallet' : 'Bank' ?>
                  </span>
                </td>
                <td class="px-5 py-3 font-mono text-gray-700 dark:text-gray-300"><?= e($a['account_number']) ?></td>
                <td class="px-5 py-3 text-gray-700 dark:text-gray-300"><?= e($a['account_name']) ?></td>
                <td class="px-5 py-3">
                  <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-bank-account" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                    <input type="hidden" name="toggle_only" value="1">
                    <button type="submit" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold <?= $a['is_active'] ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' ?>">
                      <span class="w-1.5 h-1.5 rounded-full <?= $a['is_active'] ? 'bg-green-500' : 'bg-gray-400' ?>"></span>
                      <?= $a['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                    </button>
                  </form>
                </td>
                <td class="px-5 py-3">
                  <div class="flex items-center justify-end gap-1">
                    <button type="button" title="Edit"
                            onclick='openBankModal(<?= json_encode([
                                "id" => (int) $a["id"], "channel_type" => $cType, "bank_name" => $a["bank_name"],
                                "account_number" => $a["account_number"], "account_name" => $a["account_name"],
                                "instructions" => $a["instructions"], "is_active" => (int) $a["is_active"],
                                "sort_order" => (int) $a["sort_order"],
                            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                            class="p-2 rounded-lg text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800">
                      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>
                    </button>
                    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/delete-bank-account" id="del-bank-<?= (int) $a['id'] ?>" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                      <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                      <button type="button" title="Hapus"
                              onclick="confirmAndSubmit('del-bank-<?= (int) $a['id'] ?>',{title:'Hapus Channel?',message:'Channel <?= e($a['bank_name']) ?> akan dihapus. Tidak bisa dihapus bila sudah dipakai di transaksi.',confirmText:'Ya, Hapus',variant:'danger'})"
                              class="p-2 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </main>
  </div>
</div>

<!-- Modal Tambah/Edit Channel -->
<div id="bank-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4">
  <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-md">
    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-bank-account">
      <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800">
        <h3 id="bank-modal-title" class="text-base font-semibold text-gray-900 dark:text-white">Tambah Channel</h3>
        <button type="button" onclick="closeBankModal()" class="text-gray-400 hover:text-gray-600">✕</button>
      </div>
      <div class="p-5 space-y-3.5">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="id" id="bank-id" value="">
        <input type="hidden" name="channel_type" id="bank-channel-type" value="bank">

        <!-- Tipe: Bank / E-Wallet -->
        <div>
          <div class="flex gap-1 p-1 rounded-lg bg-gray-100 dark:bg-gray-800 w-fit">
            <button type="button" id="bank-tab-bank" onclick="bankChannelTab('bank')"
                    class="bank-channel-tab px-3 py-1.5 rounded-md text-xs font-semibold transition">Rekening Bank</button>
            <button type="button" id="bank-tab-ewallet" onclick="bankChannelTab('ewallet')"
                    class="bank-channel-tab px-3 py-1.5 rounded-md text-xs font-semibold transition">E-Wallet</button>
          </div>
        </div>

        <div>
          <label id="bank-name-label" class="block text-xs font-medium text-gray-600 dark:text-gray-400 uppercase mb-1.5">Nama Bank</label>
          <input type="text" name="bank_name" id="bank-name" list="bank-presets" required maxlength="100"
                 class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
          <datalist id="bank-presets"><?php foreach ($bankPresets as $bp): ?><option value="<?= e($bp) ?>"><?php endforeach; ?></datalist>
          <datalist id="ewallet-presets"><?php foreach ($ewalletPresets as $ep): ?><option value="<?= e($ep) ?>"><?php endforeach; ?></datalist>
        </div>
        <div>
          <label id="bank-number-label" class="block text-xs font-medium text-gray-600 dark:text-gray-400 uppercase mb-1.5">Nomor Rekening</label>
          <input type="text" name="account_number" id="bank-number" required maxlength="50"
                 class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white font-mono">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 uppercase mb-1.5">Atas Nama</label>
          <input type="text" name="account_name" id="bank-acc-name" required maxlength="100"
                 class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 uppercase mb-1.5">Instruksi <span class="text-gray-400 normal-case">(opsional)</span></label>
          <textarea name="instructions" id="bank-instructions" rows="2" maxlength="500"
                    placeholder="contoh: Konfirmasi via WhatsApp setelah transfer"
                    class="w-full px-3 py-2 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white resize-none"></textarea>
        </div>
        <div class="flex items-center gap-4">
          <div class="flex-1">
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 uppercase mb-1.5">Urutan</label>
            <input type="number" name="sort_order" id="bank-sort" value="0" step="1"
                   class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
          </div>
          <label class="flex items-center gap-2 cursor-pointer pt-5">
            <input type="checkbox" name="is_active" id="bank-active" value="1" checked class="w-4 h-4 accent-[var(--brand-color)]">
            <span class="text-sm text-gray-700 dark:text-gray-300">Aktif</span>
          </label>
        </div>
      </div>
      <div class="flex gap-2 px-5 py-4 border-t border-gray-100 dark:border-gray-800">
        <button type="button" onclick="closeBankModal()" class="flex-1 py-2.5 border border-gray-200 dark:border-gray-700 text-sm font-medium rounded-xl">Batal</button>
        <button type="submit" class="flex-1 py-2.5 text-white text-sm font-semibold rounded-xl bg-brand hover:opacity-90">Simpan</button>
      </div>
    </form>
  </div>
</div>

<style>
  .bank-channel-tab { color: rgb(107 114 128); }
  .dark .bank-channel-tab { color: rgb(156 163 175); }
  .bank-channel-tab.bank-channel-tab-active {
    background: #fff; color: var(--brand-color);
    box-shadow: 0 1px 2px rgba(0,0,0,.08);
  }
  .dark .bank-channel-tab.bank-channel-tab-active { background: rgb(31 41 55); }
</style>

<script>
function bankChannelTab(type) {
  document.getElementById('bank-channel-type').value = type;
  document.getElementById('bank-tab-bank').classList.toggle('bank-channel-tab-active', type === 'bank');
  document.getElementById('bank-tab-ewallet').classList.toggle('bank-channel-tab-active', type === 'ewallet');

  var nameInput = document.getElementById('bank-name');
  if (type === 'ewallet') {
    document.getElementById('bank-name-label').textContent = 'Provider E-Wallet';
    document.getElementById('bank-number-label').textContent = 'Nomor HP / ID Akun';
    nameInput.setAttribute('list', 'ewallet-presets');
    nameInput.placeholder = 'mis. GoPay';
  } else {
    document.getElementById('bank-name-label').textContent = 'Nama Bank';
    document.getElementById('bank-number-label').textContent = 'Nomor Rekening';
    nameInput.setAttribute('list', 'bank-presets');
    nameInput.placeholder = '';
  }
}

function openBankModal(data) {
  document.getElementById('bank-modal-title').textContent = data ? 'Edit Channel' : 'Tambah Channel';
  document.getElementById('bank-id').value          = data ? data.id : '';
  document.getElementById('bank-name').value         = data ? data.bank_name : '';
  document.getElementById('bank-number').value       = data ? data.account_number : '';
  document.getElementById('bank-acc-name').value     = data ? data.account_name : '';
  document.getElementById('bank-instructions').value = data && data.instructions ? data.instructions : '';
  document.getElementById('bank-sort').value         = data ? data.sort_order : 0;
  document.getElementById('bank-active').checked     = data ? !!data.is_active : true;
  bankChannelTab(data && data.channel_type === 'ewallet' ? 'ewallet' : 'bank');
  document.getElementById('bank-modal').classList.remove('hidden');
}
function closeBankModal() { document.getElementById('bank-modal').classList.add('hidden'); }
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
