<!doctype html>
<html lang="id">
<body style="margin:0;background:#f2f6f5;font-family:Arial,sans-serif;color:#11233a">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td style="padding:32px 16px">
<table role="presentation" width="620" align="center" cellspacing="0" cellpadding="0" style="max-width:100%;background:#fff;border-radius:16px;overflow:hidden">
<tr><td style="padding:28px;background:#07192f;color:#fff"><strong style="font-size:22px">Mitra LegaOne · IzinHukum</strong></td></tr>
<tr><td style="padding:32px">
<p>Halo {{ $partner->name }},</p>
<p>Akun mitra dengan kode <strong>{{ $partner->partner_code }}</strong> telah disetujui. Buat kata sandi melalui tautan berikut. Tautan berlaku selama 7 hari.</p>
<p style="margin:28px 0"><a href="{{ $activationUrl }}" style="display:inline-block;padding:13px 20px;background:#0c8d79;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold">Aktifkan akun mitra</a></p>
<p style="color:#647487;font-size:13px">Jangan meneruskan tautan aktivasi ini kepada pihak lain.</p>
</td></tr>
</table>
</td></tr></table>
</body>
</html>
