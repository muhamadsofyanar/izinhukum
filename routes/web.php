<?php

use App\Http\Controllers\Admin\AcademyController as AdminAcademyController;
use App\Http\Controllers\Admin\ArticleController as AdminArticleController;
use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\BrandingController as AdminBrandingController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\FeatureSettingController as AdminFeatureSettingController;
use App\Http\Controllers\Admin\FinanceController as AdminFinanceController;
use App\Http\Controllers\Admin\InquiryController as AdminInquiryController;
use App\Http\Controllers\Admin\MailSettingController as AdminMailSettingController;
use App\Http\Controllers\Admin\PackageController as AdminPackageController;
use App\Http\Controllers\Admin\PartnerController as AdminPartnerController;
use App\Http\Controllers\Admin\ServiceOrderController as AdminServiceOrderController;
use App\Http\Controllers\Admin\WhatsAppAutomationController;
use App\Http\Controllers\Admin\WhatsAppCampaignController;
use App\Http\Controllers\Admin\WhatsAppContactController;
use App\Http\Controllers\Admin\WhatsAppDocumentController;
use App\Http\Controllers\Admin\WhatsAppFaqController;
use App\Http\Controllers\Admin\WhatsAppLabelController;
use App\Http\Controllers\Admin\WhatsAppLeadController;
use App\Http\Controllers\Admin\WhatsAppSequenceController;
use App\Http\Controllers\Admin\WhatsAppWebhookMonitorController;
use App\Http\Controllers\Admin\WhatsAppDashboardController;
use App\Http\Controllers\Admin\WhatsAppDeviceController;
use App\Http\Controllers\Admin\WhatsAppInboxController;
use App\Http\Controllers\Admin\WhatsAppGroupController;
use App\Http\Controllers\Admin\WhatsAppMessageController;
use App\Http\Controllers\Admin\WhatsAppProviderToolController;
use App\Http\Controllers\Admin\WhatsAppSettingsController;
use App\Http\Controllers\Admin\WhatsAppTemplateController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CrmDocumentProviderController;
use App\Http\Controllers\CustomerOrderController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InquiryController;
use App\Http\Controllers\InquiryTrackingController;
use App\Http\Controllers\KbliController;
use App\Http\Controllers\LegalPageController;
use App\Http\Controllers\LegalToolController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\Partner\ActivationController as PartnerActivationController;
use App\Http\Controllers\Partner\AuthController as PartnerAuthController;
use App\Http\Controllers\Partner\CertificateController as PartnerCertificateController;
use App\Http\Controllers\Partner\DashboardController as PartnerDashboardController;
use App\Http\Controllers\Partner\LearningController as PartnerLearningController;
use App\Http\Controllers\Partner\LearningMaterialController as PartnerLearningMaterialController;
use App\Http\Controllers\Partner\PriceController as PartnerPriceController;
use App\Http\Controllers\PartnerApplicationController;
use App\Http\Controllers\Portal\CommunityAttachmentController;
use App\Http\Controllers\Portal\CommunityController;
use App\Http\Controllers\Portal\InboxController;
use App\Http\Controllers\Portal\InvoiceController as PortalInvoiceController;
use App\Http\Controllers\Portal\PaymentController as PortalPaymentController;
use App\Http\Controllers\Portal\ProfileController as PortalProfileController;
use App\Http\Controllers\PublicInvoiceController;
use App\Http\Controllers\PublicReceiptController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\StarSenderWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/healthz', HealthController::class)->name('healthz');
Route::get('/crm-document/{link}/{token}', CrmDocumentProviderController::class)
    ->middleware(['feature:whatsapp_crm', 'throttle:60,1'])
    ->name('crm.documents.provider-download');
Route::post('/webhooks/starsender/{secret}', StarSenderWebhookController::class)
    ->middleware(['feature:whatsapp_crm', 'throttle:120,1'])
    ->name('webhooks.starsender');
