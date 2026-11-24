<?php
if (!isset($root, $csrf)) {
    http_response_code(404);
    exit;
}

$formUrl = rtrim(APP_URL, '/') . $detailPath;
$listUrl = rtrim(APP_URL, '/') . $path;
$panelClass = 'dmi-kajian-panel rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900';
$inputClass = 'mt-1 w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 dark:border-gray-700';
$buttonClass = 'inline-flex rounded-lg bg-brand px-4 py-2 font-semibold text-white';
$linkClass = 'font-semibold text-[var(--brand-color)] underline';
$formatDate = fn (string $date): string => (new DateTimeImmutable($date, $timezone))->format('d/m/Y H:i') . ' WIB';
$darmutiaraKajianTheme = true;
require $root . 'partials/head.php';
?>
<body class="bg-gray-50 text-gray-900 dark:bg-gray-950 dark:text-gray-100">
<div class="flex h-screen overflow-hidden">
  <?php require $root . ($admin ? 'partials/sidebar-admin.php' : 'partials/sidebar-user.php'); ?>
  <div class="flex min-w-0 flex-1 flex-col overflow-hidden lg:ml-60">
    <?php require $root . 'partials/topbar.php'; ?>
    <main class="flex-1 overflow-y-auto p-5 lg:p-6">
      <div class="mx-auto max-w-4xl space-y-5">
        <?php require $root . 'partials/flash-message.php'; ?>
        <section class="dmi-hero" aria-labelledby="kajian-heading">
          <div>
            <h2 id="kajian-heading"><?= e($pageError === '' ? ($event['title'] ?? $pageTitle) : $pageTitle) ?></h2>
            <?php if (!$id): ?>
              <p><?= $admin ? 'Jadwalkan pertemuan dan kelola diskusi anggota.' : 'Temukan jadwal, tautan pertemuan, dan rekaman kajian.' ?></p>
            <?php elseif ($pageError === '' && !empty($event['speaker'])): ?>
              <p><?= e($event['speaker']) ?> · <?= e($formatDate($event['starts_at'])) ?></p>
            <?php endif; ?>
          </div>
          <?php if ($id): ?>
            <a class="dmi-cta" href="<?= e($listUrl) ?>"><span aria-hidden="true">←</span> Daftar Kajian</a>
          <?php endif; ?>
        </section>

        <?php if ($pageError !== ''): ?>
          <p role="alert" class="<?= $panelClass ?>"><?= e($pageError) ?></p>
        <?php else: ?>

          <?php if ($admin): ?>
            <details class="<?= $panelClass ?>" <?= $event ? 'open' : '' ?>>
              <summary class="cursor-pointer text-lg font-bold"><?= $event ? 'Ubah Kajian' : 'Tambah Kajian' ?></summary>
              <form method="post" action="<?= e($formUrl) ?>" class="mt-4 space-y-4">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="intent" value="save">
                <div class="grid gap-4 sm:grid-cols-2">
                  <label>Judul
                    <input class="<?= $inputClass ?>" name="title" maxlength="180" required value="<?= e($event['title'] ?? '') ?>">
                  </label>
                  <label>Pemateri
                    <input class="<?= $inputClass ?>" name="speaker" maxlength="120" required value="<?= e($event['speaker'] ?? '') ?>">
                  </label>
                  <label>Tanggal dan waktu (WIB)
                    <input class="<?= $inputClass ?>" name="starts_at" type="datetime-local" required
                           value="<?= e($event ? str_replace(' ', 'T', substr($event['starts_at'], 0, 16)) : '') ?>">
                  </label>
                  <label>Lokasi
                    <input class="<?= $inputClass ?>" name="location" maxlength="240" value="<?= e($event['location'] ?? '') ?>">
                  </label>
                  <label>Tautan pertemuan
                    <input class="<?= $inputClass ?>" name="meeting_url" type="url" maxlength="500" value="<?= e($event['meeting_url'] ?? '') ?>">
                  </label>
                  <label>Tautan rekaman
                    <input class="<?= $inputClass ?>" name="recording_url" type="url" maxlength="500" value="<?= e($event['recording_url'] ?? '') ?>">
                  </label>
                  <label>Akses program
                    <select class="<?= $inputClass ?>" name="product_id">
                      <option value="">Semua anggota yang masuk</option>
                      <?php if ($event && $event['product_id'] !== null && !in_array((int) $event['product_id'], array_map('intval', array_column($products, 'id')), true)): ?>
                        <option selected value="<?= (int) $event['product_id'] ?>">Program terkait sudah dihapus</option>
                      <?php endif; ?>
                      <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>" <?= (int) ($event['product_id'] ?? 0) === (int) $product['id'] ? 'selected' : '' ?>>
                          <?= e($product['name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <span class="mt-1 block text-sm text-gray-600 dark:text-gray-400">Pilih produk e-course untuk menghubungkan jadwal ini dengan kelasnya.</span>
                  </label>
                </div>
                <label class="flex items-center gap-2">
                  <input type="checkbox" name="published" value="1" <?= !empty($event['published']) ? 'checked' : '' ?>>
                  Terbitkan untuk anggota
                </label>
                <p class="text-sm text-gray-500 dark:text-gray-400">Judul yang diawali [Demo] hanya tampil di admin.</p>
                <button class="<?= $buttonClass ?>" type="submit">Simpan Kajian</button>
              </form>
            </details>
          <?php endif; ?>

          <?php if (!$event): ?>
            <?php if ($admin): ?>
              <section class="<?= $panelClass ?>" aria-labelledby="kajian-filter">
                <h2 id="kajian-filter" class="mb-3 text-lg font-bold">Filter Kajian</h2>
                <form method="get" action="<?= e($listUrl) ?>" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                  <label class="text-sm font-medium">Cari judul atau pemateri
                    <input class="<?= $inputClass ?>" type="search" name="q" maxlength="180" value="<?= e($filters['q']) ?>">
                  </label>
                  <label class="text-sm font-medium">Jenis jadwal
                    <select class="<?= $inputClass ?>" name="kind">
                      <?php foreach (['regular' => 'Reguler', 'demo' => 'Demo', 'all' => 'Semua jadwal'] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $filters['kind'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label class="text-sm font-medium">Program
                    <select class="<?= $inputClass ?>" name="product">
                      <option value="">Semua program</option>
                      <option value="general" <?= $filters['product'] === 'general' ? 'selected' : '' ?>>Kajian umum</option>
                      <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>" <?= (int) $filters['product'] === (int) $product['id'] ? 'selected' : '' ?>><?= e($product['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label class="text-sm font-medium">Status
                    <select class="<?= $inputClass ?>" name="status">
                      <?php foreach (['' => 'Semua status', 'published' => 'Terbit', 'draft' => 'Draf'] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label class="text-sm font-medium">Dari tanggal (WIB)
                    <input class="<?= $inputClass ?>" type="date" name="from" value="<?= e($filters['from']) ?>">
                  </label>
                  <label class="text-sm font-medium">Sampai tanggal (WIB)
                    <input class="<?= $inputClass ?>" type="date" name="to" value="<?= e($filters['to']) ?>">
                  </label>
                  <div class="flex flex-wrap items-center gap-4 sm:col-span-2 lg:col-span-3">
                    <label class="inline-flex items-center gap-2 text-sm">
                      <input type="checkbox" name="when" value="upcoming" <?= $filters['when'] === 'upcoming' ? 'checked' : '' ?>>
                      Hanya kajian mendatang
                    </label>
                    <button type="submit" class="<?= $buttonClass ?>">Terapkan filter</button>
                    <a class="<?= $linkClass ?>" href="<?= e($listUrl) ?>">Reset filter</a>
                  </div>
                </form>
              </section>
            <?php endif; ?>
            <?php
            $eventGroups = $admin
                ? ['Daftar Kajian' => $events]
                : ['Kajian Mendatang' => $upcomingEvents, 'Kajian Sebelumnya' => $pastEvents];
            ?>
            <?php foreach ($eventGroups as $groupTitle => $items): ?>
            <section class="<?= $panelClass ?>">
              <h2 class="mb-3 text-lg font-bold"><?= e($groupTitle) ?><?= $admin ? ' (' . count($items) . ')' : '' ?></h2>
              <?php if (!$items): ?><p><?= $admin ? 'Tidak ada kajian yang cocok dengan filter.' : 'Belum ada kajian.' ?></p><?php endif; ?>
              <ul class="divide-y divide-gray-200 dark:divide-gray-800">
                <?php foreach ($items as $item): ?>
                  <li class="py-3">
                    <a class="<?= $linkClass ?>" href="<?= e($listUrl . '?id=' . (int) $item['id']) ?>"><?= e($item['title']) ?></a>
                    <p class="mt-1 text-sm"><?= e($item['speaker']) ?> · <?= e($formatDate($item['starts_at'])) ?>
                      <?php if ($admin): ?> · <?= $item['published'] ? 'Terbit' : 'Draf' ?><?php endif; ?>
                      <?php if (!$admin && $item['registered']): ?> · Terdaftar<?php endif; ?>
                    </p>
                  </li>
                <?php endforeach; ?>
              </ul>
            </section>
            <?php endforeach; ?>
          <?php elseif ($admin): ?>
            <section class="<?= $panelClass ?>">
              <h2 class="mb-3 text-lg font-bold">Peserta (<?= count($participants) ?>)</h2>
              <?php if (!$participants): ?><p>Belum ada peserta terdaftar.</p><?php endif; ?>
              <ul class="list-inside list-disc">
                <?php foreach ($participants as $participant): ?><li><?= e($participant['name']) ?></li><?php endforeach; ?>
              </ul>
            </section>
          <?php else: ?>
            <section class="<?= $panelClass ?> space-y-3">
              <h2 class="text-lg font-bold"><?= e($event['title']) ?></h2>
              <p><?= e($event['speaker']) ?> · <?= e($formatDate($event['starts_at'])) ?></p>
              <?php if ($event['location'] !== ''): ?><p><?= e($event['location']) ?></p><?php endif; ?>
              <?php if ($courseUrl !== null): ?>
                <p><a class="<?= $linkClass ?>" href="<?= e($courseUrl) ?>">Buka Materi E-course</a></p>
              <?php endif; ?>
              <?php if ($registered): ?>
                <p class="text-sm font-medium">Anda sudah terdaftar.</p>
                <div class="flex flex-wrap gap-4">
                  <a class="<?= $linkClass ?>" href="<?= e($formUrl . '&calendar=1') ?>">Unduh Pengingat Kalender</a>
                  <?php if ($event['meeting_url']): ?><a class="<?= $linkClass ?>" rel="noreferrer" href="<?= e($event['meeting_url']) ?>">Ikuti Kajian</a><?php endif; ?>
                  <?php if ($event['recording_url']): ?><a class="<?= $linkClass ?>" rel="noreferrer" href="<?= e($event['recording_url']) ?>">Buka Rekaman</a><?php endif; ?>
                </div>
              <?php elseif (!$preview): ?>
                <form method="post" action="<?= e($formUrl) ?>">
                  <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                  <button class="<?= $buttonClass ?>" name="intent" value="register" type="submit">Daftar Kajian</button>
                </form>
              <?php endif; ?>
            </section>
          <?php endif; ?>

          <?php if ($event && ($admin || $registered)): ?>
            <section class="<?= $panelClass ?>">
              <h2 class="mb-3 text-lg font-bold">Diskusi Kajian</h2>
              <?php if (!$admin && !$preview): ?>
                <form method="post" action="<?= e($formUrl) ?>" class="mb-5 space-y-2">
                  <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                  <label class="block">Tulis komentar
                    <textarea class="<?= $inputClass ?>" name="body" rows="3" maxlength="2000" required></textarea>
                  </label>
                  <button class="<?= $buttonClass ?>" name="intent" value="comment" type="submit">Kirim Komentar</button>
                </form>
              <?php endif; ?>
              <?php if (!$posts): ?><p>Belum ada komentar.</p><?php endif; ?>
              <ul class="divide-y divide-gray-200 dark:divide-gray-800">
                <?php foreach ($posts as $post): ?>
                  <li class="space-y-2 py-3">
                    <p class="font-semibold"><?= e($post['name']) ?><?= $admin && $post['hidden'] ? ' · Disembunyikan' : '' ?></p>
                    <p class="whitespace-pre-wrap break-words"><?= e($post['body']) ?></p>
                    <?php if ($admin): ?>
                      <form method="post" action="<?= e($formUrl) ?>">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
                        <button class="<?= $linkClass ?>" name="intent" value="<?= $post['hidden'] ? 'show' : 'hide' ?>" type="submit">
                          <?= $post['hidden'] ? 'Tampilkan' : 'Sembunyikan' ?>
                        </button>
                      </form>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            </section>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </main>
  </div>
</div>
<?php require $root . 'partials/footer.php'; ?>
