<?php
// Admin — Info Member: pengumuman in-app di member area (lonceng topbar user).
// Target per-info: global (semua user) / users (daftar email tertentu) / product
// (semua pemilik akses produk). CRUD ringan dgn POST handler PRG di file ini sendiri, pola support-page.php.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pdo = getDB();
ensureAnnouncementCtaColumns($pdo); // self-heal kolom cta_url/cta_text (v1.2.55)

// ═══ POST HANDLER (PRG) ═════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        flash('inf', 'Token keamanan tidak valid.', 'error');
        redirect('/admin/info-member');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('UPDATE announcements SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
            flash('inf', 'Status info diperbarui.', 'success');
        }
        redirect('/admin/info-member');
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]); // reads/users ikut (FK cascade)
            flash('inf', 'Info dihapus.', 'success');
        }
        redirect('/admin/info-member');
    }

    if ($action === 'save') {
        $id     = (int) ($_POST['id'] ?? 0);
        $title  = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 200);
        $body   = trim((string) ($_POST['body'] ?? ''));
        $target = in_array($_POST['target_type'] ?? 'global', ['global', 'users', 'product'], true)
                ? $_POST['target_type'] : 'global';
        $productId = ($target === 'product') ? (int) ($_POST['product_id'] ?? 0) : null;

        // Tombol CTA opsional: URL + teks tombol. Tombol hanya tampil bila URL diisi;
        // teks kosong → default "Selengkapnya". URL dinormalisasi (blokir skema berbahaya).
        $ctaUrl  = sanitizeCtaUrl((string) ($_POST['cta_url'] ?? ''));
        $ctaText = mb_substr(trim((string) ($_POST['cta_text'] ?? '')), 0, 80);
        if ($ctaUrl === '') {
            $ctaUrl = null; $ctaText = null;                 // tak ada tombol
        } elseif ($ctaText === '') {
            $ctaText = 'Selengkapnya';                        // default teks tombol
        }

        if ($title === '' || $body === '') {
            flash('inf', 'Judul dan isi wajib diisi.', 'error');
            redirect('/admin/info-member');
        }
        if ($target === 'product' && $productId <= 0) {
            flash('inf', 'Pilih produk untuk target pembeli produk.', 'error');
            redirect('/admin/info-member');
        }

        // Target users: id dari picker (dipilih via pencarian) → validasi user terdaftar.
        $targetUserIds = [];
        if ($target === 'users') {
            $ids = array_values(array_unique(array_filter(array_map('intval',
                (array) ($_POST['target_user_ids'] ?? [])), fn($n) => $n > 0)));
            if (!$ids) {
                flash('inf', 'Pilih minimal satu user untuk target user tertentu.', 'error');
                redirect('/admin/info-member');
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT id FROM users WHERE id IN ($ph)");
            $st->execute($ids);
            $targetUserIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            if (!$targetUserIds) {
                flash('inf', 'User yang dipilih tidak ditemukan.', 'error');
                redirect('/admin/info-member');
            }
        }

        try {
            $pdo->beginTransaction();
            if ($id > 0) {
                $pdo->prepare('UPDATE announcements SET title = ?, body = ?, cta_url = ?, cta_text = ?, target_type = ?, product_id = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$title, $body, $ctaUrl, $ctaText, $target, $productId, $id]);
                $pdo->prepare('DELETE FROM announcement_users WHERE announcement_id = ?')->execute([$id]);
            } else {
                $pdo->prepare('INSERT INTO announcements (title, body, cta_url, cta_text, target_type, product_id, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, 1, ?)')
                    ->execute([$title, $body, $ctaUrl, $ctaText, $target, $productId, (int) $_SESSION['user_id']]);
                $id = (int) $pdo->lastInsertId();
            }
            if ($targetUserIds) {
                $ins = $pdo->prepare('INSERT IGNORE INTO announcement_users (announcement_id, user_id) VALUES (?, ?)');
                foreach ($targetUserIds as $uid) $ins->execute([$id, $uid]);
            }
            $pdo->commit();
            flash('inf', 'Info tersimpan & tayang di member area.', 'success');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('info-member save: ' . $e->getMessage());
            flash('inf', 'Gagal menyimpan info.', 'error');
        }
        redirect('/admin/info-member');
    }

    redirect('/admin/info-member');
}

// ═══ GET DISPLAY ════════════════════════════════════════════════
$pageTitle   = 'Info Member';
$topbarTitle = 'Info Member';

