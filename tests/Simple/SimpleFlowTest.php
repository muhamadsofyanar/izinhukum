<?php
namespace Tests\Simple;
use Tests\TestCase;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\ServiceOrder;
use App\Services\FeatureFlagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
class SimpleFlowTest extends TestCase
{
    use RefreshDatabase;
    public function test_old_features_cannot_be_reactivated_by_database_settings(): void
    {
        \App\Models\SystemSetting::storeValue('feature_whatsapp_transactional', '1');
        $this->assertFalse(app(FeatureFlagService::class)->enabled('whatsapp_transactional'));
        $this->get('/mitra/masuk')->assertNotFound();
        $this->get('/alat')->assertNotFound();
        $this->get('/admin/kupon')->assertNotFound();
    }
    public function test_checkout_records_order_without_jobs_or_invoice(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $service = Service::create(['name'=>'PT','short_name'=>'PT','summary'=>'Pendirian PT','slug'=>'pt-test','category'=>'Badan usaha','is_active'=>true]);
        $package = ServicePackage::create(['service_id'=>$service->id,'name'=>'Paket PT','price'=>3000000,'features'=>['Akta'],'is_active'=>true]);
        $this->post('/checkout', ['service_package_id'=>$package->id,'name'=>'Klien','phone'=>'081234567890','privacy_consent'=>'1'])->assertRedirect();
        $this->assertDatabaseHas('service_orders', ['customer_name'=>'Klien','service_package_id'=>$package->id,'status'=>'lead']);
        $this->assertDatabaseCount('invoices',0);
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
        $order = ServiceOrder::firstOrFail();
        $this->get(route('proposal.success',$order->inquiry))->assertOk()->assertSee('Lanjutkan ke WhatsApp');
        $this->flushSession();
        $this->get(route('proposal.success',$order->inquiry))->assertNotFound();
    }
    public function test_inactive_package_cannot_be_ordered(): void
    {
        $service = Service::create(['name'=>'PT','short_name'=>'PT','summary'=>'Pendirian PT','slug'=>'pt-inactive','category'=>'Badan usaha','is_active'=>true]);
        $package = ServicePackage::create(['service_id'=>$service->id,'name'=>'Nonaktif','price'=>3000000,'features'=>['Akta'],'is_active'=>false]);
        $this->post('/checkout',['service_package_id'=>$package->id,'name'=>'Klien','phone'=>'081234567890','privacy_consent'=>'1'])->assertNotFound();
        $this->assertDatabaseCount('service_orders',0);
    }
    public function test_public_price_save_preserves_hidden_historical_prices(): void
    {
        $admin = \App\Models\User::create(['role'=>'admin','name'=>'Admin','email'=>'simple-admin@example.test','password'=>'secret-password','is_active'=>true]);
        $service = Service::create(['name'=>'PT','short_name'=>'PT','summary'=>'PT','slug'=>'pt-price','category'=>'Badan usaha','is_active'=>true]);
        $package = ServicePackage::create(['service_id'=>$service->id,'name'=>'Paket','price'=>3000000,'original_price'=>4000000,'minimum_end_user_price'=>2500000,'partner_price'=>2000000,'features'=>[],'is_active'=>true]);
        $this->withSession(['portal_user_id'=>$admin->id])->put(route('admin.packages.update',$package),['price'=>1000000,'is_active'=>'1'])->assertSessionHasNoErrors();
        $package->refresh();
        $this->assertEquals(4000000,$package->original_price);
        $this->assertEquals(2500000,$package->minimum_end_user_price);
        $this->assertEquals(2000000,$package->partner_price);
        $this->get(route('admin.packages.index'))->assertOk();
    }
    public function test_manual_payment_does_not_create_a_new_partner_commission(): void
    {
        $admin = \App\Models\User::create(['role'=>'admin','name'=>'Admin','email'=>'simple-pay@example.test','password'=>'secret-password','is_active'=>true]);
        $partner = \App\Models\User::create(['role'=>'partner','partner_level'=>'priority','name'=>'Mitra','email'=>'old-partner@example.test','password'=>'secret-password','is_active'=>true]);
        $invoice = \App\Models\Invoice::create(['invoice_number'=>'INV-SIMPLE','public_token'=>str_repeat('Q',56),'created_by'=>$admin->id,'referred_by_partner_id'=>$partner->id,'recipient_type'=>'end_user','recipient_name'=>'Klien','issue_date'=>'2026-10-13','status'=>'sent','subtotal'=>2000000,'total'=>2000000,'sent_at'=>now()]);
        $this->withSession(['portal_user_id'=>$admin->id])->post(route('admin.invoices.payments.store',$invoice),['payment_date'=>'2026-10-13','amount'=>1000000,'payment_method'=>'transfer'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payments',1);
        $this->assertDatabaseCount('commissions',0);
    }

    public function test_core_pages_render_without_legacy_route_dependencies(): void
    {
        $this->seed(\Database\Seeders\ServiceSeeder::class);
        foreach (['/','/layanan','/checkout','/kontak','/sitemap.xml','/admin/masuk'] as $path) {
            $this->get($path)->assertOk();
        }
        $service = Service::where('is_active',true)->firstOrFail();
        $this->get(route('services.show',$service))->assertOk()->assertSee('Pilih paket');
        $admin = \App\Models\User::create(['role'=>'admin','name'=>'Admin','email'=>'simple-pages@example.test','password'=>'secret-password','is_active'=>true]);
        foreach (['/admin','/admin/order','/admin/order/buat','/admin/paket','/admin/landing-layanan','/admin/invoice','/admin/invoice/buat','/admin/keuangan','/admin/branding','/admin/profil'] as $path) {
            $this->withSession(['portal_user_id'=>$admin->id])->get($path)->assertOk();
        }
    }

    public function test_checkout_order_can_be_invoiced_at_the_manually_agreed_price(): void
    {
        $admin = \App\Models\User::create(['role'=>'admin','name'=>'Admin','email'=>'simple-invoice@example.test','password'=>'secret-password','is_active'=>true]);
        $service = Service::create(['name'=>'PT','short_name'=>'PT','summary'=>'PT','slug'=>'pt-invoice','category'=>'Badan usaha','is_active'=>true]);
        $package = ServicePackage::create(['service_id'=>$service->id,'name'=>'Paket','price'=>3000000,'minimum_end_user_price'=>2500000,'features'=>[],'is_active'=>true]);
        $this->post('/checkout',['service_package_id'=>$package->id,'name'=>'Klien','phone'=>'081234567891','privacy_consent'=>'1'])->assertRedirect();
        $order = ServiceOrder::firstOrFail();
        $this->withSession(['portal_user_id'=>$admin->id])->post(route('admin.invoices.store'),[
            'inquiry_id'=>$order->inquiry_id,'recipient_name'=>'Klien','recipient_phone'=>'081234567891','issue_date'=>'2026-10-13',
            'items'=>[['service_package_id'=>$package->id,'quantity'=>1,'unit_price'=>1000000]],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $invoice = \App\Models\Invoice::firstOrFail();
        $this->assertEquals($order->id,$invoice->service_order_id);
        $this->assertEquals(1000000,$invoice->total);
        $this->withSession(['portal_user_id'=>$admin->id])->get(route('admin.orders.show',$order))->assertOk();
        $this->withSession(['portal_user_id'=>$admin->id])->get(route('admin.invoices.show',$invoice))->assertOk()->assertSee('Bagikan invoice via WhatsApp');
        $this->get(route('invoices.public',$invoice->public_token))->assertOk();
    }
    public function test_admin_host_is_separate_from_public_host(): void
    {
        config(['simple.public_host'=>'izinhukum.test','simple.admin_host'=>'admin.izinhukum.test']);
        $this->get('https://izinhukum.test/admin/masuk')->assertNotFound();
        $this->get('https://admin.izinhukum.test/admin/masuk')->assertOk();
        $this->get('https://admin.izinhukum.test/checkout')->assertNotFound();
    }

    public function test_non_numeric_phone_is_rejected(): void
    {
        $service = Service::create(['name'=>'PT','short_name'=>'PT','summary'=>'PT','slug'=>'pt-invalid-phone','category'=>'Badan usaha','is_active'=>true]);
        $package = ServicePackage::create(['service_id'=>$service->id,'name'=>'Paket','price'=>3000000,'features'=>[],'is_active'=>true]);
        $this->post('/checkout',['service_package_id'=>$package->id,'name'=>'Klien','phone'=>'abc','privacy_consent'=>'1'])->assertSessionHasErrors('phone');
        $this->assertDatabaseCount('service_orders',0);
    }
    public function test_invalid_legacy_phone_does_not_break_invoice_display(): void
    {
        $admin = \App\Models\User::create(['role'=>'admin','name'=>'Admin','email'=>'phone-display@example.test','password'=>'secret-password','is_active'=>true]);
        $invoice = \App\Models\Invoice::create(['invoice_number'=>'INV-PHONE','public_token'=>str_repeat('W',56),'created_by'=>$admin->id,'recipient_type'=>'end_user','recipient_name'=>'Klien','recipient_phone'=>'123','issue_date'=>'2026-10-13','status'=>'sent','total'=>2000000]);
        $this->withSession(['portal_user_id'=>$admin->id])->get(route('admin.invoices.show',$invoice))->assertOk();
    }

}