Route::get('/', HomeController::class)->name('home');
Route::get('/layanan', [ServiceController::class, 'index'])->name('services.index');
Route::get('/layanan/{service:slug}', [ServiceController::class, 'show'])->name('services.show');
Route::get('/cek-risiko-kbli', [KbliController::class, 'index'])->name('kbli.index');
Route::get('/cek-risiko-kbli/{code}', [KbliController::class, 'show'])->name('kbli.show');
Route::get('/alat', [LegalToolController::class, 'index'])->name('tools.index');
Route::get('/alat/generator-nama', [LegalToolController::class, 'nameGenerator'])->name('tools.name-generator');
Route::get('/alat/simulasi-akta', [LegalToolController::class, 'deedSimulator'])->name('tools.deed-simulator');
Route::post('/alat/simulasi-akta', [LegalToolController::class, 'simulateDeed'])
    ->middleware('throttle:20,1')
    ->name('tools.deed-simulator.run');
Route::get('/artikel', [ArticleController::class, 'index'])->middleware('feature:public_articles')->name('articles.index');
Route::get('/artikel/{article:slug}', [ArticleController::class, 'show'])->middleware('feature:public_articles')->name('articles.show');
Route::get('/proposal', [InquiryController::class, 'create'])->middleware('feature:public_proposal')->name('proposal.create');
Route::post('/proposal', [InquiryController::class, 'store'])->middleware(['feature:public_proposal', 'throttle:10,1'])->name('proposal.store');
Route::get('/proposal/berhasil/{inquiry:reference}', [InquiryController::class, 'success'])->name('proposal.success');
Route::get('/lacak-permintaan', [InquiryTrackingController::class, 'index'])->name('tracking.index');
Route::post('/lacak-permintaan', [InquiryTrackingController::class, 'search'])->middleware('throttle:8,1')->name('tracking.search');
Route::get('/kemitraan', [PartnerApplicationController::class, 'create'])->middleware('feature:partner_registration')->name('partnership.create');
Route::post('/kemitraan', [PartnerApplicationController::class, 'store'])->middleware(['feature:partner_registration', 'throttle:5,1'])->name('partnership.store');
Route::get('/tagihan/{token}', [PublicInvoiceController::class, 'show'])->middleware('throttle:60,1')->name('invoices.public');
Route::get('/kwitansi/{token}', [PublicReceiptController::class, 'show'])->middleware('throttle:60,1')->name('receipts.public');
Route::get('/pelanggan/order/{token}', [CustomerOrderController::class, 'show'])->middleware(['feature:customer_portal', 'throttle:60,1'])->name('customer.orders.show');
Route::post('/pelanggan/order/{token}/catatan', [CustomerOrderController::class, 'note'])->middleware(['feature:customer_portal', 'throttle:10,1'])->name('customer.orders.note');
Route::post('/pelanggan/order/{token}/dokumen', [CustomerOrderController::class, 'upload'])->middleware(['feature:customer_portal', 'feature:customer_document_upload', 'throttle:10,1'])->name('customer.orders.documents.store');
Route::get('/pelanggan/order/{token}/dokumen/{document}', [CustomerOrderController::class, 'download'])->middleware(['feature:customer_portal', 'throttle:60,1'])->name('customer.orders.documents.download');
Route::get('/kebijakan-privasi', [LegalPageController::class, 'privacy'])->name('legal.privacy');
Route::get('/syarat-ketentuan', [LegalPageController::class, 'terms'])->name('legal.terms');
Route::get('/kontak', ContactController::class)->name('contact');
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/masuk', [AdminAuthController::class, 'create'])->name('login');
    Route::post('/masuk', [AdminAuthController::class, 'store'])->middleware('throttle:5,1')->name('login.store');

    Route::middleware('admin')->group(function (): void {
        Route::get('/', AdminDashboardController::class)->name('dashboard');
        Route::get('/order', [AdminServiceOrderController::class, 'index'])->name('orders.index');
        Route::get('/order/buat', [AdminServiceOrderController::class, 'create'])->name('orders.create');
        Route::post('/order', [AdminServiceOrderController::class, 'store'])->name('orders.store');
        Route::post('/order/sinkronkan', [AdminServiceOrderController::class, 'sync'])->middleware('throttle:3,1')->name('orders.sync');
        Route::post('/order/dari-permintaan/{inquiry}', [AdminServiceOrderController::class, 'fromInquiry'])->name('orders.from-inquiry');
        Route::get('/order/{order}', [AdminServiceOrderController::class, 'show'])->name('orders.show');
        Route::put('/order/{order}', [AdminServiceOrderController::class, 'update'])->name('orders.update');
        Route::post('/order/{order}/portal-token', [AdminServiceOrderController::class, 'resetPortalToken'])->name('orders.portal-token');
        Route::post('/order/{order}/invoice', [AdminServiceOrderController::class, 'attachInvoice'])->name('orders.invoices.attach');
        Route::post('/order/{order}/dokumen', [AdminServiceOrderController::class, 'uploadDocument'])->name('orders.documents.store');
        Route::get('/order/{order}/dokumen/{document}', [AdminServiceOrderController::class, 'downloadDocument'])->name('orders.documents.download');
        Route::delete('/order/{order}/dokumen/{document}', [AdminServiceOrderController::class, 'deleteDocument'])->name('orders.documents.destroy');

        Route::get('/paket', [AdminPackageController::class, 'index'])->name('packages.index');
        Route::put('/paket/{package}', [AdminPackageController::class, 'update'])->name('packages.update');
        Route::get('/permintaan', [AdminInquiryController::class, 'index'])->name('inquiries.index');
        Route::put('/permintaan/{inquiry}', [AdminInquiryController::class, 'update'])->name('inquiries.update');
        Route::get('/mitra', [AdminPartnerController::class, 'index'])->name('partners.index');
        Route::post('/mitra', [AdminPartnerController::class, 'store'])->name('partners.store');
        Route::post('/mitra/permohonan/{application}/setujui', [AdminPartnerController::class, 'approve'])->name('partners.approve');
        Route::post('/mitra/permohonan/{application}/tolak', [AdminPartnerController::class, 'reject'])->name('partners.reject');
        Route::put('/mitra/{partner}/status', [AdminPartnerController::class, 'toggle'])->name('partners.toggle');
        Route::put('/mitra/{partner}', [AdminPartnerController::class, 'update'])->name('partners.update');
        Route::put('/mitra/{partner}/password', [AdminPartnerController::class, 'updatePassword'])->name('partners.password');
        Route::get('/akademi', [AdminAcademyController::class, 'index'])->name('academy.index');
        Route::get('/akademi/laporan', [AdminAcademyController::class, 'report'])->name('academy.report');
        Route::get('/akademi/buat', [AdminAcademyController::class, 'create'])->name('academy.create');
        Route::post('/akademi', [AdminAcademyController::class, 'store'])->name('academy.store');
        Route::get('/akademi/{course}/ubah', [AdminAcademyController::class, 'edit'])->name('academy.edit');
        Route::put('/akademi/{course}', [AdminAcademyController::class, 'update'])->name('academy.update');
        Route::delete('/akademi/{course}', [AdminAcademyController::class, 'destroy'])->name('academy.destroy');
        Route::post('/akademi-kategori', [AdminAcademyController::class, 'storeCategory'])->name('academy.categories.store');
        Route::post('/akademi/{course}/bab', [AdminAcademyController::class, 'storeSection'])->name('academy.sections.store');
        Route::put('/akademi/bab/{section}', [AdminAcademyController::class, 'updateSection'])->name('academy.sections.update');
        Route::post('/akademi/bab/{section}/materi', [AdminAcademyController::class, 'storeLesson'])->name('academy.lessons.store');
        Route::put('/akademi/materi/{lesson}', [AdminAcademyController::class, 'updateLesson'])->name('academy.lessons.update');
        Route::delete('/akademi/materi/{lesson}', [AdminAcademyController::class, 'destroyLesson'])->name('academy.lessons.destroy');
        Route::post('/akademi/{course}/peserta', [AdminAcademyController::class, 'assign'])->name('academy.assign');
        Route::get('/operasional/{module}', [OperationsController::class, 'index'])->name('operations.index');
        Route::post('/operasional/{module}', [OperationsController::class, 'store'])->name('operations.store');
        Route::put('/tiket/{ticket}', [OperationsController::class, 'updateTicket'])->name('tickets.update');
        Route::put('/komisi/{commission}', [OperationsController::class, 'updateCommission'])->name('commissions.update');
        Route::get('/invoice', [PortalInvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoice/buat', [PortalInvoiceController::class, 'create'])->name('invoices.create');
        Route::post('/invoice', [PortalInvoiceController::class, 'store'])->name('invoices.store');
        Route::get('/invoice/{invoice}/ubah', [PortalInvoiceController::class, 'edit'])->name('invoices.edit');
        Route::put('/invoice/{invoice}', [PortalInvoiceController::class, 'update'])->name('invoices.update');
        Route::delete('/invoice/{invoice}', [PortalInvoiceController::class, 'destroy'])->name('invoices.destroy');
        Route::get('/invoice/{invoice}', [PortalInvoiceController::class, 'show'])->name('invoices.show');
        Route::post('/invoice/{invoice}/pembayaran', [PortalPaymentController::class, 'store'])->name('invoices.payments.store');
        Route::put('/invoice/{invoice}/status', [PortalInvoiceController::class, 'updateStatus'])->name('invoices.status');
        Route::post('/invoice/{invoice}/batalkan', [PortalInvoiceController::class, 'cancel'])->name('invoices.cancel');
        Route::post('/invoice/{invoice}/kirim', [PortalInvoiceController::class, 'send'])->name('invoices.send');
        Route::get('/pembayaran/{payment}/ubah', [PortalPaymentController::class, 'edit'])->name('payments.edit');
        Route::put('/pembayaran/{payment}', [PortalPaymentController::class, 'update'])->name('payments.update');
        Route::post('/pembayaran/{payment}/batalkan', [PortalPaymentController::class, 'cancel'])->name('payments.cancel');
        Route::get('/keuangan', [AdminFinanceController::class, 'index'])->name('finance.index');
        Route::post('/keuangan/kategori', [AdminFinanceController::class, 'storeCategory'])->name('finance.categories.store');
        Route::post('/keuangan/pemasukan', [AdminFinanceController::class, 'storeIncome'])->name('finance.incomes.store');
        Route::post('/keuangan/pengeluaran', [AdminFinanceController::class, 'storeExpense'])->name('finance.expenses.store');
        Route::get('/keuangan/ekspor.csv', [AdminFinanceController::class, 'export'])->name('finance.export');
        Route::get('/keuangan/cetak', [AdminFinanceController::class, 'print'])->name('finance.print');
        Route::resource('/artikel', AdminArticleController::class)
            ->middleware('feature:public_articles')
            ->parameters(['artikel' => 'article'])
            ->except('show')
            ->names([
                'index' => 'articles.index',
                'create' => 'articles.create',
                'store' => 'articles.store',
                'edit' => 'articles.edit',
                'update' => 'articles.update',
                'destroy' => 'articles.destroy',
            ]);
        Route::get('/pengaturan-email', [AdminMailSettingController::class, 'edit'])->name('mail.edit');
        Route::put('/pengaturan-email', [AdminMailSettingController::class, 'update'])->name('mail.update');
        Route::post('/pengaturan-email/tes', [AdminMailSettingController::class, 'test'])->name('mail.test');
        Route::post('/pengaturan-email/sender', [AdminMailSettingController::class, 'storeSender'])->name('mail.senders.store');
        Route::put('/pengaturan-email/sender/{sender}', [AdminMailSettingController::class, 'updateSender'])->name('mail.senders.update');
        Route::get('/profil', [PortalProfileController::class, 'edit'])->name('profile.edit');
        Route::put('/profil', [PortalProfileController::class, 'update'])->name('profile.update');
        Route::get('/komunitas', [CommunityController::class, 'index'])->middleware('feature:partner_community')->name('community.index');
        Route::post('/komunitas', [CommunityController::class, 'store'])->middleware('feature:partner_community')->name('community.store');
        Route::get('/komunitas/{post}/lampiran', CommunityAttachmentController::class)->middleware('feature:partner_community')->name('community.attachment');
        Route::post('/komunitas/{post}/komentar', [CommunityController::class, 'comment'])->middleware('feature:partner_community')->name('community.comment');
        Route::get('/inbox', [InboxController::class, 'index'])->middleware('feature:partner_inbox')->name('inbox.index');
        Route::post('/inbox', [InboxController::class, 'store'])->middleware('feature:partner_inbox')->name('inbox.store');
        Route::get('/branding', [AdminBrandingController::class, 'edit'])->name('branding.edit');
        Route::put('/branding', [AdminBrandingController::class, 'update'])->name('branding.update');
        Route::get('/pengaturan-fitur', [AdminFeatureSettingController::class, 'edit'])->name('features.edit');
        Route::put('/pengaturan-fitur', [AdminFeatureSettingController::class, 'update'])->name('features.update');

        Route::prefix('whatsapp')->name('whatsapp.')->middleware('feature:whatsapp_crm')->group(function (): void {
            Route::get('/', WhatsAppDashboardController::class)->name('dashboard');
            Route::get('/pengaturan', [WhatsAppSettingsController::class, 'index'])->name('settings.index');
            Route::post('/pengaturan/pesan-uji', [WhatsAppSettingsController::class, 'testMessage'])->middleware('throttle:10,1')->name('settings.test-message');
            Route::post('/pengaturan/cek-nomor', [WhatsAppSettingsController::class, 'checkNumber'])->middleware('throttle:20,1')->name('settings.check-number');
            Route::post('/pengaturan/consent', [WhatsAppSettingsController::class, 'storeConsent'])->name('settings.consents.store');
            Route::delete('/pengaturan/consent/{consent}', [WhatsAppSettingsController::class, 'revokeConsent'])->name('settings.consents.revoke');

            Route::get('/grup', [WhatsAppGroupController::class, 'index'])->name('groups.index');
            Route::post('/grup/sinkronkan', [WhatsAppGroupController::class, 'sync'])->middleware('throttle:5,1')->name('groups.sync');
            Route::post('/grup/kategori', [WhatsAppGroupController::class, 'savePreset'])->name('groups.presets.save');
            Route::delete('/grup/kategori/{preset}', [WhatsAppGroupController::class, 'deletePreset'])->name('groups.presets.delete');
            Route::post('/grup/kirim-banyak', [WhatsAppGroupController::class, 'sendMany'])->middleware('throttle:10,1')->name('groups.send-many');


            Route::get('/kontak', [WhatsAppContactController::class, 'index'])->name('contacts.index');
            Route::post('/kontak', [WhatsAppContactController::class, 'store'])->name('contacts.store');
            Route::get('/kontak-ekspor', [WhatsAppContactController::class, 'export'])->name('contacts.export');
            Route::post('/kontak-impor', [WhatsAppContactController::class, 'import'])->name('contacts.import');
            Route::get('/kontak/{contact}', [WhatsAppContactController::class, 'show'])->name('contacts.show');
            Route::put('/kontak/{contact}', [WhatsAppContactController::class, 'update'])->name('contacts.update');
            Route::put('/kontak/{contact}/label', [WhatsAppContactController::class, 'labels'])->name('contacts.labels');
            Route::post('/kontak/{contact}/kirim', [WhatsAppContactController::class, 'send'])->middleware('throttle:30,1')->name('contacts.send');
            Route::post('/kontak/{contact}/sequence', [WhatsAppContactController::class, 'enroll'])->name('contacts.sequences.enroll');
            Route::post('/label', [WhatsAppLabelController::class, 'store'])->name('labels.store');
            Route::put('/label/{label}', [WhatsAppLabelController::class, 'update'])->name('labels.update');
            Route::delete('/label/{label}', [WhatsAppLabelController::class, 'destroy'])->name('labels.destroy');

            Route::get('/crm', [WhatsAppLeadController::class, 'index'])->name('leads.index');
            Route::post('/crm', [WhatsAppLeadController::class, 'store'])->name('leads.store');
            Route::put('/crm/{lead}', [WhatsAppLeadController::class, 'update'])->name('leads.update');
            Route::post('/crm/{lead}/order', [WhatsAppLeadController::class, 'createOrder'])->name('leads.orders.store');
            Route::post('/crm/{lead}/persyaratan', [WhatsAppLeadController::class, 'applyRequirements'])->name('leads.requirements.apply');
            Route::post('/crm/{lead}/persyaratan/kirim', [WhatsAppLeadController::class, 'sendRequirements'])->middleware('throttle:20,1')->name('leads.requirements.send');
            Route::put('/persyaratan/{requirement}', [WhatsAppLeadController::class, 'updateRequirement'])->name('requirements.update');

            Route::get('/sequence', [WhatsAppSequenceController::class, 'index'])->name('sequences.index');
            Route::post('/sequence', [WhatsAppSequenceController::class, 'store'])->name('sequences.store');
            Route::get('/sequence/{sequence}', [WhatsAppSequenceController::class, 'show'])->name('sequences.show');
            Route::put('/sequence/{sequence}', [WhatsAppSequenceController::class, 'update'])->name('sequences.update');
            Route::post('/sequence/{sequence}/langkah', [WhatsAppSequenceController::class, 'addStep'])->name('sequences.steps.store');
            Route::delete('/sequence/{sequence}/langkah/{step}', [WhatsAppSequenceController::class, 'deleteStep'])->name('sequences.steps.destroy');
            Route::post('/sequence/{sequence}/target', [WhatsAppSequenceController::class, 'enroll'])->name('sequences.enroll');
            Route::post('/sequence-target/{enrollment}', [WhatsAppSequenceController::class, 'enrollmentAction'])->name('sequences.enrollments.action');

            Route::get('/dokumen', [WhatsAppDocumentController::class, 'index'])->name('documents.index');
            Route::post('/dokumen', [WhatsAppDocumentController::class, 'store'])->name('documents.store');
            Route::post('/dokumen/kirim-banyak', [WhatsAppDocumentController::class, 'sendMany'])->middleware('throttle:10,1')->name('documents.send-many');
            Route::get('/dokumen/{document}/unduh', [WhatsAppDocumentController::class, 'download'])->name('documents.download');
            Route::post('/dokumen/{document}/arsipkan', [WhatsAppDocumentController::class, 'archive'])->name('documents.archive');
            Route::put('/dokumen/{document}', [WhatsAppDocumentController::class, 'update'])->name('documents.update');
            Route::post('/dokumen/{document}/kirim', [WhatsAppDocumentController::class, 'send'])->middleware('throttle:20,1')->name('documents.send');

            Route::get('/faq', [WhatsAppFaqController::class, 'index'])->name('faq.index');
            Route::post('/faq', [WhatsAppFaqController::class, 'store'])->name('faq.store');
            Route::put('/faq/{faq}', [WhatsAppFaqController::class, 'update'])->name('faq.update');
            Route::delete('/faq/{faq}', [WhatsAppFaqController::class, 'destroy'])->name('faq.destroy');

            Route::get('/webhook-monitor', [WhatsAppWebhookMonitorController::class, 'index'])->name('webhooks.index');
            Route::post('/webhook-monitor/{event}/retry', [WhatsAppWebhookMonitorController::class, 'retry'])->name('webhooks.retry');

            Route::get('/pesan', [WhatsAppMessageController::class, 'index'])->name('messages.index');
            Route::post('/pesan', [WhatsAppMessageController::class, 'store'])->middleware('throttle:30,1')->name('messages.store');
            Route::post('/pesan/{message}/coba-lagi', [WhatsAppMessageController::class, 'retry'])->name('messages.retry');
            Route::post('/pesan/{message}/batalkan', [WhatsAppMessageController::class, 'cancel'])->name('messages.cancel');

            Route::get('/template', [WhatsAppTemplateController::class, 'index'])->name('templates.index');
            Route::post('/template', [WhatsAppTemplateController::class, 'store'])->name('templates.store');
            Route::put('/template/{template}', [WhatsAppTemplateController::class, 'update'])->name('templates.update');

            Route::get('/inbox', [WhatsAppInboxController::class, 'index'])->name('inbox.index');
            Route::get('/inbox/{conversation}', [WhatsAppInboxController::class, 'show'])->name('inbox.show');
            Route::post('/inbox/{conversation}/balas', [WhatsAppInboxController::class, 'reply'])->middleware('throttle:30,1')->name('inbox.reply');
            Route::put('/inbox/{conversation}', [WhatsAppInboxController::class, 'update'])->name('inbox.update');
            Route::post('/inbox/{conversation}/ai-blacklist', [WhatsAppInboxController::class, 'aiBlacklist'])->name('inbox.ai-blacklist');

            Route::get('/otomasi', [WhatsAppAutomationController::class, 'index'])->name('automations.index');
            Route::post('/otomasi/kata-kunci', [WhatsAppAutomationController::class, 'storeKeyword'])->name('automations.keywords.store');
            Route::put('/otomasi/{automation}', [WhatsAppAutomationController::class, 'update'])->name('automations.update');

            Route::get('/campaign', [WhatsAppCampaignController::class, 'index'])->name('campaigns.index');
            Route::post('/campaign', [WhatsAppCampaignController::class, 'store'])->name('campaigns.store');
            Route::get('/campaign/{campaign}', [WhatsAppCampaignController::class, 'show'])->name('campaigns.show');
            Route::post('/campaign/{campaign}/jalankan', [WhatsAppCampaignController::class, 'dispatch'])->name('campaigns.dispatch');
            Route::post('/campaign/{campaign}/batalkan', [WhatsAppCampaignController::class, 'cancel'])->name('campaigns.cancel');

            Route::get('/perangkat', [WhatsAppDeviceController::class, 'index'])->name('devices.index');
            Route::post('/perangkat/sinkronkan', [WhatsAppDeviceController::class, 'sync'])->middleware('throttle:5,1')->name('devices.sync');
            Route::post('/perangkat/buat', [WhatsAppDeviceController::class, 'create'])->middleware('throttle:5,1')->name('devices.create');
            Route::put('/perangkat/{device}', [WhatsAppDeviceController::class, 'update'])->name('devices.update');
            Route::post('/perangkat/{device}/relog', [WhatsAppDeviceController::class, 'relog'])->middleware('throttle:5,1')->name('devices.relog');
            Route::delete('/perangkat/{device}', [WhatsAppDeviceController::class, 'delete'])->name('devices.delete');

            Route::get('/alat-provider', [WhatsAppProviderToolController::class, 'index'])->name('provider-tools.index');
            Route::post('/alat-provider/kontak', [WhatsAppProviderToolController::class, 'createContact'])->middleware('throttle:20,1')->name('provider-tools.contacts.store');
            Route::delete('/alat-provider/kontak/grup', [WhatsAppProviderToolController::class, 'removeContactFromGroup'])->middleware('throttle:20,1')->name('provider-tools.contacts.groups.remove');
            Route::post('/alat-provider/kontak/pindah-grup', [WhatsAppProviderToolController::class, 'moveContactGroup'])->middleware('throttle:20,1')->name('provider-tools.contacts.groups.move');
            Route::post('/alat-provider/campaign', [WhatsAppProviderToolController::class, 'createProviderCampaign'])->middleware('throttle:10,1')->name('provider-tools.campaigns.store');
            Route::post('/alat-provider/campaign/anggota', [WhatsAppProviderToolController::class, 'addProviderCampaignMember'])->middleware('throttle:20,1')->name('provider-tools.campaigns.members.store');
            Route::post('/alat-provider/campaign/anggota/pindah', [WhatsAppProviderToolController::class, 'moveProviderCampaignMember'])->middleware('throttle:20,1')->name('provider-tools.campaigns.members.move');
        });
        Route::post('/keluar', [AdminAuthController::class, 'destroy'])->name('logout');
    });
});

