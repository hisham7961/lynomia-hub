@php
    $svcCanCost = hub_field_mode(auth()->user(), 'services', 'cost') !== 'hide';
    $svcPrice = (float) ($row->price ?? 0);
    $svcCost = (float) ($row->cost ?? 0);
    $svcMargin = ($svcCanCost && $svcPrice > 0) ? round(($svcPrice - $svcCost) * 100 / $svcPrice, 1) : null;
    $svcNum = fn ($v) => $v === null ? '—' : number_format((float) $v, ((float) $v == (int) $v) ? 0 : 2);
    $svcCur = $row->currency ?: '';

    // من ينافس هذه الخدمة: المرتبطون بها صراحةً + من أُسندت إليه في سجلهم
    $svcRivals = collect();
    if (hub_can(auth()->user(), 'competitors', 'v')) {
        $ids = array_values(array_filter((array) ($row->competitor_ids ?? [])));
        $svcRivals = hub_scope(\App\Models\Competitor::query(), 'competitors')->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('service_id', $row->id)->when($ids, fn ($w) => $w->orWhereIn('id', $ids)))
            ->orderByRaw("CASE threat WHEN 'مرتفع' THEN 1 WHEN 'متوسط' THEN 2 ELSE 3 END")
            ->limit(20)->get();
    }
    $svcLows = $svcRivals->pluck('price_from')->filter(fn ($v) => (float) $v > 0)->map(fn ($v) => (float) $v);
    $svcHighs = $svcRivals->pluck('price_to')->filter(fn ($v) => (float) $v > 0)->map(fn ($v) => (float) $v);

    // أفكار تطوّر هذه الخدمة: المرتبطة بها صراحةً أو المسندة إليها
    $svcIdeas = collect();
    if (hub_can(auth()->user(), 'ideas', 'v')) {
        $iids = array_values(array_filter((array) ($row->idea_ids ?? [])));
        $svcIdeas = hub_scope(\App\Models\Idea::query(), 'ideas')->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('service_id', $row->id)->when($iids, fn ($w) => $w->orWhereIn('id', $iids)))
            ->orderByDesc('created_at')->limit(12)->get();
    }
@endphp

