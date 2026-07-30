<!doctype html>
<html lang="id">
<body style="margin:0;background:#f2f6f5;font-family:Arial,sans-serif;color:#11233a">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td style="padding:32px 16px">
<table role="presentation" width="620" align="center" cellspacing="0" cellpadding="0" style="max-width:100%;background:#fff;border-radius:16px;overflow:hidden">
<tr><td style="padding:28px;background:#07192f;color:#fff"><strong style="font-size:22px">Pesanan baru IzinHukum</strong><br><span style="opacity:.72">{{ $inquiry->reference }}</span></td></tr>
<tr><td style="padding:32px">
<p>Pesanan baru masuk dari landing page dan sudah tercatat sebagai order.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="7" style="border-collapse:collapse">
<tr><td style="color:#647487;width:150px">Pelanggan</td><td><strong>{{ $inquiry->name }}</strong></td></tr>
<tr><td style="color:#647487">Layanan</td><td>{{ $inquiry->package?->service?->name ?: ($inquiry->package?->name ?: 'Konsultasi legalitas') }}</td></tr>
<tr><td style="color:#647487">WhatsApp</td><td>{{ $inquiry->phone }}</td></tr>
<tr><td style="color:#647487">Email</td><td>{{ $inquiry->email ?: 'Tidak diisi' }}</td></tr>
<tr><td style="color:#647487">Perusahaan</td><td>{{ $inquiry->company_name ?: 'Tidak diisi' }}</td></tr>
<tr><td style="color:#647487">Kota</td><td>{{ $inquiry->city ?: 'Tidak diisi' }}</td></tr>
</table>
@if($inquiry->message)
<p style="margin-top:24px"><strong>Catatan pelanggan</strong><br>{{ $inquiry->message }}</p>
@endif
@if($inquiry->serviceOrder)
<p style="margin:28px 0"><a href="{{ route('admin.orders.show', $inquiry->serviceOrder) }}" style="display:inline-block;padding:13px 20px;background:#0c8d79;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold">Buka order di admin</a></p>
@endif
<p style="color:#647487;font-size:13px">Email ini merupakan notifikasi transaksi, bukan email marketing.</p>
</td></tr>
</table>
</td></tr></table>
</body>
</html>