$rows = [];
try {
    $rows = $pdo->query(
        "SELECT a.*, p.name AS product_name,
                (SELECT COUNT(*) FROM announcement_users au WHERE au.announcement_id = a.id) AS target_count,
                (SELECT COUNT(*) FROM announcement_reads ar WHERE ar.announcement_id = a.id) AS read_count
         FROM announcements a
         LEFT JOIN products p ON p.id = a.product_id
         ORDER BY a.created_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { error_log('info-member list: ' . $e->getMessage()); }

$products = [];
try {
    $products = $pdo->query("SELECT id, name FROM products WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// User target existing (untuk prefill chip saat edit) — data-atribut JSON per baris.
$targetUsers = [];
try {
    $st = $pdo->query("SELECT au.announcement_id, u.id, u.name, u.email FROM announcement_users au JOIN users u ON u.id = au.user_id ORDER BY u.name");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $targetUsers[(int) $r['announcement_id']][] = ['id' => (int) $r['id'], 'name' => $r['name'], 'email' => $r['email']];
    }
} catch (Throwable $e) {}

generateCSRF();
$inputCls = 'w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition';
$labelCls = 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5';
$cardCls  = 'bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm';
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php
      if (($f = getFlash('inf')) !== null) {
          $isErr = ($f['type'] ?? '') === 'error';
          $wrap  = $isErr
              ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-800 dark:text-red-300'
              : 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300';
          echo '<div class="mb-4 px-4 py-3 rounded-xl border text-sm font-medium ' . $wrap . '">' . $f['message'] . '</div>';
      }
      ?>

      <div class="mb-6 flex items-start justify-between gap-4 flex-wrap">
        <div>
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Info Member</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Pengumuman dari admin yang tampil di lonceng member area — target semua user, user tertentu, atau pembeli produk.</p>
        </div>
        <button type="button" onclick="infOpenForm()" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-semibold text-white hover:opacity-95 transition" style="background: var(--brand-color);">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg>
          Buat Info
        </button>
      </div>

      <!-- Form (hidden by default) -->
      <div id="infForm" class="hidden <?= $cardCls ?> p-5 mb-6">
        <form method="POST" action="<?= e(APP_URL) ?>/admin/info-member" class="space-y-4">
          <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" id="infId" value="">

          <div>
            <label class="<?= $labelCls ?>">Judul</label>
            <input type="text" name="title" id="infTitle" maxlength="200" required class="<?= $inputCls ?>" placeholder="Judul singkat info">
          </div>
          <div>
            <label class="<?= $labelCls ?>">Isi</label>
            <textarea name="body" id="infBody" rows="4" required class="<?= $inputCls ?> resize-y" placeholder="Isi pengumuman untuk member"></textarea>
          </div>

          <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-3">
            <div class="flex items-center justify-between">
              <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Tombol Aksi (CTA) <span class="text-xs font-normal text-gray-400">— opsional</span></p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Teks Tombol</label>
                <input type="text" name="cta_text" id="infCtaText" maxlength="80" class="<?= $inputCls ?>" placeholder="mis. Gabung Grup, Lihat Promo">
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">URL Tujuan</label>
                <input type="text" name="cta_url" id="infCtaUrl" maxlength="500" class="<?= $inputCls ?>" placeholder="https://… atau /halaman">
              </div>
            </div>
            <p class="text-xs text-gray-400 dark:text-gray-500">Tombol hanya muncul bila URL diisi. Teks kosong akan memakai default &ldquo;Selengkapnya&rdquo;. Tautan terbuka di tab baru.</p>
          </div>

          <div>
            <label class="<?= $labelCls ?>">Target</label>
            <div class="flex flex-wrap gap-4">
              <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
                <input type="radio" name="target_type" value="global" checked onchange="infSyncTarget()" class="accent-[var(--brand-color)]"> Semua user
              </label>
              <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
                <input type="radio" name="target_type" value="users" onchange="infSyncTarget()" class="accent-[var(--brand-color)]"> User tertentu
              </label>
              <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
                <input type="radio" name="target_type" value="product" onchange="infSyncTarget()" class="accent-[var(--brand-color)]"> Pembeli produk
              </label>
            </div>
          </div>

          <div id="infTargetUsers" class="hidden">
            <label class="<?= $labelCls ?>">Pilih user</label>
            <div id="infUserChips" class="flex flex-wrap gap-1.5 mb-2 empty:hidden"></div>
            <div class="relative">
              <input type="text" id="infUserSearch" autocomplete="off" class="<?= $inputCls ?>" placeholder="Ketik nama atau email user (min 2 huruf)…">
              <div id="infUserResults" class="hidden absolute left-0 right-0 top-full mt-1 bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 shadow-lg max-h-56 overflow-y-auto" style="z-index: 40;"></div>
            </div>
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Cari dari data user terdaftar, klik untuk menambahkan. Klik tanda silang pada chip untuk menghapus.</p>
          </div>

          <div id="infTargetProduct" class="hidden">
            <label class="<?= $labelCls ?>">Produk</label>
            <select name="product_id" id="infProduct" class="<?= $inputCls ?>">
              <option value="">— pilih produk —</option>
              <?php foreach ($products as $p): ?>
              <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Tayang untuk semua user yang punya akses produk ini.</p>
          </div>

          <div class="flex items-center gap-3">
            <button type="submit" class="px-4 py-2.5 rounded-lg text-sm font-semibold text-white hover:opacity-95 transition" style="background: var(--brand-color);">Simpan &amp; Tayangkan</button>
            <button type="button" onclick="document.getElementById('infForm').classList.add('hidden')" class="px-4 py-2.5 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Batal</button>
          </div>
        </form>
      </div>

      <!-- Daftar -->
      <div class="<?= $cardCls ?> overflow-hidden">
        <?php if (!$rows): ?>
        <div class="p-10 text-center">
          <p class="text-sm font-medium text-gray-600 dark:text-gray-300">Belum ada info</p>
          <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Klik "Buat Info" untuk mengumumkan sesuatu ke member area.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500 border-b border-gray-100 dark:border-gray-800">
                <th class="px-4 py-3">Judul</th>
                <th class="px-4 py-3">Target</th>
                <th class="px-4 py-3 text-center">Dibaca</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">Dibuat</th>
                <th class="px-4 py-3 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r):
                $usersJson = json_encode($targetUsers[(int) $r['id']] ?? [], JSON_UNESCAPED_UNICODE);
                $targetLabel = match ($r['target_type']) {
                    'users'   => 'User tertentu (' . (int) $r['target_count'] . ')',
                    'product' => 'Pembeli: ' . ($r['product_name'] ?: '(produk terhapus)'),
                    default   => 'Semua user',
                };
              ?>
              <tr class="border-b border-gray-50 dark:border-gray-800/60"
                  data-id="<?= (int) $r['id'] ?>" data-title="<?= e($r['title']) ?>" data-body="<?= e($r['body']) ?>"
                  data-target="<?= e($r['target_type']) ?>" data-product="<?= (int) ($r['product_id'] ?? 0) ?>" data-users="<?= e($usersJson) ?>"
                  data-cta-url="<?= e($r['cta_url'] ?? '') ?>" data-cta-text="<?= e($r['cta_text'] ?? '') ?>">
                <td class="px-4 py-3.5 font-medium text-gray-900 dark:text-white max-w-[260px] truncate"><?= e($r['title']) ?></td>
                <td class="px-4 py-3.5 text-gray-600 dark:text-gray-300"><?= e($targetLabel) ?></td>
                <td class="px-4 py-3.5 text-center text-gray-600 dark:text-gray-300"><?= (int) $r['read_count'] ?></td>
                <td class="px-4 py-3.5">
                  <?php if ((int) $r['is_active'] === 1): ?>
                  <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">Tayang</span>
                  <?php else: ?>
                  <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">Nonaktif</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3.5 text-gray-500 dark:text-gray-400"><?= e(date('d M Y', strtotime($r['created_at']))) ?></td>
                <td class="px-4 py-3.5">
                  <div class="flex items-center justify-end gap-2">
                    <button type="button" onclick="infEdit(this)" class="text-xs font-medium text-[var(--brand-color)] hover:underline">Edit</button>
                    <form method="POST" action="<?= e(APP_URL) ?>/admin/info-member" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                      <button type="submit" class="text-xs font-medium text-gray-500 dark:text-gray-400 hover:underline"><?= (int) $r['is_active'] === 1 ? 'Nonaktifkan' : 'Tayangkan' ?></button>
                    </form>
                    <form method="POST" action="<?= e(APP_URL) ?>/admin/info-member" class="inline" id="del-inf-<?= (int) $r['id'] ?>">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                      <button type="button" onclick="confirmAndSubmit('del-inf-<?= (int) $r['id'] ?>', {title:'Hapus Info?', message:'Info ini dihapus permanen beserta status dibaca member.', confirmText:'Ya, Hapus', variant:'danger'})" class="text-xs font-medium text-red-500 hover:underline">Hapus</button>
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

<script>
function infSyncTarget() {
  var t = document.querySelector('input[name="target_type"]:checked').value;
  document.getElementById('infTargetUsers').classList.toggle('hidden', t !== 'users');
  document.getElementById('infTargetProduct').classList.toggle('hidden', t !== 'product');
}
function infOpenForm() {
  document.getElementById('infId').value = '';
  document.getElementById('infTitle').value = '';
  document.getElementById('infBody').value = '';
  document.getElementById('infCtaText').value = '';
  document.getElementById('infCtaUrl').value = '';
  document.getElementById('infProduct').value = '';
  document.querySelector('input[name="target_type"][value="global"]').checked = true;
  infClearChips();
  infSyncTarget();
  document.getElementById('infForm').classList.remove('hidden');
  document.getElementById('infTitle').focus();
}
function infEdit(btn) {
  var tr = btn.closest('tr');
  document.getElementById('infId').value = tr.dataset.id;
  document.getElementById('infTitle').value = tr.dataset.title;
  document.getElementById('infBody').value = tr.dataset.body;
  document.getElementById('infCtaText').value = tr.dataset.ctaText || '';
  document.getElementById('infCtaUrl').value = tr.dataset.ctaUrl || '';
  document.getElementById('infProduct').value = tr.dataset.product !== '0' ? tr.dataset.product : '';
  var radio = document.querySelector('input[name="target_type"][value="' + tr.dataset.target + '"]');
  if (radio) radio.checked = true;
  infClearChips();
  try { (JSON.parse(tr.dataset.users || '[]')).forEach(function (u) { infAddUser(u); }); } catch (e) {}
  infSyncTarget();
  document.getElementById('infForm').classList.remove('hidden');
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ── Picker user (search + chip multi-select) ──
var infSearchTimer = null;
function infClearChips() { document.getElementById('infUserChips').innerHTML = ''; }
function infSelectedIds() {
  return Array.from(document.querySelectorAll('#infUserChips input[name="target_user_ids[]"]')).map(function (i) { return i.value; });
}
function infAddUser(u) {
  if (infSelectedIds().indexOf(String(u.id)) !== -1) return;
  var chip = document.createElement('span');
  chip.className = 'inline-flex items-center gap-1.5 pl-2.5 pr-1.5 py-1 rounded-lg text-xs font-medium bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300';
  var label = document.createElement('span');
  label.textContent = u.name + ' (' + u.email + ')';
  var hid = document.createElement('input');
  hid.type = 'hidden'; hid.name = 'target_user_ids[]'; hid.value = u.id;
  var x = document.createElement('button');
  x.type = 'button';
  x.className = 'w-4 h-4 rounded flex items-center justify-center text-gray-400 hover:text-red-500 transition';
  x.setAttribute('aria-label', 'Hapus');
  x.innerHTML = '<svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
  x.onclick = function () { chip.remove(); };
  chip.appendChild(label); chip.appendChild(hid); chip.appendChild(x);
  document.getElementById('infUserChips').appendChild(chip);
}
document.getElementById('infUserSearch').addEventListener('input', function () {
  var q = this.value.trim();
  var box = document.getElementById('infUserResults');
  clearTimeout(infSearchTimer);
  if (q.length < 2) { box.classList.add('hidden'); return; }
  infSearchTimer = setTimeout(function () {
    fetch(<?= json_encode(rtrim(APP_URL, '/') . '/actions/admin/info-user-search') ?> + '?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (list) {
        box.innerHTML = '';
        if (!list.length) {
          var p = document.createElement('p');
          p.className = 'px-3 py-2.5 text-xs text-gray-400';
          p.textContent = 'Tidak ada user yang cocok.';
          box.appendChild(p);
        } else {
          list.forEach(function (u) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'w-full text-left px-3 py-2 hover:bg-gray-50 dark:hover:bg-gray-800 transition';
            var n = document.createElement('span'); n.className = 'block text-sm text-gray-900 dark:text-white'; n.textContent = u.name;
            var m = document.createElement('span'); m.className = 'block text-xs text-gray-400'; m.textContent = u.email;
            b.appendChild(n); b.appendChild(m);
            b.onclick = function () {
              infAddUser(u);
              box.classList.add('hidden');
              document.getElementById('infUserSearch').value = '';
            };
            box.appendChild(b);
          });
        }
        box.classList.remove('hidden');
      }).catch(function () { box.classList.add('hidden'); });
  }, 250);
});
document.addEventListener('click', function (e) {
  var wrap = document.getElementById('infTargetUsers');
  if (wrap && !wrap.contains(e.target)) document.getElementById('infUserResults').classList.add('hidden');
});
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
