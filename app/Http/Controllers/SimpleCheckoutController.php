<?php
namespace App\Http\Controllers;
use App\Models\Inquiry;
use App\Models\ServiceOrder;
use App\Models\ServicePackage;
use App\Services\InquiryScreeningService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
class SimpleCheckoutController extends Controller
{
    public function create(Request $request): View
    {
        $packages = ServicePackage::query()->with('service')->where('is_active', true)
            ->whereHas('service', fn ($q) => $q->where('is_active', true))->orderBy('service_id')->orderBy('sort_order')->get();
        return view('simple.checkout', ['packages'=>$packages, 'selectedPackage'=>$request->integer('paket')]);
    }
    public function store(Request $request, InquiryScreeningService $screening): RedirectResponse
    {
        $data = $request->validate([
            'service_package_id'=>['required','integer'], 'name'=>['required','string','max:120'],
            'phone'=>['required','string','max:32'], 'email'=>['nullable','email','max:160'],
            'company_name'=>['nullable','string','max:160'], 'city'=>['nullable','string','max:120'],
            'message'=>['nullable','string','max:3000'], 'privacy_consent'=>['accepted'],
            'website'=>['nullable','string','max:255'], 'form_started_at'=>['nullable','integer'],
        ]);
        $package = ServicePackage::query()->with('service')->where('is_active',true)
            ->whereHas('service',fn ($q)=>$q->where('is_active',true))->findOrFail($data['service_package_id']);
        try { $result = $screening->screen($data); }
        catch (\InvalidArgumentException $e) { throw ValidationException::withMessages(['phone'=>$e->getMessage()]); }
        if ($result['phone_normalized'] === '') {
            throw ValidationException::withMessages(['phone'=>'Nomor WhatsApp wajib valid. Gunakan format 08xx atau 628xx.']);
        }
        if (in_array($result['disposition'], [Inquiry::DISPOSITION_SPAM, Inquiry::DISPOSITION_DUPLICATE], true)) {
            throw ValidationException::withMessages(['phone'=>'Permintaan belum dapat dicatat. Jika sudah pernah memesan, hubungi admin melalui WhatsApp.']);
        }
        unset($data['privacy_consent'], $data['website'], $data['form_started_at']);
        $inquiry = DB::transaction(function () use ($data,$result,$package): Inquiry {
            $inquiry = Inquiry::create([...$data,...$result,'reference'=>'IH-'.now()->format('ymd').'-'.Str::upper(Str::random(16)),'source'=>'website','status'=>'baru']);
            ServiceOrder::create([
                'inquiry_id'=>$inquiry->id,'service_package_id'=>$package->id,
                'order_number'=>'ORD-'.now()->format('ymd').'-'.$inquiry->id,'public_token'=>Str::random(64),
                'title'=>$package->service->name.' · '.$package->name,'customer_name'=>$inquiry->name,
                'customer_phone'=>$result['phone_normalized'],'customer_email'=>$inquiry->email,
                'customer_company'=>$inquiry->company_name,'customer_city'=>$inquiry->city,
                'description'=>$inquiry->message,'status'=>'lead','payment_status'=>'unpaid','priority'=>'normal','progress'=>0,
                'checklist'=>[['label'=>'Harga dan lingkup disepakati melalui WhatsApp','done'=>false],['label'=>'Dokumen lengkap','done'=>false],['label'=>'Pekerjaan selesai','done'=>false]],
            ]);
            return $inquiry;
        });
        $request->session()->put('checkout_inquiry_id',$inquiry->id);
        return redirect()->route('proposal.success',$inquiry);
    }
    public function success(Request $request, Inquiry $inquiry): View
    {
        abort_unless((int)$request->session()->get('checkout_inquiry_id') === $inquiry->id,404);
        $inquiry->load(['package.service','serviceOrder']);
        $text = implode("\n",['Halo IzinHukum, saya ingin melanjutkan pesanan.','Nomor: '.$inquiry->serviceOrder?->order_number,'Nama: '.$inquiry->name,'Layanan: '.$inquiry->package?->service?->name,'Paket: '.$inquiry->package?->name,'Mohon konfirmasi harga, persyaratan, dan pembayaran.']);
        $whatsappUrl = 'https://wa.me/'.preg_replace('/\D/','',(string)config('company.whatsapp')).'?text='.rawurlencode($text);
        return view('simple.success',compact('inquiry','whatsappUrl'));
    }
}
