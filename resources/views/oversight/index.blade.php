@extends('layouts.app')
@section('title', 'رقابةُ الاتصالات')
@section('content')

@include('partials.pagehead', ['icon' => '🛡️', 'title' => 'رقابةُ الاتصالات', 'crumb' => 'الامتثال',
    'sub' => 'قراءةٌ رقابيّةٌ للمحادثات لغرض الامتثال — للقراءة فقط، لا تُحرّك إيصالَ القراءة، وكلُّ فتحٍ مُسجَّلٌ بسببه في سجل التدقيق.'])

{{-- لافتةُ الوصول المُسجَّل — بابٌ ظاهرٌ لا خفيّ (§6) --}}
<div class="note wn" style="border-color:var(--wn);display:flex;gap:10px;align-items:flex-start">
    <span aria-hidden="true" style="font-size:18px">🛡️</span>
    <div>
        <b>وصولُ امتثالٍ — مُسجَّل.</b>
        كلُّ محادثةٍ تفتحها هنا تُقرأ للقراءة فقط ويُكتَب لها صفٌّ في سجل التدقيق يحمل هويّتك
        والسببَ ومعرّفَ المحادثة والعنوان ومعرّفَ الطلب. القراءةُ الرقابيّةُ لا تُغيّر حالةَ
        القراءة لدى الأطراف، ولا تخوّل تحريرَ رسالةٍ أو حذفَها.
    </div>
</div>

@if (session('err'))<div class="note wn">{{ session('err') }}</div>@endif

@if ($needReason)
    {{-- بلا سببٍ لا بيانات: نافذةُ السبب وحدَها — لا وصولٌ غيرُ مسجَّل --}}
    <div class="card" style="max-width:560px;margin-top:12px">
        <h3>سببُ الوصول الرقابيّ</h3>
        <div class="sub" style="margin-bottom:10px">
            اكتب سببَ اطّلاعك على المحادثات (تحقيقٌ في بلاغ، مراجعةُ امتثال، طلبٌ قانونيّ…).
            السببُ إلزاميٌّ ويُسجَّل مع كلِّ فتح — لا رقابةَ بلا مسوّغٍ مكتوب.
        </div>
        <form method="GET" action="{{ route('oversight.index') }}">
            <label class="lbl" for="ov-reason">السبب</label>
            <input class="inp" id="ov-reason" name="reason" required maxlength="400"
                   placeholder="مثال: تحقيقٌ في بلاغِ تسريبِ بياناتِ عميل — تذكرة #…">
            <button class="btn p" type="submit" style="margin-top:10px">🛡️ ابدأ المسحَ الرقابيّ</button>
        </form>
    </div>
@else
    {{-- السببُ الفعّالُ ظاهرٌ دائماً — يُحمَل في كلِّ فتحٍ ويُدقَّق به --}}
    <div class="sub" style="margin:10px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <span class="bdg wn">💬 سببُ الوصول: {{ \Illuminate\Support\Str::limit($reason, 90) }}</span>
        <a class="btn ghost xs" href="{{ route('oversight.index') }}">✎ تغييرُ السبب</a>
    </div>

    @include('partials.cc.kpis', ['items' => $kpis])

    @include('partials.cc.tabs', ['tabs' => $tabs, 'active' => $active])

    <div class="card pad0" style="margin-top:12px">
        @include('partials.cc.findings', ['rows' => $rows,
            'empty' => 'لا محادثاتٍ ضمنَ نطاقك في هذا التبويب'])
    </div>
@endif

@endsection
