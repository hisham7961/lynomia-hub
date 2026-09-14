@extends('layouts.app')
@section('title', 'دليل الفريق')
@section('content')
{{-- وجها الدليل (الجولة 1 · F4): full لحامل hr:v كما كان · basic للزميل الداخليّ
     — بطاقةٌ أدنى (اسم/مسمّى/قسم/شركة/مدير) بلا روابطَ تقود لملفّات HR ثم 403 --}}
@php $tFull = ($t['mode'] ?? 'full') === 'full'; @endphp
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>الموارد البشرية</span><span aria-hidden="true">‹</span><b>دليل الفريق</b></nav>
        <h2>👥 دليل الفريق</h2>
        <div class="sub">
            @if ($tFull)
                وجوهٌ لا صفوف: مربّعٌ لكل موظف بصورته ومسمّاه ومديره ومهاراته وشهاداته —
                و<b>حالُ ملفّه</b> وما يقترب انتهاؤه.
            @else
                زملاؤك في المنشأة: الاسمُ والمسمّى والقسمُ والشركة ومديرُه المباشر —
                والبياناتُ الحسّاسة (هاتف/راتب/وثائق) تبقى لأصحاب صلاحيّة الموارد البشرية.
            @endif
        </div>
    </div>
    <div class="crow" style="gap:8px">
        <label class="vh" for="team-q">ابحث في الدليل</label>
        <input class="inp" type="search" id="team-q" placeholder="🔎 ابحث باسمٍ أو مسمّى أو قسم…" style="max-width:230px">
        <a class="btn ghost sm" href="{{ route('team') }}?fresh=1">🔄 حدّث</a>
        @if ($tFull)<a class="btn ghost sm" href="{{ route('m.index', 'hr') }}">جدول الموظفين ↗</a>@endif
    </div>
</div>

@if (count($t['alerts']))
    <div class="card" style="margin-bottom:12px">
        <h3>🎯 ما يستحق التفاتاً <span class="bdg wn">{{ count($t['alerts']) }}</span></h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px;margin-top:6px">
            @foreach (array_slice($t['alerts'], 0, 12) as $a)
                <a href="{{ route('m.show', ['hr', $a['id']]) }}"
                   style="padding:10px 12px;border:1px solid var(--ln);border-radius:11px;display:block;
                          background:color-mix(in srgb, var(--{{ $a['tone'] }}) 9%, transparent)">
                    <div class="crow" style="gap:6px;align-items:baseline"><span>{{ $a['icon'] }}</span><b style="font-size:13px">{{ $a['title'] }}</b></div>
                    <div style="font-size:12px;margin-top:3px"><b>{{ $a['what'] }}</b></div>
                    <div class="sub" style="font-size:12px;line-height:1.8;margin-top:3px">{{ $a['why'] }}</div>
                </a>
            @endforeach
        </div>
    </div>
@endif

