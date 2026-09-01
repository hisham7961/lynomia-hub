{{-- مساحة عمل العقد (CLM م7) — تبويبات فوق السكّتين القائمتين: أطراف وتواقيع،
     موافقات، التزامات، مالية، وسلسلة الملاحق/التجديد. يتوقع $row (العقد) و$module --}}
@php
    $u = auth()->user();
    $wsReqs = \App\Models\SignRequest::where('contract_id', $row->id)->orderByDesc('created_at')->limit(10)->get();
    $wsSigners = \App\Models\ContractSigner::whereIn('request_id', $wsReqs->pluck('id'))
        ->orderBy('order')->get()->groupBy('request_id');
    $wsSteps = \Illuminate\Support\Facades\Schema::hasTable('contract_approval_steps')
        ? \App\Models\ContractApprovalStep::where('contract_id', $row->id)->orderByDesc('created_at')->orderBy('stage')->limit(20)->get()
        : collect();
    $wsObs = \Illuminate\Support\Facades\Schema::hasTable('contract_obligations')
        ? hub_scope(\App\Models\ContractObligation::query(), 'obligations')->where('contract_id', $row->id)->orderBy('due')->limit(50)->get()
        : collect();
    $wsChain = \App\Models\Contract::where('parent_id', $row->id)->orderByDesc('created_at')->limit(20)->get();
    $wsParent = $row->parent_id ? \App\Models\Contract::find($row->parent_id) : null;
    // تبويب المالية خلف صلاحية fin.v ونطاق المستخدم — رؤية العقد لا تمنح رؤية أرقامه المالية
    $wsFin = (hub_can($u, 'fin', 'v') && ($row->project_id || $row->company_id))
        ? hub_scope(\Illuminate\Support\Facades\DB::table('fin_documents')->whereNull('deleted_at'), 'fin')
            ->when($row->project_id,
                fn ($q) => $q->where('project_id', $row->project_id),
                fn ($q) => $q->where('company_id', $row->company_id))
            ->orderByDesc('date')->limit(10)->get()
        : collect();
    $wsUsers = \App\Models\User::whereIn('id', $wsObs->pluck('owner_id')->merge($wsSteps->pluck('approver_id'))->filter()->unique())->pluck('name', 'id');
    $cwNext = \App\Support\NextAction::for('contracts', $row);
@endphp
@if (! empty($cwNext))
    {{-- الفعلُ الأفضلُ التالي (محرّك NextAction): تجديدٌ وشيكٌ أو تذكيرُ توقيع --}}
    <div class="card">
        <h3 class="cardtitle">🎯 الخطوة التالية</h3>
        <div class="crow" style="flex-wrap:wrap;gap:8px">
            @foreach ($cwNext as $step)
                <a class="chip" href="{{ $step['url'] }}" title="{{ $step['why'] }}">{{ $step['primary'] ? '⭐ ' : '' }}{{ $step['label'] }}</a>
            @endforeach
        </div>
        <div class="sub" style="margin-top:6px">{{ $cwNext[0]['why'] }}</div>
    </div>
