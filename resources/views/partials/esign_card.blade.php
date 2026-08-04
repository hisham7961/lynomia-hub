{{-- مركزُ العقد: التوقيع ومراحلُه، الموقّعون، التحقّق، وسلسلةُ الملاحق/التجديدات --}}
@php
    $sreqs = \App\Models\SignRequest::where('contract_id', $row->id)->orderByDesc('created_at')->get();
    $signersByReq = $sreqs->count()
        ? \App\Models\ContractSigner::whereIn('request_id', $sreqs->pluck('id'))->orderBy('order')->get()->groupBy('request_id')
        : collect();
    $parent   = $row->parent_id ? \App\Models\Contract::withTrashed()->find($row->parent_id) : null;
    $branches = \App\Models\Contract::where('parent_id', $row->id)->orderBy('created_at')->get();
    $stageOf = fn ($s) => $s === 'وُقّع' ? 3 : ($s === 'رُفض' || $s === 'ملغى' ? 0 : ($s === 'مُرسَل' || $s === 'بانتظار التوقيع' ? 2 : 1));
    $toneOf  = fn ($s) => $s === 'وُقّع' ? 'ok' : ($s === 'رُفض' || $s === 'ملغى' ? 'bad' : 'wn');
@endphp

<div class="card" style="border-inline-start:4px solid var(--ok)">
    <h3 class="cardtitle">✍️ التوقيع الإلكتروني ومراحله
        <span class="spacer"></span>
        @if (hub_can(auth()->user(), 'contracts', 'a'))
            <a class="btn p xs" href="{{ route('esign.index', ['contract' => $row->id]) }}">＋ طلب توقيع</a>
        @endif
    </h3>

    @forelse ($sreqs as $q)
        @php $st = $stageOf($q->status); $signers = $signersByReq[$q->id] ?? collect(); @endphp
        <div style="padding:10px 0;border-bottom:1px solid color-mix(in srgb,var(--ln) 45%,transparent)">
            <div style="display:flex;gap:9px;align-items:center;flex-wrap:wrap">
                <span class="bdg {{ $toneOf($q->status) }}">{{ $q->status }}</span>
                <b style="flex:1;min-width:120px">{{ $q->title }}</b>
                <a class="btn ghost xs" href="{{ route('esign.doc', $q->id) }}">📄 الوثيقة</a>
                @if ($q->status === 'وُقّع')<a class="btn ghost xs" href="{{ route('esign.doc', $q->id) }}#cert">🎖️ الشهادة</a>@endif
            </div>

            {{-- شريطُ المراحل: مسودة ← مُرسَل ← موقّع --}}
            <div style="display:flex;gap:4px;align-items:center;margin:8px 0" aria-hidden="true">
                @foreach (['مُنشأ', 'مُرسَل', 'موقّع'] as $i => $lab)
                    <span style="height:6px;flex:1;border-radius:99px;background:{{ $st >= $i + 1 ? 'var(--ok)' : 'var(--ln)' }}"></span>
                @endforeach
                <span class="sub" style="font-size:11px">{{ ['ملغى', 'مُنشأ', 'مُرسَل', 'موقّع'][$st] ?? '' }}</span>
            </div>

            {{-- الموقّعون وحالة كلٍّ --}}
            @if ($signers->count())
                <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:4px">
                    @foreach ($signers as $s)
                        <span class="bdg {{ $toneOf($s->status ?? '') }}" title="{{ $s->role }}">
                            {{ $s->order }}. {{ $s->name ?: '—' }} · {{ $s->status ?: 'بانتظار' }}</span>
                    @endforeach
                </div>
            @endif

            {{-- التحقّق: الرمز و QR للموقَّعة (نسخةٌ ورقيةٌ قابلةٌ للتحقّق) --}}
            @if ($q->status === 'وُقّع' && ! empty($q->verify_code))
                @php $qr = \App\Support\Qr::svg(route('sign.verify') . '?code=' . $q->verify_code, 84); @endphp
                <div style="display:flex;gap:10px;align-items:center;margin-top:8px">
                    @if ($qr)<div style="background:#fff;padding:4px;border-radius:8px;border:1px solid var(--ln)">{!! $qr !!}</div>@endif
                    <div class="sub" style="line-height:1.8">
                        وُقّع: <b>{{ $q->signer_name ?? '—' }}</b>@if ($q->signed_at) · {{ $q->signed_at->format('Y-m-d H:i') }}@endif<br>
                        رمز التحقق: <b class="mono ltr">{{ $q->verify_code }}</b> — امسح الرمز أو تحقّق عبر
                        <a class="mono ltr" href="{{ route('sign.verify') }}">التحقّق</a>
                    </div>
                </div>
            @endif
        </div>
    @empty
        <div class="sub">لا طلبات توقيع لهذا العقد بعد — ابدأ بطلبٍ من الزر أعلاه.</div>
    @endforelse
</div>

{{-- سلسلةُ العقد: الأصل والملاحق والتجديدات — مسارُ حياةِ العقد في مكانٍ واحد --}}
@if ($parent || $branches->count())
    <div class="card">
        <h3 class="cardtitle">🔗 سلسلة العقد
            @if ($row->kind)<span class="bdg">{{ $row->kind }}</span>@endif
        </h3>
        <div class="sub" style="line-height:2.1">
            @if ($parent)
                <div style="display:flex;gap:8px;align-items:center">
                    <span class="bdg">الأصل</span>
                    <a href="{{ route('m.show', ['contracts', $parent->id]) }}">{{ \Illuminate\Support\Str::limit($parent->title ?? $parent->doc_no, 50) }}</a>
                    @if ($parent->status)<span class="bdg {{ hub_tone($parent->status) }}">{{ $parent->status }}</span>@endif
                </div>
            @endif
            <div style="display:flex;gap:8px;align-items:center;font-weight:700">
                <span class="bdg ok">هذا العقد</span>
                <span>{{ \Illuminate\Support\Str::limit($row->title ?? $row->doc_no, 50) }}</span>
            </div>
            @foreach ($branches as $b)
                <div style="display:flex;gap:8px;align-items:center;padding-inline-start:14px">
                    <span class="bdg wn">{{ $b->kind ?: 'فرع' }}</span>
                    <a href="{{ route('m.show', ['contracts', $b->id]) }}">{{ \Illuminate\Support\Str::limit($b->title ?? $b->doc_no, 50) }}</a>
                    @if ($b->status)<span class="bdg {{ hub_tone($b->status) }}">{{ $b->status }}</span>@endif
                </div>
            @endforeach
        </div>
    </div>
@endif
