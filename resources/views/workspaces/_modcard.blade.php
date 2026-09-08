{{-- بطاقةُ وحدةٍ في صفحة المساحة — الترميزُ نفسُه حرفاً بحرف (لا تغييرَ بصريّ · P3):
     عدّاد + جديدُ الأسبوع + شارةُ الانتباه (wsatt). تُستدعى من كلِّ قسمٍ ومن «أخرى». --}}
@php $def = hub_mod($mk); $look = hub_mod_look($mk); $c = $cards[$mk] ?? null; $att = (int) ($attention[$mk] ?? 0); @endphp
<a href="{{ route('m.index', $mk) }}" class="stat" style="--st:{{ $look['color'] }};text-decoration:none;position:relative">
    {{-- شارة الانتباه: يستحق أو تأخّر — النقطة الحمراء تقول أين يُنظر --}}
    @if ($att)<span class="nbdg wsatt" style="position:absolute;top:8px;left:8px" title="{{ $att }} يحتاج انتباهاً">{{ $att }}</span>@endif
    <span class="ico" aria-hidden="true">{{ $look['icon'] }}</span>
    <b>{{ number_format($c['count'] ?? 0) }}</b>
    <span>{{ $def['label'] }}@if (($c['week'] ?? 0) > 0) · <b style="font-size:11px">+{{ $c['week'] }}</b> هذا الأسبوع @endif</span>
</a>