Route::prefix('mitra')->name('partner.')->group(function (): void {
    Route::get('/masuk', [PartnerAuthController::class, 'create'])->name('login');
    Route::post('/masuk', [PartnerAuthController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
    Route::get('/aktivasi/{token}', [PartnerActivationController::class, 'create'])->name('activate');
    Route::post('/aktivasi/{token}', [PartnerActivationController::class, 'store'])->name('activate.store');

    Route::middleware('partner')->group(function (): void {
        Route::get('/', PartnerDashboardController::class)->name('dashboard');
        Route::get('/harga', [PartnerPriceController::class, 'index'])->name('prices.index');
        Route::get('/akademi', [PartnerLearningController::class, 'index'])->middleware('feature:partner_academy')->name('learning.index');
        Route::get('/akademi/{course}', [PartnerLearningController::class, 'show'])->middleware('feature:partner_academy')->name('learning.show');
        Route::get('/akademi/{course}/materi/{lesson}/file', PartnerLearningMaterialController::class)->middleware('feature:partner_academy')->name('learning.material');
        Route::post('/akademi/{course}/materi/{lesson}/selesai', [PartnerLearningController::class, 'complete'])->middleware('feature:partner_academy')->name('learning.complete');
        Route::get('/sertifikat/{enrollment}', PartnerCertificateController::class)->middleware('feature:partner_academy')->name('learning.certificate');
        Route::get('/operasional/{module}', [OperationsController::class, 'index'])->name('operations.index');
        Route::post('/operasional/{module}', [OperationsController::class, 'store'])->name('operations.store');
        Route::get('/invoice', [PortalInvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoice/buat', [PortalInvoiceController::class, 'create'])->name('invoices.create');
        Route::post('/invoice', [PortalInvoiceController::class, 'store'])->name('invoices.store');
        Route::get('/invoice/{invoice}/ubah', [PortalInvoiceController::class, 'edit'])->name('invoices.edit');
        Route::put('/invoice/{invoice}', [PortalInvoiceController::class, 'update'])->name('invoices.update');
        Route::delete('/invoice/{invoice}', [PortalInvoiceController::class, 'destroy'])->name('invoices.destroy');
        Route::get('/invoice/{invoice}', [PortalInvoiceController::class, 'show'])->name('invoices.show');
        Route::put('/invoice/{invoice}/status', [PortalInvoiceController::class, 'updateStatus'])->name('invoices.status');
        Route::post('/invoice/{invoice}/batalkan', [PortalInvoiceController::class, 'cancel'])->name('invoices.cancel');
        Route::post('/invoice/{invoice}/kirim', [PortalInvoiceController::class, 'send'])->name('invoices.send');
        Route::get('/profil', [PortalProfileController::class, 'edit'])->name('profile.edit');
        Route::put('/profil', [PortalProfileController::class, 'update'])->name('profile.update');
        Route::get('/komunitas', [CommunityController::class, 'index'])->middleware('feature:partner_community')->name('community.index');
        Route::post('/komunitas', [CommunityController::class, 'store'])->middleware('feature:partner_community')->name('community.store');
        Route::get('/komunitas/{post}/lampiran', CommunityAttachmentController::class)->middleware('feature:partner_community')->name('community.attachment');
        Route::post('/komunitas/{post}/komentar', [CommunityController::class, 'comment'])->middleware('feature:partner_community')->name('community.comment');
        Route::get('/inbox', [InboxController::class, 'index'])->middleware('feature:partner_inbox')->name('inbox.index');
        Route::post('/inbox', [InboxController::class, 'store'])->middleware('feature:partner_inbox')->name('inbox.store');
        Route::post('/keluar', [PartnerAuthController::class, 'destroy'])->name('logout');
    });
});