{{-- ═══ موقعنا من السوق ═══ --}}
@if ($svcRivals->isNotEmpty() || $svcMargin !== null)
<div class="card">
    <h3 class="cardtitle">🎯 موقعنا من السوق</h3>
    <div class="cards" style="margin-bottom:10px">
        <div class="stat"><span class="ico">🏷️</span><b>{{ $svcNum($svcPrice ?: null) }}</b>
            <span>سعرنا {{ $svcCur }}{{ $row->cycle ? ' · ' . $row->cycle : '' }}{{ $row->unit ? ' · ' . $row->unit : '' }}</span></div>
        @if ($svcMargin !== null)
            <div class="stat"><span class="ico">📐</span>
                <b class="{{ $svcMargin < 20 ? 'txt-bad' : '' }}">{{ $svcMargin }}٪</b>
                <span>الهامش · تكلفة {{ $svcNum($svcCost) }}</span></div>
        @endif
        @if ($svcLows->count())
            <div class="stat"><span class="ico">📉</span><b>{{ $svcNum($svcLows->min()) }}</b>
                <span>أرخص منافس معلن</span></div>
            <div class="stat"><span class="ico">📈</span><b>{{ $svcNum($svcHighs->count() ? $svcHighs->max() : $svcLows->max()) }}</b>
                <span>أغلى منافس معلن</span></div>
        @endif
        @if ($row->min_price)
            <div class="stat"><span class="ico">🛡️</span><b>{{ $svcNum($row->min_price) }}</b>
                <span>أدنى سعر يُقبل — سياج التفاوض</span></div>
        @endif
    </div>

    @if ($svcLows->count() && $svcPrice > 0)
        @php
            $mn = min($svcLows->min(), $svcPrice);
            $mx = max($svcHighs->count() ? $svcHighs->max() : $svcLows->max(), $svcPrice);
            $span = max(0.0001, $mx - $mn);
            $pos = (int) round(($svcPrice - $mn) * 100 / $span);
        @endphp
        <div class="sub" style="margin-bottom:4px">أين يقع سعرنا بين أرخص وأغلى منافس:</div>
        <div class="pbar big" style="position:relative"><span style="width:{{ $pos }}%"></span></div>
        <div class="sub" style="display:flex;justify-content:space-between;margin-top:4px">
            <span>{{ $svcNum($mn) }}</span>
            <span><b>سعرنا {{ $svcNum($svcPrice) }}</b> — {{ $pos <= 33 ? 'في الثلث الأرخص' : ($pos >= 67 ? 'في الثلث الأغلى' : 'في الوسط') }}</span>
            <span>{{ $svcNum($mx) }}</span>
        </div>
        @if ($pos >= 67 && ! $row->value_prop)
            <div class="sub" style="margin-top:8px;color:var(--wn)">
                ⚠️ سعرنا في الثلث الأغلى و«عرض القيمة» فارغ — السعر الأعلى يحتاج سبباً مكتوباً يُقال للعميل.
            </div>
        @endif
    @endif

    @if ($svcRivals->isNotEmpty())
        <div class="tblwrap" style="margin-top:12px"><table class="tbl">
            <thead><tr><th>المنافس</th><th>التهديد</th><th>موقعه</th><th>سعره</th><th>مجاني؟</th><th>قنواته</th></tr></thead>
            <tbody>
            @foreach ($svcRivals as $c)
                <tr>
                    <td><a href="{{ route('m.show', ['competitors', $c->id]) }}"><b>{{ $c->name }}</b></a>
                        @if ($c->market)<div class="sub">{{ $c->market }}</div>@endif</td>
                    <td><span class="bdg {{ $c->threat === 'مرتفع' ? 'bad' : ($c->threat === 'متوسط' ? 'wn' : 'g') }}">{{ $c->threat ?: '—' }}</span></td>
                    <td class="sub">{{ $c->positioning ?: '—' }}</td>
                    <td class="mono">
                        @if ($c->price_from || $c->price_to)
                            {{ $svcNum($c->price_from) }}@if ($c->price_to && $c->price_to != $c->price_from) – {{ $svcNum($c->price_to) }}@endif
                            <div class="sub">{{ $c->billing ?: '' }}</div>
                        @else<span class="sub">غير معلن</span>@endif
                    </td>
                    <td>@if ($c->free_tier)<span class="bdg wn">باقة مجانية</span>@elseif ($c->trial_days)<span class="bdg g">{{ $c->trial_days }} يوم تجربة</span>@else<span class="sub">—</span>@endif</td>
                    <td>
                        @foreach (['website' => '🌐', 'instagram' => '📸', 'tiktok' => '🎵', 'x_url' => '𝕏',
                                   'linkedin' => '💼', 'youtube' => '▶️', 'facebook' => '👍', 'play' => '🤖', 'appstore' => '🍏'] as $col => $emo)
                            @if ($c->{$col})<a class="btn ghost xs" href="{{ hub_safe_url($c->{$col}) }}" target="_blank" rel="noopener">{{ $emo }}</a>@endif
                        @endforeach
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @else
        <div class="sub" style="margin-top:10px">
            لا منافس مرتبطاً بهذه الخدمة — اربطهم من حقل «من ينافسها» أو من سجل المنافس نفسه،
            فيصير التسعير مُقارَناً لا تخميناً.
        </div>
    @endif
</div>
@endif

{{-- ═══ الابتكار المتصل بالخدمة ═══ --}}
@if ($svcIdeas->isNotEmpty())
<div class="card">
    <h3 class="cardtitle">💡 أفكار تطوّر هذه الخدمة</h3>
    <div class="sub" style="margin-bottom:8px">
        كان الابتكار في وادٍ والخدمة في وادٍ — هنا ما اقتُرح لتحسينها بدرجة ICE وحالته.
    </div>
    <table class="mini">
        @foreach ($svcIdeas as $i)
            <tr>
                <td><a href="{{ route('m.show', ['ideas', $i->id]) }}">{{ $i->title }}</a>
                    <div class="sub">{{ $i->cat ?: '—' }}</div></td>
                <td class="acts"><span class="bdg {{ hub_tone($i->status) }}">{{ $i->status ?: '—' }}</span></td>
                <td class="acts mono sub">{{ $i->iceScore() !== null ? 'ICE ' . $i->iceScore() : '—' }}</td>
            </tr>
        @endforeach
    </table>
    <div class="sub" style="margin-top:8px"><a href="{{ route('innovation') }}">مركز الابتكار ←</a></div>
</div>
@endif
