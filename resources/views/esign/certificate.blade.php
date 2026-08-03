{{-- شهادة إتمام التوقيع (v2.122) — من سجل الأدلة append-only بسلسلة تجزئته الجارية.
     تُعرض داخلياً (esign.cert) وللموقّع عبر رابطه المفتوح (sign.cert، $client) --}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>شهادة إتمام — {{ $req->title }}</title>
@include('partials.standalone_head')
<style>@media print{.noprint{display:none!important}body{background:#fff!important}.card{box-shadow:none!important;border:0!important}}</style>
</head>
<body class="pagedoc">
<div style="max-width:820px;margin:0 auto">
    <div class="noprint" style="display:flex;gap:8px;margin-bottom:12px">
        @unless ($client ?? false)<a class="btn ghost sm" href="{{ route('esign.index') }}">→ رجوع</a>@endunless
        <button class="btn sm" onclick="window.print()">🖨️ طباعة / حفظ PDF</button>
    </div>
    <div class="card">
        <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;border-bottom:2px solid var(--ln);padding-bottom:12px;margin-bottom:14px">
            <div>
                <div class="sub">شهادة إتمام التوقيع الإلكتروني</div>
                <h2 style="margin:2px 0 6px">{{ $req->title }}</h2>
                <div class="sub">رمز التحقق <b class="mono ltr">{{ $req->verify_code }}</b>
                    @if ($req->contract_id && ($c = \App\Models\Contract::find($req->contract_id)))
                        · العقد <b class="mono ltr">{{ $c->doc_no }}</b>
                    @endif
                </div>
            </div>
            @if ($qr)
                <div style="text-align:center">
                    <div style="background:#fff;padding:6px;border-radius:10px;border:1px solid var(--ln);display:inline-block">{!! $qr !!}</div>
                    <div class="sub" style="font-size:10px;margin-top:2px">امسح للتحقق</div>
                </div>
            @endif
        </div>

        <table class="mini" style="margin-bottom:14px">
            <tr><td>اكتمل التوقيع في</td><td class="mono">{{ $req->signed_at?->format('Y-m-d H:i:s') }}</td></tr>
            <tr><td>نمط التوقيع</td><td>{{ $req->mode ?: 'مفرد' }} — {{ $signers->where('role', 'موقّع')->count() }} موقّع</td></tr>
            <tr><td>بصمة الوثيقة SHA-256</td><td class="mono ltr" style="font-size:9.5px;word-break:break-all">{{ $req->doc_hash }}</td></tr>
            <tr><td>رأس سلسلة الأدلة المجمّد</td><td class="mono ltr" style="font-size:9.5px;word-break:break-all">{{ $req->evidence_hash ?: $head }}</td></tr>
        </table>

        <h3>الأطراف</h3>
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>الاسم</th><th>الدور</th><th>الحالة</th><th>وقّع في</th><th>IP</th></tr></thead>
            <tbody>
            @foreach ($signers as $s)
                <tr>
                    <td><b>{{ $s->name }}</b>@if ($s->id_no)<div class="sub mono ltr" style="font-size:10px">{{ $s->id_no }}</div>@endif</td>
                    <td>{{ $s->role }}</td>
                    <td><span class="bdg {{ $s->status === 'وُقّع' ? 'ok' : ($s->status === 'رُفض' ? 'bad' : 'wn') }}">{{ $s->status }}</span></td>
                    <td class="mono sub">{{ $s->signed_at?->format('Y-m-d H:i:s') ?: '—' }}</td>
                    <td class="mono ltr sub">{{ $s->ip ?: '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>

        <h3 style="margin:16px 0 6px">سجل الأدلة الكامل</h3>
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>الوقت</th><th>الحدث</th><th>IP</th><th>حلقة السلسلة</th></tr></thead>
            <tbody>
            @foreach ($chain as $row)
                <tr>
                    <td class="mono sub">{{ $row['e']->created_at }}</td>
                    <td>{{ \App\Support\Evidence::label($row['e']->event) }}</td>
                    <td class="mono ltr sub">{{ $row['e']->ip ?: '—' }}</td>
                    <td class="mono ltr sub" style="font-size:9.5px">{{ substr($row['hash'], 0, 16) }}…</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>

        <div class="sub" style="margin-top:12px;font-size:11px;line-height:1.8">
            كل حلقةٍ تُجزّأ فوق سابقتها وفوق بصمة الوثيقة (SHA-256) — أي عبثٍ بحدثٍ ماضٍ يكسر كل ما بعده.
            رأس السلسلة أعلاه جُمّد لحظة اكتمال التوقيع؛ الأحداث اللاحقة (كالتنزيلات) تمدّ السلسلة ولا تمسّ ما جُمّد.
            <br>للتحقق من أصالة الوثيقة: <span class="mono ltr">{{ route('sign.verify') }}</span> — الرمز <b class="mono ltr">{{ $req->verify_code }}</b>
        </div>
    </div>
</div>
</body>
</html>