@forelse ($t['depts'] as $dept => $cards)
    <div class="card" style="margin-bottom:14px">
        <div class="crow" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
            <h3 style="margin:0">🏷️ {{ $dept }}</h3>
            <span class="bdg">{{ count($cards) }}</span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px;margin-top:10px">
            @foreach ($cards as $c)
                <div class="team-card" data-q="{{ mb_strtolower(trim($c['name'] . ' ' . $c['title'] . ' ' . $c['dept'] . ' ' . ($c['manager'] ?? '') . ' ' . ($c['company'] ?? ''))) }}"
                     style="border:1px solid var(--ln);border-radius:14px;padding:12px;display:flex;flex-direction:column;gap:6px;
                            {{ $c['alert'] ? 'border-color:var(--wn)' : '' }}">
                    <div class="crow" style="gap:9px;align-items:center">
                        @if ($c['photo'])
                            <img src="{{ route('file.show', $c['photo']) }}" alt=""
                                 style="height:44px;width:44px;object-fit:cover;border-radius:50%;border:1px solid var(--ln)">
                        @else
                            <span class="ava" style="height:44px;width:44px;font-size:17px">{{ mb_substr($c['name'], 0, 1) }}</span>
                        @endif
                        <div style="min-width:0">
                            {{-- في الأدنى لا رابطَ لملف HR — بابٌ سيصدّه 403 (درسُ F28) --}}
                            @if ($tFull)
                                <a href="{{ route('m.show', ['hr', $c['id']]) }}"><b style="font-size:13px">{{ \Illuminate\Support\Str::limit($c['name'], 22) }}</b></a>
                            @else
                                <b style="font-size:13px">{{ \Illuminate\Support\Str::limit($c['name'], 22) }}</b>
                            @endif
                            <div class="sub" style="font-size:12px">{{ \Illuminate\Support\Str::limit((string) $c['title'], 24) ?: '—' }}</div>
                        </div>
                    </div>

                    <div class="crow" style="gap:4px;flex-wrap:wrap">
                        @if ($tFull)<span class="bdg {{ $c['status'] === 'نشط' ? 'ok' : '' }}">{{ $c['status'] ?: '—' }}</span>@endif
                        @if ($c['docPct'] < 100)<span class="bdg {{ $c['docMissing'] ? 'bad' : 'wn' }}" title="اكتمال الملف">📂 {{ $c['docPct'] }}٪</span>@endif
                        @if ($c['skillsN'])<span class="bdg" title="مهارات">🧠 {{ $c['skillsN'] }}</span>@endif
                        @if ($c['certsN'])<span class="bdg" title="شهادات">🎓 {{ $c['certsN'] }}</span>@endif
                        @if (! $c['userId'])
                            @if ($tFull)<span class="bdg wn" title="لا حساب في النظام">بلا حساب</span>@endif
                        @elseif ($c['userId'] !== auth()->id())
                            {{-- بابٌ ثانٍ للمراسلة: الدليلُ يعرف من هو، فلا يُبحث عنه في قائمةٍ ثانية --}}
                            <a class="bdg lnk" href="{{ route('dm.thread', $c['userId']) }}"
                               title="راسِل {{ $c['name'] }}">💬 راسِله</a>
                        @endif
                    </div>

                    @if (count($c['skills']))
                        <div class="sub" style="font-size:12px;line-height:1.8">{{ implode(' · ', array_slice($c['skills'], 0, 3)) }}</div>
                    @endif
                    @if ($c['manager'])<div class="sub" style="font-size:12px">مديره: {{ $c['manager'] }}</div>@endif
                    @if ($c['company'] ?? null)<div class="sub" style="font-size:12px">🏢 {{ $c['company'] }}</div>@endif
                    @if ($c['salary'] !== null)<div class="sub" style="font-size:12px">💰 {{ number_format($c['salary'], 0) }}</div>@endif

                    @if ($c['idDays'] !== null && $c['idDays'] <= 60)
                        <div class="sub" style="font-size:12px"><span class="bdg {{ $c['idDays'] < 0 ? 'bad' : 'wn' }}">🪪 {{ $c['idDays'] < 0 ? 'منتهية' : 'بعد ' . $c['idDays'] . ' يوماً' }}</span></div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@empty
    <div class="card"><div class="empty"><span class="big">👥</span>لا موظفين في نطاقك بعد</div></div>
@endforelse

{{-- بحثٌ محليّ (F4: «لا يجد حتى مديرته بالبحث») — ترشيحُ بطاقاتٍ في المتصفح بلا استعلام --}}
<script>
(function () {
    var q = document.getElementById('team-q');
    if (! q) return;
    q.addEventListener('input', function () {
        var t = q.value.trim().toLowerCase();
        document.querySelectorAll('.team-card').forEach(function (c) {
            c.style.display = (! t || (c.dataset.q || '').indexOf(t) !== -1) ? '' : 'none';
        });
    });
})();
</script>

<div class="card">
    <div class="sub" style="line-height:2">
        <b>الصورة الشخصية</b> تُرفع من صفحة الموظف في لوحة الوثائق بنوع «الصورة الشخصية» — فتصير وجهه هنا.
        و<b>اكتمال الملف</b> يُحسب على الوثائق الإلزامية وحدها، و<b>المهارات والشهادات</b> من وحدتها مربوطةً بالموظف.
        والراتب لا يظهر لمن حجبه دورُه.
    </div>
</div>
@endsection
