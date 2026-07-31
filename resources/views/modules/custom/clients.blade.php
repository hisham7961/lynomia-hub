{{-- تشريح الخسارة على صفحة العميل الخاسر — يتوقع $row --}}
@if ((string) $row->stage === 'خسارة')
    @php
        $lsComp = $row->competitor_id
            ? \App\Models\Competitor::whereNull('deleted_at')->find($row->competitor_id) : null;
    @endphp
    <div class="card">
        <h3 class="cardtitle">📉 تشريح الخسارة</h3>
        <div style="display:flex;gap:18px;flex-wrap:wrap">
            <div><div class="sub">السبب</div>
                @if ($row->lost_reason)<span class="bdg bad">{{ $row->lost_reason }}</span>
                @else<span class="sub">غير مسجَّل — سجّله ليُحتسب في تقرير «لماذا نخسر»</span>@endif
            </div>
            @if ($lsComp)
                <div><div class="sub">خسرناه لصالح</div>
                    <a href="{{ route('m.show', ['competitors', $lsComp->id]) }}">{{ $lsComp->name }}</a>
                    @if ($lsComp->threat)<span class="bdg {{ hub_tone($lsComp->threat) }}">{{ $lsComp->threat }}</span>@endif
                </div>
            @endif
            @if ($row->value)
                <div><div class="sub">القيمة الضائعة</div>
                    <b class="mono">{{ number_format((float) $row->value, 2) }} {{ setting('app.currency', 'د.ك') }}</b></div>
            @endif
        </div>
    </div>
@endif
