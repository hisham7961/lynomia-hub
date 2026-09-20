@extends('layouts.app')
@section('title', 'مطابقةُ النماذج')
@section('content')

{{-- ═══ أين يفترق Hub عن البوّابة؟ ═══

     **قراءةٌ محضةٌ لا تُصلِح شيئاً.** الإصلاحُ قرارُ إنسانٍ يراه قبل أن يتّخذه،
     ومصالحةٌ تلقائيّةٌ تحذف صفّاً أو تُعيد نشرَ نموذجٍ **بلا أن يعلم أحد** أسوأُ
     من الافتراقِ نفسِه. --}}

<div class="hero">
    <div>
        <h2>🔁 مطابقةُ نماذجِ {{ $provider->label }}</h2>
        <div class="sub">ما في Hub مقابلَ ما هو منشورٌ عند البوّابةِ باعتمادِ هذا المزوّد.</div>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
        <a class="btn sm" href="{{ route('ai.models.index', $provider) }}">🧠 النماذج</a>
        <a class="btn sm" href="{{ route('ai.models.browse', $provider) }}">🔍 اكتشاف</a>
    </div>
</div>

@include('ai._sections')

@if (! $report['ok'])
    <div class="cards">
        <div class="stat"><span class="ico bdg wn">⚠️</span><b>تعذّرت القراءة</b>
            <span>{{ $report['error'] }} — <b>ولا يُقرَأ هذا افتراقاً</b>: البوّابةُ لم تُسأل.</span></div>
    </div>
@else

<div class="cards">
    <div class="stat"><span class="ico bdg ok">✅</span><b>{{ $report['matched'] }}</b>
        <span>مُتطابقٌ في الطرفَين</span></div>
    <div class="stat"><span class="ico bdg {{ $report['hub_only'] !== [] ? 'wn' : '' }}">📦</span>
        <b>{{ count($report['hub_only']) }}</b>
        <span>في Hub بلا نشرٍ عند البوّابة</span></div>
    <div class="stat"><span class="ico bdg {{ $report['gateway_only'] !== [] ? 'wn' : '' }}">🛰️</span>
        <b>{{ count($report['gateway_only']) }}</b>
        <span>منشورٌ عند البوّابةِ بلا صفٍّ في Hub</span></div>
</div>

@if ($report['hub_only'] !== [])
<div class="card">
    <h3>في Hub بلا نشر <span class="mut">({{ count($report['hub_only']) }})</span></h3>
    <div class="sub mut">
        النموذجُ يبدو متاحاً في الشاشاتِ و<b>يسقط عند أوّلِ طلبٍ حقيقيّ</b> — فالبوّابةُ لا تعرفه.
        أزِله من Hub، أو أعِد تبنّيَه من الاكتشافِ لينشُر من جديد.
    </div>
    <table class="tbl">
        <thead><tr><th>الاسم في Hub</th><th>المعرّفُ عند المزوّد</th><th>الحال</th></tr></thead>
        <tbody>
        @foreach ($report['hub_only'] as $r)
            <tr>
                <td><b class="mono ltr">{{ $r['litellm_model_name'] }}</b></td>
                <td><span class="mono ltr">{{ $r['upstream_model'] }}</span></td>
                <td>{{ $r['enabled'] ? 'مُفعَّل' : 'مُعطَّل' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

@if ($report['gateway_only'] !== [])
<div class="card">
    <h3>منشورٌ عند البوّابةِ بلا صفٍّ في Hub <span class="mut">({{ count($report['gateway_only']) }})</span></h3>
    <div class="sub mut">
        تهيئةٌ حيّةٌ <b>لا يحكمها Hub</b>: تُنفق ولا تُعرَض ولا تُطفأ من مركزِ الذكاء.
        تبنَّها من <a href="{{ route('ai.models.browse', $provider) }}">شاشةِ الاكتشاف</a> لتصير مرئيّةً ومحكومة.
    </div>
    <table class="tbl">
        <thead><tr><th>الاسمُ عند البوّابة</th><th>المعرّفُ عند المزوّد</th><th>معرّفُ النشر</th></tr></thead>
        <tbody>
        @foreach ($report['gateway_only'] as $r)
            <tr>
                <td><b class="mono ltr">{{ $r['litellm_model_name'] }}</b></td>
                <td><span class="mono ltr">{{ $r['upstream_model'] }}</span></td>
                <td><span class="mut mono ltr">{{ $r['deployment_id'] }}</span></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

@if ($report['hub_only'] === [] && $report['gateway_only'] === [])
    <div class="card">
        <h3>الطرفانِ متّفقان</h3>
        <div class="sub mut">كلُّ ما في Hub منشورٌ عند البوّابة، ولا نشرَ عندها خارجَ علمِنا.</div>
    </div>
@endif

@endif

@endsection
