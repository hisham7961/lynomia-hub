{{-- بطاقة «مبيعات القنوات» من أودو — للمشاريع. يتوقع: $row --}}
@php
    $chCli = \App\Support\Odoo::forRow($row);
    $chList = (array) (((array) $row->meta)['odoo']['channels'] ?? []);
    $chMayE = hub_can(auth()->user(), 'projects', 'e');
    // كل قناة تُجلب على حدة: فشلُ واحدةٍ لا يقتل الباقيات
    $chData = [];
    if ($chCli->ready()) {
        foreach ($chList as $chC) {
            try { $chData[$chC['key']] = ['ok' => true] + $chCli->channelStats($row->id, $chC); }
            catch (\Throwable $e) { $chData[$chC['key']] = ['ok' => false, 'err' => $e->getMessage()]; }
        }
    }
    $chTotal = array_sum(array_map(fn ($d) => ($d['ok'] ?? false) ? $d['total'] : 0, $chData));
    $chAllOk = $chData && ! in_array(false, array_column($chData, 'ok'), true);
@endphp
<div class="card" id="chans">
    <h3>🛍 مبيعات القنوات — من أودو
        @if ($chCli->id() !== 'default')<span class="bdg ok">{{ $chCli->label() }}</span>@endif
    </h3>

    @if ($chCli->error())
        <div class="ferr" style="margin-bottom:8px">⚠️ {{ $chCli->error() }}</div>
    @elseif (! $chCli->ready())
        <p class="sub">لم يُهيأ اتصال أودو بعد —
            @if (hub_is_owner())أكمل الافتراضي في <a href="{{ route('settings.edit') }}">الإعدادات</a> أو أضف خادماً من <a href="{{ route('integrations.odoo') }}">مركز التكاملات</a>.
            @else اطلب من المالك تهيئته.@endif</p>
    @elseif (! $chList)
        <p class="sub">لا قنوات بيعٍ معرّفة لهذا المشروع —
            @if ($chMayE)<a href="{{ route('odoo.project', $row->id) }}">خصّص القنوات</a> (ترنديول، أمازون، نون، المتجر…) لتظهر مبيعاتُ كلٍّ منها هنا.
            @else تُعرَّف من صاحب صلاحية تعديل المشروع.@endif</p>
    @else
        <div class="cards" style="margin-bottom:8px">
            @foreach ($chList as $chC)
                @php $d = $chData[$chC['key']] ?? ['ok' => false, 'err' => 'لم تُجلب']; @endphp
                @if ($d['ok'])
                    <div class="stat"><span class="ico">{{ $chC['icon'] ?? '🛒' }}</span>
                        <b>{{ number_format($d['total'], 0) }}@if ($d['approx'])<span class="sub"> تقريبي</span>@endif</b>
                        <span>{{ $chC['label'] }} ({{ $d['count'] }} {{ $d['src'] === 'invoices' ? 'فاتورة' : 'أمر' }})</span></div>
                @else
                    <div class="stat"><span class="ico">{{ $chC['icon'] ?? '🛒' }}</span>
                        <b class="txt-bad">تعذّر</b>
                        <span>{{ $chC['label'] }} — {{ \Illuminate\Support\Str::limit($d['err'], 60) }}</span></div>
                @endif
            @endforeach
        </div>
        <div class="crow">
            <span class="sub">
                @if ($chAllOk)المجموع: <b>{{ number_format($chTotal, 0) }}</b> بعملة أودو ·@endif
                آخر جلب {{ collect($chData)->first()['at'] ?? now()->format('H:i') }} · تُخبأ ١٠ دقائق
            </span>
            <span class="spacer"></span>
            @if ($chMayE)
                <form method="POST" action="{{ route('odoo.project.refresh', $row->id) }}">@csrf
                    <button class="btn ghost xs">🔄 تحديث الآن</button></form>
                <a class="btn ghost xs" href="{{ route('odoo.project', $row->id) }}">⚙️ تخصيص القنوات</a>
            @endif
        </div>
    @endif
</div>
