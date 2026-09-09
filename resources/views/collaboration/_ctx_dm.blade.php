{{-- لوحُ السياق لمحادثةٍ مباشرة — الحضورُ الخشن (للتواصل لا للمراقبة) وروابطُ الطرف --}}
@php
    $presenceLabel = ['online' => 'متصل الآن', 'recent' => 'نشطٌ حديثاً', 'away' => 'بعيد', 'offline' => 'غير متصل'];
    $st = $dmPresence['state'] ?? 'offline';
@endphp

<div class="cx-ctxsec" style="text-align:center;padding-bottom:14px">
    <span class="ava" style="width:52px;height:52px;font-size:20px;margin:0 auto">{{ mb_substr($other->name, 0, 1) }}</span>
    <div style="margin-top:8px"><b>{{ $other->name }}</b></div>
    @if ($other->job_title)<div class="sub" style="font-size:12px">{{ $other->job_title }}</div>@endif
    <div class="sub" style="font-size:12px;margin-top:4px">{{ $presenceLabel[$st] ?? 'غير متصل' }}</div>
</div>

<div class="cx-ctxsec">
    <div class="cx-ctxh">🔗 روابط</div>
    <a class="btn ghost sm" href="{{ route('dm.thread', $other->id) }}" style="width:100%;text-align:start;margin-bottom:6px">✉️ الصندوقُ الكامل ⤢</a>
    {{-- §14 بدءُ مجموعةٍ من محادثة — مجموعةٌ **جديدة** تضمّ الطرفَ سلفاً، لا تُغيَّر المحادثةُ
         الخاصّةُ ولا تُنقَل رسائلُها. يُفتَح لوحُ الإنشاءِ والطرفُ محدَّدٌ مسبقاً. --}}
    <a class="btn ghost sm" href="{{ route('collab.center', ['new' => 'group', 'with' => $other->id]) }}"
       style="width:100%;text-align:start">➕ إضافةُ أشخاص / بدءُ مجموعة</a>
    <div class="sub" style="font-size:11px;margin-top:5px;line-height:1.7">تُنشئ مجموعةً جديدة — لا تُنقَل رسائلُ محادثتِكما الخاصّة.</div>
</div>

<div class="cx-ctxsec">
    <div class="sub" style="font-size:11px;line-height:1.8">
        الحضورُ حالةٌ خشنةٌ من نبضةِ الجلسة — للتواصلِ لا للمراقبة. لا يُقرأ منها دوامٌ أو موقعٌ أو أداء.
    </div>
</div>
