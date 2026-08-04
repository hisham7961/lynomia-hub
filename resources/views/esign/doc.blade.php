{{-- الوثيقة النهائية (A4 للطباعة/حفظ PDF) — النص + التوقيع + شهادة الأثر الكاملة.
     تُعرض داخلياً (esign.doc) وللعميل عبر رابطه المفتوح ($client) --}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $req->title }}</title>
@include('partials.standalone_head')
<style>@media print{.noprint{display:none!important}body{background:#fff!important}.card{box-shadow:none!important;border:0!important}}</style>
</head>
<body class="pagedoc">
<div style="max-width:800px;margin:0 auto">
    <div class="noprint" style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
        @if ($public ?? false)
            {{-- فُتحت عبر QR بلا حساب: طباعةٌ وتفاصيلُ تحقّقٍ فقط، لا أزرارٌ داخلية --}}
            <button class="btn sm" onclick="window.print()">🖨️ طباعة / حفظ PDF</button>
            <a class="btn ghost sm" href="{{ route('sign.verify') }}?code={{ $req->verify_code }}">🔎 تفاصيل التحقّق</a>
        @else
            @unless ($client ?? false)<a class="btn ghost sm" href="{{ route('esign.index') }}">→ رجوع</a>@endunless
            <button class="btn sm" onclick="window.print()">🖨️ طباعة / حفظ PDF</button>
            @if (($client ?? false) && ($token ?? null))
                <a class="btn ghost sm" href="{{ route('sign.pdf', $token) }}">⬇️ تنزيل PDF</a>
                @if ($req->status === 'وُقّع')<a class="btn ghost sm" href="{{ route('sign.cert', $token) }}">📜 شهادة الإتمام</a>@endif
            @elseif (! ($client ?? false))
                <a class="btn ghost sm" href="{{ route('esign.pdf', $req->id) }}">⬇️ تنزيل PDF</a>
                @if ($req->status === 'وُقّع')<a class="btn ghost sm" href="{{ route('esign.cert', $req->id) }}">📜 شهادة الإتمام</a>@endif
            @endif
        @endif
    </div>
    @if ($public ?? false)
        <div class="card" style="border-inline-start:4px solid var(--ok);background:rgba(39,174,96,.06);margin-bottom:12px">
            <b>✅ وثيقة موقّعة أصلية</b> — فُتحت والتُحقِّق منها عبر رمز التحقّق <b class="mono ltr">{{ $req->verify_code }}</b>. للموقّعين وبصمة الوثيقة الكاملة:
            <a href="{{ route('sign.verify') }}?code={{ $req->verify_code }}">صفحة التحقّق التفصيلية</a>.
        </div>
    @endif
    <div class="card">
        @php $vdoc = ($req->status === 'وُقّع' && $req->verify_code)
            ? \App\Support\Qr::svg(route('sign.verify.doc', $req->verify_code), 96) : null; @endphp
        <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;border-bottom:2px solid var(--ln);padding-bottom:10px;margin-bottom:14px">
            <h2>{{ $req->title }}</h2>
            <div style="text-align:left">
                <div class="sub">رمز التحقق</div>
                <b class="mono ltr">{{ $req->verify_code }}</b>
                @if ($vdoc)
                    {{-- QR على متن الوثيقة: مسحُه يفتحها ويتحقّق منها مباشرةً --}}
                    <div style="margin-top:8px;background:#fff;padding:5px;border-radius:8px;border:1px solid var(--ln);display:inline-block" title="امسح لفتح الوثيقة والتحقّق مباشرةً">{!! $vdoc !!}</div>
                    <div class="sub" style="font-size:10px;margin-top:3px;max-width:110px;line-height:1.5">امسح لفتح الوثيقة والتحقّق</div>
                @endif
            </div>
        </div>
        <div style="white-space:pre-wrap;line-height:2;font-size:14.5px">{{ $req->body }}</div>

        @if (($sgs ?? collect())->isNotEmpty())
            {{-- v2.122: موقّعون مستقلون (م4) — توقيع كل واحد بأثره، والقديم على كتلته أدناه --}}
            @foreach ($sgs as $s)
                <div style="margin-top:26px;border-top:2px solid var(--ln);padding-top:16px">
                    <b>توقيع: {{ $s->name }} ({{ $s->role }})</b><br>
                    <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start;margin:8px 0">
                        @if (str_starts_with((string) $s->signature, 'data:image/png'))
                            <img src="{{ $s->signature }}" alt="التوقيع" style="max-width:300px;border:1px solid var(--ln);border-radius:10px;background:#fff">
                        @endif
                        {{-- العرضُ العامّ عبر QR يحجب صورة السيلفي كما تحجبها صفحةُ التحقّق --}}
                        @if ($s->selfie && ! ($public ?? false))
                            <img src="{{ $s->selfie }}" alt="صورة التحقق" style="max-width:140px;border:1px solid var(--ln);border-radius:10px">
                        @endif
                    </div>
                    <table class="mini">
                        <tr><td>وقت التوقيع</td><td class="mono">{{ $s->signed_at?->format('Y-m-d H:i:s') }}</td></tr>
                        @unless ($public ?? false)
                            {{-- بياناتٌ حسّاسة (هوية/IP/جهاز) لا تُكشف في الفتح العامّ بالرمز --}}
                            @if ($s->id_no)<tr><td>رقم الهوية/السجل</td><td class="mono ltr">{{ $s->id_no }}</td></tr>@endif
                            <tr><td>عنوان الشبكة IP</td><td class="mono ltr">{{ $s->ip }}</td></tr>
                            <tr><td>الجهاز</td><td class="mono ltr" style="font-size:10.5px">{{ $s->agent }}</td></tr>
                        @endunless
                    </table>
                </div>
            @endforeach
            <div style="margin-top:14px">
                <table class="mini">
                    <tr><td>بصمة الوثيقة SHA-256</td><td class="mono ltr" style="font-size:9.5px;word-break:break-all">{{ $req->doc_hash }}</td></tr>
                </table>
                <div class="sub" style="margin-top:8px">للتحقّق من أصالة هذه الوثيقة أو فتحها مباشرةً: امسح رمز QR أعلاها، أو افتح {{ route('sign.verify.doc', $req->verify_code) }} — أو أدخل الرمز {{ $req->verify_code }} في {{ route('sign.verify') }}</div>
            </div>
        @elseif ($req->status === 'وُقّع')
            <div style="margin-top:26px;border-top:2px solid var(--ln);padding-top:16px">
                <b>توقيع الطرف الثاني:</b><br>
                <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start;margin:8px 0">
                    <img src="{{ $req->signature }}" alt="التوقيع" style="max-width:300px;border:1px solid var(--ln);border-radius:10px;background:#fff">
                    @if ($req->selfie && ! ($public ?? false))
                        <img src="{{ $req->selfie }}" alt="صورة التحقق" style="max-width:140px;border:1px solid var(--ln);border-radius:10px">
                    @endif
                </div>
                <table class="mini">
                    <tr><td>الاسم</td><td><b>{{ $req->signer_name }}</b></td></tr>
                    <tr><td>وقت التوقيع</td><td class="mono">{{ $req->signed_at?->format('Y-m-d H:i:s') }}</td></tr>
                    @unless ($public ?? false)
                        {{-- بياناتٌ حسّاسة (هوية/IP/جهاز/فتحات) لا تُكشف في الفتح العامّ بالرمز --}}
                        @if ($req->signer_id_no)<tr><td>رقم الهوية/السجل</td><td class="mono ltr">{{ $req->signer_id_no }}</td></tr>@endif
                        <tr><td>عنوان الشبكة IP</td><td class="mono ltr">{{ $req->signed_ip }}</td></tr>
                        <tr><td>الجهاز</td><td class="mono ltr" style="font-size:10.5px">{{ $req->signed_agent }}</td></tr>
                        <tr><td>لغة الجهاز</td><td class="mono ltr">{{ $req->signed_locale }}</td></tr>
                        <tr><td>مرات الفتح</td><td class="mono">{{ $req->opens }} — أول فتح {{ $req->opened_at?->format('Y-m-d H:i') }}</td></tr>
                    @endunless
                    <tr><td>بصمة الوثيقة SHA-256</td><td class="mono ltr" style="font-size:9.5px;word-break:break-all">{{ $req->doc_hash }}</td></tr>
                </table>
                <div class="sub" style="margin-top:8px">للتحقّق من أصالة هذه الوثيقة أو فتحها مباشرةً: امسح رمز QR أعلاها، أو افتح {{ route('sign.verify.doc', $req->verify_code) }} — أو أدخل الرمز {{ $req->verify_code }} في {{ route('sign.verify') }}</div>
            </div>
        @elseif ($req->status === 'رُفض')
            <div class="sub" style="margin-top:20px;color:var(--bad)">🚫 رُفض التوقيع — السبب: {{ $req->declined_reason }}</div>
        @else
            <div class="sub" style="margin-top:20px">⏳ لم تُوقَّع بعد — {{ $req->opens }} فتحة للرابط حتى الآن.</div>
        @endif
    </div>
</div>
</body>
</html>
