<?php // Gaya UI checkout bersama (radio premium + strip). Di-include sekali per halaman
      // checkout (template login lewat checkout.php, publik lewat public-checkout.php). ?>
<style>
/* Radio premium: cincin outline → saat dipilih terisi warna brand + titik putih.
   Dipakai semua radio checkout (metode bayar + cara bayar/booking) via .checkout-radio. */
.checkout-radio{
  -webkit-appearance:none;appearance:none;
  width:1.15rem;height:1.15rem;flex:0 0 auto;margin:0;
  border:2px solid #cbd5e1;border-radius:9999px;background:#fff;
  cursor:pointer;vertical-align:middle;
  transition:border-color .15s ease, background .15s ease, box-shadow .15s ease;
}
.checkout-radio:hover{border-color:var(--brand-color);}
.checkout-radio:checked{
  border-color:var(--brand-color);
  background:radial-gradient(circle, var(--brand-color) 0 42%, #fff 47% 100%);
}
.checkout-radio:focus-visible{
  outline:none;
  box-shadow:0 0 0 3px color-mix(in srgb, var(--brand-color) 30%, transparent);
}
.dark .checkout-radio{border-color:#475569;background:#0f172a;}
.dark .checkout-radio:checked{
  background:radial-gradient(circle, var(--brand-color) 0 42%, #0f172a 47% 100%);
}
/* Strip "metode diterima": chip ikon netral monokrom. */
.pay-chip{
  display:inline-flex;align-items:center;justify-content:center;
  width:2.4rem;height:1.55rem;border-radius:.4rem;
  border:1px solid #e5e7eb;background:#f9fafb;color:#64748b;
}
.dark .pay-chip{border-color:#374151;background:#1f2937;color:#94a3b8;}
.pay-chip svg{width:1.05rem;height:1.05rem;}
</style>
