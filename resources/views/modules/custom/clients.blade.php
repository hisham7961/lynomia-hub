{{-- **عميل ٣٦٠°**: صحتُه بأسبابها، وأرقامُ علاقته كلِّها (ارتباطات، مشاريع،
     عقود، تذاكر، فواتير، أصولٌ نديرها له)، ودخولُ مساحته بضغطة. --}}
@php
    $c360 = [
        'engagements' => \App\Models\Engagement::whereNull('deleted_at')->where('client_id', $row->id)
            ->whereNotIn('status', ['منتهٍ', 'ملغى'])->count(),
        'projects' => \App\Models\Project::whereNull('deleted_at')->where('client_id', $row->id)
            ->whereNotIn('status', ['مكتمل', 'ملغى'])->count(),
        'contracts' => \App\Models\Contract::whereNull('deleted_at')->where('client_id', $row->id)
            ->where('status', 'ساري')->count(),
        'tickets' => \App\Models\Ticket::whereNull('deleted_at')->where('client_id', $row->id)
            ->whereNotIn('status', ['تم الحل', 'مغلقة'])->count(),
        'unpaid' => \App\Models\FinDocument::whereNull('deleted_at')->where('client_id', $row->id)
            ->whereIn('kind', config('hub.fin.income', []))
            ->whereNotIn('state', ['مدفوعة', 'ملغاة', 'مسودة'])->count(),
        'assets' => \App\Models\Asset::whereNull('deleted_at')->where('client_id', $row->id)->count(),
    ];
    $cHealth = \App\Support\Engagements::health($row);
@endphp
<div class="card">
    <h3 class="cardtitle">🧭 العميل ٣٦٠°
        <span class="bdg {{ $cHealth['tone'] === 'أخضر' ? 'ok' : ($cHealth['tone'] === 'أصفر' ? 'wn' : 'bad') }}"
              title="صحةُ العلاقة — مركّبةٌ من التذاكر والفواتير والمشاريع والتجديدات">
            الصحة: {{ $cHealth['tone'] }}</span>
        <form method="POST" action="{{ route('client.switch') }}" class="inline" style="margin-inline-start:auto">
            @csrf
            <input type="hidden" name="client" value="{{ $row->id }}">
            <button class="btn p xs" title="كل القوائم تتصفى على هذا العميل حتى تعود">🏢 دخول مساحة العمل</button>
        </form>
    </h3>
    @if ($cHealth['why'])
        <div class="crow" style="margin-bottom:8px">
            @foreach ($cHealth['why'] as $w)<span class="bdg wn">{{ $w }}</span>@endforeach
        </div>
    @endif
    <div class="cards">
        <div class="stat"><span class="ico">🤝</span><b>{{ $c360['engagements'] }}</b><span>ارتباطاً نشطاً</span></div>
        <div class="stat"><span class="ico">🗂️</span><b>{{ $c360['projects'] }}</b><span>مشروعاً جارياً</span></div>
        <div class="stat"><span class="ico">📜</span><b>{{ $c360['contracts'] }}</b><span>عقداً سارياً</span></div>
        <div class="stat"><span class="ico">🎫</span><b>{{ $c360['tickets'] }}</b><span>تذكرة مفتوحة</span></div>
        <div class="stat"><span class="ico">🧾</span><b>{{ $c360['unpaid'] }}</b><span>فاتورة غير مسددة</span></div>
        <div class="stat"><span class="ico">📦</span><b>{{ $c360['assets'] }}</b><span>أصلاً نديره له</span></div>
    </div>
    @if (hub_can(auth()->user(), 'engagements', 'a'))
        <a class="btn ghost sm" href="{{ route('m.create', 'engagements') }}">＋ ارتباط جديد لهذا العميل</a>
    @endif
    <span class="sub" style="margin-inline-start:8px">تفاصيلُ كل صنفٍ في «السجلات المرتبطة» أدناه — لا نسخةَ ثانية.</span>
</div>

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
