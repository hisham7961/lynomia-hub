{{-- ودجة: آخر النشاطات — صفوف بصورة رمزية وشارة فعلٍ ملونة --}}
@php
    // لون الصورة الرمزية ثابت لكل اسم — تجزئة بسيطة على لوحة الهوية
    $pal = ['#0E7C66', '#4C6FA5', '#C08A3E', '#7C6FB0', '#B0568E', '#2FB79A'];
@endphp
<div class="card kid" style="grid-column:span 1">
    <h3>🕘 آخر النشاطات</h3>
    <div class="actfeed">
        @forelse ($data ?? [] as $a)
            @php $who = $a->user_name ?? 'النظام'; $c = $pal[abs(crc32($who)) % count($pal)]; @endphp
            <div class="actrow">
                <span class="avx" style="--av:{{ $c }}">{{ mb_substr($who, 0, 1) }}</span>
                <div class="actbody">
                    <div class="actline"><b>{{ $who }}</b>
                        <span class="bdg {{ $a->action === 'حذف' ? 'bad' : ($a->action === 'إضافة' ? 'ok' : 'g') }}">{{ $a->action }}</span></div>
                    <div class="sub">{{ hub_mod($a->module)['label'] ?? $a->module }}: {{ \Illuminate\Support\Str::limit($a->name, 34) }}</div>
                </div>
                <span class="acttime">{{ \Illuminate\Support\Carbon::parse($a->created_at)->diffForHumans(null, true) }}</span>
            </div>
        @empty
            <div class="empty">لا نشاط بعد — ابدأ بإضافة أول سجل</div>
        @endforelse
    </div>
</div>