@endif
<style>
.cwtabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
.cwtabs button{border:1px solid var(--brd);background:transparent;color:inherit;border-radius:20px;padding:5px 12px;cursor:pointer;font-size:12.5px}
.cwtabs button.on{background:var(--p);color:#fff;border-color:var(--p)}
.cwpane[hidden]{display:none}
</style>
<div class="card" id="cworkspace">
    <h3 class="cardtitle">🗂 مساحة عمل العقد</h3>
    <div class="cwtabs" role="tablist" aria-label="أقسام مساحة عمل العقد">
        <button type="button" class="on" role="tab" aria-selected="true" data-cw="parties">✍️ الأطراف والتواقيع</button>
        <button type="button" role="tab" aria-selected="false" tabindex="-1" data-cw="approvals">🔏 الموافقات</button>
        <button type="button" role="tab" aria-selected="false" tabindex="-1" data-cw="obligations">📌 الالتزامات <span class="sub">({{ $wsObs->count() }})</span></button>
        @if (hub_can($u, 'fin', 'v'))
            <button type="button" role="tab" aria-selected="false" tabindex="-1" data-cw="fin">💰 المالية</button>
        @endif
        <button type="button" role="tab" aria-selected="false" tabindex="-1" data-cw="chain">🔗 سلسلة العقد</button>
    </div>

    <div class="cwpane" data-cwp="parties">
        @forelse ($wsReqs as $q)
            <div style="border:1px solid var(--brd);border-radius:10px;padding:8px 10px;margin-bottom:8px">
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <b>{{ $q->title }}</b>
                    <span class="bdg {{ $q->status === 'وُقّع' ? 'ok' : ($q->status === 'رُفض' ? 'bad' : 'wn') }}">{{ $q->status }}</span>
                    <span class="sub mono ltr">{{ $q->verify_code }}</span>
                    <span class="spacer"></span>
                    <a class="btn ghost xs" href="{{ route('esign.doc', $q->id) }}">📄</a>
                    @if ($q->status === 'وُقّع')<a class="btn ghost xs" href="{{ route('esign.cert', $q->id) }}">📜</a>@endif
                </div>
                @foreach ($wsSigners[$q->id] ?? [] as $s)
                    <div class="sub" style="margin-top:3px">
                        {{ $s->role === 'موقّع' ? '✍️' : ($s->role === 'شاهد' ? '👁' : '📧') }}
                        {{ $s->name }} — {{ $s->role }} ·
                        <span class="bdg {{ $s->status === 'وُقّع' ? 'ok' : ($s->status === 'رُفض' ? 'bad' : 'wn') }}" style="font-size:10px">{{ $s->status }}</span>
                        @if ($s->signed_at)<span class="mono">{{ $s->signed_at->format('Y-m-d H:i') }}</span>@endif
                    </div>
                @endforeach
            </div>
        @empty
            <div class="sub">لا طلبات توقيع على هذا العقد بعد — أنشئ واحداً من بطاقة التواقيع أعلاه.</div>
        @endforelse
    </div>

    <div class="cwpane" data-cwp="approvals" hidden>
        @forelse ($wsSteps as $st)
            <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px;flex-wrap:wrap">
                <span class="sub">مرحلة {{ $st->stage }}</span>
                <b>{{ $st->label ?: $st->kind }}</b>
                <span class="bdg {{ $st->status === 'موافق' ? 'ok' : ($st->status === 'مرفوض' ? 'bad' : 'wn') }}">{{ $st->status }}</span>
                @if ($st->decided_at)
                    <span class="sub">{{ $wsUsers[$st->decided_by] ?? '' }} · <span class="mono">{{ $st->decided_at->format('Y-m-d H:i') }}</span></span>
                @elseif ($st->approver_id)
                    <span class="sub">بانتظار {{ $wsUsers[$st->approver_id] ?? 'الموافِق' }}</span>
                @endif
                @if ($st->note)<span class="sub">— {{ $st->note }}</span>@endif
            </div>
        @empty
            <div class="sub">لا مراحل موافقة على طلبات هذا العقد — تُعرَّف عتباتها من الإعدادات ⚙️.</div>
        @endforelse
    </div>

    <div class="cwpane" data-cwp="obligations" hidden>
        @if ($wsObs->isNotEmpty())
            <div class="tblwrap"><table class="tbl">
                <thead><tr><th>الالتزام</th><th>الاستحقاق</th><th>المبلغ</th><th>المسؤول</th><th>الحالة</th></tr></thead>
                <tbody>
                @foreach ($wsObs as $o)
                    <tr>
                        <td><a href="{{ route('m.show', ['obligations', $o->id]) }}">{{ $o->title }}</a></td>
                        <td class="mono sub">{{ $o->due?->format('Y-m-d') ?: '—' }}</td>
                        <td class="mono">{{ $o->amount ? number_format($o->amount, 2) . ' ' . ($o->currency ?: '') : '—' }}</td>
                        <td class="sub">{{ $wsUsers[$o->owner_id] ?? '—' }}</td>
                        <td><span class="bdg {{ $o->status === 'مكتمل' ? 'ok' : ($o->status === 'متأخر' ? 'bad' : 'wn') }}">{{ $o->status ?: '—' }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @else
            <div class="sub">لا التزامات متتبعة لهذا العقد.</div>
        @endif
        @if (hub_can($u, 'obligations', 'a'))
            <a class="btn ghost sm" style="margin-top:8px"
               href="{{ route('m.create', ['obligations', 'contractId' => $row->id]) }}">＋ التزام جديد</a>
        @endif
    </div>

    <div class="cwpane" data-cwp="fin" hidden>
        @if ($wsFin->isNotEmpty())
            <div class="sub" style="margin-bottom:6px">مستندات {{ $row->project_id ? 'مشروع' : 'شركة' }} هذا العقد — الأحدث أولاً:</div>
            <div class="tblwrap"><table class="tbl">
                <thead><tr><th>الرقم</th><th>النوع</th><th>التاريخ</th><th>الإجمالي</th><th>الحالة</th></tr></thead>
                <tbody>
                @foreach ($wsFin as $f)
                    <tr>
                        <td><a class="mono" href="{{ route('m.show', ['fin', $f->id]) }}">{{ $f->doc_no }}</a></td>
                        <td class="sub">{{ $f->kind }}</td>
                        <td class="mono sub">{{ $f->date }}</td>
                        <td class="mono">{{ number_format((float) $f->total, 2) }} {{ $f->currency }}</td>
                        <td class="sub">{{ $f->state }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @else
            <div class="sub">لا مستندات مالية على {{ $row->project_id ? 'مشروع' : ($row->company_id ? 'شركة' : 'نطاق') }} هذا العقد — لا توجد بيانات مقارنة.</div>
        @endif
    </div>

    <div class="cwpane" data-cwp="chain" hidden>
        @if ($wsParent)
            <div style="margin-bottom:8px">⬆️ الأصل:
                <a href="{{ route('m.show', ['contracts', $wsParent->id]) }}">{{ $wsParent->doc_no }} — {{ $wsParent->title }}</a>
                <span class="bdg wn">{{ $row->kind ?: '' }}</span>
            </div>
        @endif
        @forelse ($wsChain as $ch)
            <div style="margin-bottom:6px">
                {{ $ch->kind === 'تجديد' ? '🔄' : '📎' }}
                <a href="{{ route('m.show', ['contracts', $ch->id]) }}">{{ $ch->doc_no }} — {{ $ch->title }}</a>
                <span class="bdg {{ hub_tone($ch->status) }}">{{ $ch->status }}</span>
                <span class="sub">{{ $ch->kind }}</span>
            </div>
        @empty
            @unless ($wsParent)<div class="sub" style="margin-bottom:6px">لا ملاحق ولا تجديدات لهذا العقد.</div>@endunless
        @endforelse
        @if (hub_can($u, 'contracts', 'a') && ! $row->trashed())
            <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
                <form method="POST" action="{{ route('contract.amend', $row->id) }}" class="inline">@csrf
                    <button class="btn ghost sm">📎 ملحق تعديل</button></form>
                {{-- التجديدُ يكتب «قيد التجديد» على العقد الأصل، فحارسُه يشترط
                     صلاحيةَ التعديل (v2.322). وكان الزرُّ يُعرض بصلاحية «إضافة»
                     وحدها — فيضغطه صاحبُها فيُردّ 403: خيارٌ معروضٌ لا يؤدّي شيئاً. --}}
                @if (hub_can($u, 'contracts', 'e'))
                    <form method="POST" action="{{ route('contract.renew', $row->id) }}" class="inline">@csrf
                        <button class="btn ghost sm">🔄 بدء التجديد</button></form>
                @endif
            </div>
        @endif
    </div>
</div>
<script>
(function () {
    var tabs = [].slice.call(document.querySelectorAll('#cworkspace .cwtabs button'));
    function activate(b) {
        tabs.forEach(function (x) {
            var on = x === b;
            x.classList.toggle('on', on);
            x.setAttribute('aria-selected', on ? 'true' : 'false');
            x.tabIndex = on ? 0 : -1;
        });
        document.querySelectorAll('#cworkspace .cwpane').forEach(function (p) {
            p.hidden = p.dataset.cwp !== b.dataset.cw;
            if (!p.hidden) { p.setAttribute('role', 'tabpanel'); }
        });
    }
    tabs.forEach(function (b, i) {
        b.addEventListener('click', function () { activate(b); });
        // أسهم الكيبورد بين التبويبات — نمط tablist المعياري
        b.addEventListener('keydown', function (e) {
            var d = e.key === 'ArrowLeft' ? 1 : e.key === 'ArrowRight' ? -1 : 0;   // RTL
            if (!d) return;
            e.preventDefault();
            var n = tabs[(i + d + tabs.length) % tabs.length];
            activate(n); n.focus();
        });
    });
})();
</script>
