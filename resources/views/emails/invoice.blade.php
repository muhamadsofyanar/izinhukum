<!doctype html>
<html lang="id">
<body style="margin:0;background:#f2f6f5;font-family:Arial,sans-serif;color:#11233a">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td style="padding:32px 16px">
<table role="presentation" width="620" align="center" cellspacing="0" cellpadding="0" style="max-width:100%;background:#fff;border-radius:16px;overflow:hidden">
<tr><td style="padding:28px;background:#07192f;color:#fff"><strong style="font-size:22px">{{ $branding['name'] }}</strong><br><span style="opacity:.72">{{ $branding['tagline'] }}</span></td></tr>
<tr><td style="padding:32px">
<p>Yth. {{ $invoice->recipient_name }},</p>
<p>Invoice <strong>{{ $invoice->invoice_number }}</strong> dengan total <strong>{{ $invoice->formattedTotal() }}</strong> telah diterbitkan.</p>
<p style="margin:28px 0"><a href="{{ route('invoices.public', $invoice->public_token) }}" style="display:inline-block;padding:13px 20px;background:#0c8d79;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold">Lihat invoice</a></p>
<p>Pembayaran resmi: {{ $branding['bank_name'] }} {{ $branding['bank_account_number'] }} a.n. {{ $branding['bank_account_holder'] }}.</p>
<p style="color:#647487;font-size:13px">Abaikan email ini jika invoice telah dibatalkan oleh pihak penerbit.</p>
</td></tr>
</table>
</td></tr></table>
</body>
</html>
