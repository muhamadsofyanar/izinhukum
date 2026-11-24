<?php // Modal konfirmasi global — compact, dikontrol via JS (confirmAndSubmit / confirmAndRun) ?>
<div id="confirmModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4">
  <div id="confirmModalBackdrop"
       class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm transition-opacity duration-200 opacity-0"></div>
  <div id="confirmModalCard"
       class="relative bg-white dark:bg-gray-900 rounded-xl shadow-xl border border-gray-100 dark:border-gray-800 max-w-[20rem] w-full p-4 transform transition-all duration-200 scale-95 opacity-0">
    <div class="flex items-start gap-2.5 mb-3.5">
      <div id="confirmModalIconWrap"
           class="w-8 h-8 shrink-0 rounded-lg bg-red-50 dark:bg-red-900/20 flex items-center justify-center">
        <svg id="confirmModalIcon" class="w-4 h-4 text-red-600 dark:text-red-400"
             fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round"
                d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
        </svg>
      </div>
      <div class="min-w-0 pt-0.5">
        <h3 id="confirmModalTitle" class="text-sm font-bold text-gray-900 dark:text-white">Konfirmasi</h3>
        <p id="confirmModalMessage" class="text-[13px] text-gray-500 dark:text-gray-400 leading-relaxed mt-0.5">Apakah kamu yakin?</p>
      </div>
    </div>
    <div class="flex gap-2 justify-end">
      <button type="button" id="confirmModalCancelBtn"
              class="px-3.5 py-1.5 text-[13px] font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 rounded-lg transition-colors">
        Batal
      </button>
      <button type="button" id="confirmModalConfirmBtn"
              class="px-3.5 py-1.5 text-[13px] font-semibold text-white bg-red-600 hover:bg-red-700 rounded-lg transition-colors">
        Ya, Lanjutkan
      </button>
    </div>
  </div>
</div>
