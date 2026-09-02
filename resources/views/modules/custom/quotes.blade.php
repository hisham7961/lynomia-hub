{{-- بنّاء العرض المهنيّ: بنودٌ مهيكلة، مراحلُ دفع، وربحيةٌ داخلية مخفيّة عن العميل --}}
@php
    $qLines = $row->lines()->get();
    $qMs = $row->milestones()->get();
    $qLocked = in_array($row->status, ['مقبول', 'محوّل'], true);
    $canEdit = hub_can(auth()->user(), 'quotes', 'e') && ! $qLocked;
    // الربحيةُ الداخلية تظهر فقط لمن لا يُخفى عنه حقلُ التكلفة (قواعد الدور)
    $showInternal = hub_field_mode(auth()->user(), 'quotes', 'cost') !== 'hide';
    // كتالوجُ الخدمات المنطَّق — لملء البند من مصدرٍ واحد (CPQ)
    $qServices = \Illuminate\Support\Facades\Schema::hasTable('services')
        ? hub_scope(\App\Models\Service::query(), 'services')->orderBy('name')->limit(400)->get(['id', 'name'])
        : collect();
    $modeLabels = ['required' => 'أساسيّ', 'optional' => 'اختياريّ', 'alternative' => 'بديل', 'addon' => 'إضافة'];
    $qMeta = (array) $row->meta;
@endphp

{{-- عرض ٣٦٠: سلسلةُ الأثر التجاريّ — من العميل حتى المشروع والفاتورة بنقرة --}}
@if ($row->client_id || array_intersect_key($qMeta, array_flip(['contract_id','engagement_id','project_id','invoice_id'])))
    <div class="card">
        <h3 class="cardtitle">🧭 سلسلةُ الأثر التجاريّ</h3>
        <div class="crow" style="flex-wrap:wrap;gap:6px">
            @if ($row->client_id)<a class="chip" href="{{ route('m.show', ['clients', $row->client_id]) }}">👤 العميل</a><span class="sub">›</span>@endif
            <span class="chip">🧾 {{ $row->doc_no }}</span>
            @if (! empty($qMeta['contract_id']))<span class="sub">›</span><a class="chip" href="{{ route('m.show', ['contracts', $qMeta['contract_id']]) }}">📜 العقد</a>@endif
            @if (! empty($qMeta['engagement_id']))<span class="sub">›</span><a class="chip" href="{{ route('m.show', ['engagements', $qMeta['engagement_id']]) }}">🤝 الارتباط</a>@endif
            @if (! empty($qMeta['project_id']))<span class="sub">›</span><a class="chip" href="{{ route('m.show', ['projects', $qMeta['project_id']]) }}">🗂️ المشروع</a>@endif
            @if (! empty($qMeta['invoice_id']))<span class="sub">›</span><a class="chip" href="{{ route('m.show', ['fin', $qMeta['invoice_id']]) }}">🧾 الفاتورة</a>@endif
        </div>
        <div class="sub" style="margin-top:6px">مصادرُ الحقيقة موصولة — العرضُ يشير لها لا ينسخها.</div>
    </div>
@endif

{{-- الفعلُ الأفضلُ التالي (محرّك NextAction) — الخطوةُ المنطقيةُ حسب حالة العرض --}}
@php $qNext = \App\Support\NextAction::for('quotes', $row); @endphp
@if (! empty($qNext))
    <div class="card">
        <h3 class="cardtitle">🎯 الخطوة التالية</h3>
        <div class="crow" style="flex-wrap:wrap;gap:8px">
            @foreach ($qNext as $step)
                <a class="chip" href="{{ $step['url'] }}" title="{{ $step['why'] }}">{{ $step['primary'] ? '⭐ ' : '' }}{{ $step['label'] }}</a>
            @endforeach
        </div>
        <div class="sub" style="margin-top:6px">{{ $qNext[0]['why'] }}</div>
    </div>
@endif

<div class="card">
    <h3 class="cardtitle">🧾 بنود العرض
        <span class="bdg">{{ $qLines->count() }} بند</span>
        @if ($qLocked)<span class="bdg g">مجمَّد ({{ $row->status }})</span>@endif
    </h3>
    @if ($qLines->isNotEmpty())
        <div class="tblwrap"><table>
            <thead><tr><th>النمط</th><th>النوع</th><th>البند</th><th>المرحلة</th><th>كمية</th><th>سعر الوحدة</th><th>الإجمالي</th>@if ($canEdit)<th></th>@endif</tr></thead>
            <tbody>
            @foreach ($qLines as $l)
                @php $lm = $l->line_mode ?: 'required'; $committed = $l->countsToward(); @endphp
                <tr @if (! $committed) style="opacity:.62" @endif>
                    <td>
                        <span class="bdg {{ $lm === 'required' ? '' : 'wn' }}">{{ $modeLabels[$lm] ?? $lm }}</span>
                        @if ($lm === 'alternative' && $l->opt_group)<div class="sub" style="font-size:11px">{{ $l->opt_group }}</div>@endif
                    </td>
                    <td class="sub">{{ $l->kind ?: '—' }}</td>
                    <td>{{ $l->title }}@if ($l->description)<div class="sub">{{ \Illuminate\Support\Str::limit($l->description, 60) }}</div>@endif</td>
                    <td class="sub">{{ $l->phase ?: '—' }}</td>
                    <td class="mono">{{ rtrim(rtrim(number_format((float) $l->qty, 3), '0'), '.') }}</td>
                    <td class="mono">{{ number_format((float) $l->unit_price, 3) }}</td>
                    <td class="mono"><b>{{ number_format((float) $l->line_total, 3) }}</b>@if (! $committed)<div class="sub" style="font-size:11px">غير مُدرَج</div>@endif</td>
                    @if ($canEdit)
                        <td class="crow" style="gap:4px">
                            @if ($lm !== 'required')
                                <form method="POST" action="{{ route('quotes.line.toggle', [$row->id, $l->id]) }}" class="inline">@csrf<button class="btn ghost xs">{{ $committed ? 'إخراج' : 'إدراج' }}</button></form>
                            @endif
                            <form method="POST" action="{{ route('quotes.line.destroy', [$row->id, $l->id]) }}" class="inline">@csrf @method('DELETE')<button class="btn ghost xs bad" data-confirm="حذف البند؟">حذف</button></form>
                        </td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table></div>
        <div class="crow" style="margin-top:8px">
            @if ((float) $row->discount > 0)<span class="chip">الصافي قبل الخصم: <b class="mono">{{ number_format((float) $row->amount + (float) $row->discount, 3) }}</b></span><span class="chip">الخصم: <b class="mono">−{{ number_format((float) $row->discount, 3) }}</b></span>@endif
            <span class="chip">الصافي{{ (float) $row->discount > 0 ? ' الخاضع' : '' }}: <b class="mono">{{ number_format((float) $row->amount, 3) }}</b></span>
            <span class="chip">الضريبة: <b class="mono">{{ number_format((float) $row->tax, 3) }}</b></span>
            <span class="chip">الإجمالي: <b class="mono">{{ number_format((float) $row->total, 3) }} {{ $row->currency }}</b></span>
        </div>
    @else
        <div class="sub">لا بنود بعد — أضِف بنداً ليُحسَب الإجماليُّ تلقائياً.</div>
    @endif

    @if ($canEdit)
        <form method="POST" action="{{ route('quotes.line.store', $row->id) }}" style="margin-top:12px">
            @csrf
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px">
                @if ($qServices->isNotEmpty())
                    <select class="inp" name="service_id" title="من كتالوج الخدمات — يملأ الفراغ">
                        <option value="">من الكتالوج…</option>
                        @foreach ($qServices as $svc)<option value="{{ $svc->id }}">{{ $svc->name }}</option>@endforeach
                    </select>
                @endif
                <input class="inp" name="title" placeholder="وصف البند (أو من الكتالوج)">
                <select class="inp" name="kind"><option value="">النوع…</option>@foreach (['خدمة','مرحلة','تسليم','رسوم ثابتة','بالساعة','اشتراك','بنية تحتية','رسوم إعداد','صيانة','تكلفة طرف ثالث','مخصص'] as $k)<option>{{ $k }}</option>@endforeach</select>
                <select class="inp" name="line_mode" title="نمط البند">
                    <option value="required">أساسيّ</option>
                    <option value="optional">اختياريّ</option>
                    <option value="alternative">بديل</option>
                    <option value="addon">إضافة</option>
                </select>
                <input class="inp" name="opt_group" placeholder="مجموعة البدائل (للبديل)">
                <input class="inp" name="phase" placeholder="المرحلة (اختياري)">
                <input class="inp mono" name="qty" type="number" step="0.001" value="1" placeholder="الكمية">
                <input class="inp mono" name="unit_price" type="number" step="0.001" placeholder="سعر الوحدة">
                <input class="inp mono" name="discount_pct" type="number" step="0.01" placeholder="خصم %">
                <input class="inp mono" name="tax_pct" type="number" step="0.01" placeholder="ضريبة %">
                @if ($showInternal)<input class="inp mono" name="unit_cost" type="number" step="0.001" placeholder="تكلفة (داخليّ)">@endif
                <select class="inp" name="rev_type" title="تصنيف الإيراد">
                    <option value="one_time">إيرادٌ لمرّة</option>
                    <option value="recurring">إيرادٌ دوريّ</option>
                    <option value="usage">حسب الاستخدام</option>
                    <option value="pass_through">تكلفةٌ ممرَّرة</option>
                </select>
                <select class="inp" name="rev_period" title="دوريّة المتكرّر"><option value="">— الدوريّة —</option><option>شهري</option><option>سنوي</option></select>
            </div>
            <div class="sub" style="margin-top:4px">النمط «اختياريّ/بديل/إضافة» لا يدخل الإجماليَّ حتى تُدرِجه — والبديلُ واحدٌ من مجموعته.</div>
            <button class="btn p sm" style="margin-top:8px">➕ أضف بنداً</button>
        </form>
    @endif
</div>

@if ($showInternal)
    @php $cs = $row->commercialSummary(); $floor = (float) setting('quotes.margin_floor', 0); @endphp
    <div class="card">
        <h3 class="cardtitle">💰 الملخّص التجاريّ والربحية الداخلية <span class="bdg wn">لا يظهر للعميل</span></h3>
        <div style="display:flex;gap:18px;flex-wrap:wrap">
            <div><div class="sub">إيرادٌ لمرّة</div><b class="mono">{{ number_format($cs['one_time'], 3) }} {{ $row->currency }}</b></div>
            <div><div class="sub">شهريّ MRR</div><b class="mono">{{ number_format($cs['mrr'], 3) }}</b></div>
            <div><div class="sub">سنويّ ARR</div><b class="mono">{{ number_format($cs['arr'], 3) }}</b></div>
            <div><div class="sub">قيمة العقد TCV</div><b class="mono">{{ number_format($cs['tcv'], 3) }}</b></div>
            @if (($cs['upside'] ?? 0) > 0)<div><div class="sub">فرصةٌ عُلويّة (اختياريّ)</div><b class="mono">{{ number_format($cs['upside'], 3) }}</b></div>@endif
            <div><div class="sub">التكلفة</div><b class="mono">{{ number_format($cs['cost'], 3) }}</b></div>
            <div><div class="sub">الربح</div><b class="mono">{{ number_format((float) $row->total - $cs['cost'], 3) }}</b></div>
            <div><div class="sub">الهامش</div>
                @if ($cs['margin'] !== null)<b class="mono {{ ($floor > 0 ? $cs['margin'] < $floor : $cs['margin'] < 20) ? 'txt-bad' : '' }}">{{ $cs['margin'] }}%</b>@else<span class="sub">—</span>@endif
                @if ($floor > 0)<span class="sub"> (الحدّ {{ $floor }}%)</span>@endif
            </div>
        </div>
    </div>
@endif

@php $qc = $row->qualityCheck(); @endphp
@if ($canEdit && ! empty($qc))
    <div class="card" style="border-color:var(--wn)">
        <h3 class="cardtitle">🧪 فحص الجودة التجاريّ <span class="bdg wn">{{ count($qc) }} تنبيه</span></h3>
        <div class="sub" style="margin-bottom:6px">راجِعها قبل الإرسال — بعضُها قد يستوجب اعتماداً.</div>
        <ul style="margin:0;padding-inline-start:20px">
            @foreach ($qc as $issue)<li style="margin-bottom:4px">{{ $issue }}</li>@endforeach
        </ul>
    </div>
@endif

<div class="card">
    <h3 class="cardtitle">📅 جدول المدفوعات <span class="bdg">{{ $qMs->count() }}</span></h3>
    @if ($qMs->isNotEmpty())
        @php
            $pctSum = $qMs->sum(fn ($m) => (float) $m->pct);
            // بعد القبول يصير الجدولُ التزاماً: البلوغُ والفوترةُ (v2.399) تُتاح هنا
            // تحديداً — الجدولُ نفسُه مجمَّدٌ في البنّاء (لا إضافةَ ولا حذف).
            $msTrack = $qLocked && \Illuminate\Support\Facades\Schema::hasColumn('quote_milestones', 'reached_at');
            $msCanReach = $msTrack && hub_can(auth()->user(), 'quotes', 'e');
            // فاتورةٌ كاملةٌ حيّةٌ للعرض (do=invoice) تمنع فواتيرَ الدفعات — لا ازدواجَ فوترة
            $msFullInv = $msTrack && $row->hasLiveFullInvoice();
            // عرضٌ لم يُسعَّر (إجماليُّه صفر): سقفُه صفرٌ فلا سكَّ — ويُقال سببُه لا «أَلغِ فاتورةً»
            $msZeroTotal = $msTrack && (float) $row->total <= 0;
            // سقفُ العقد: ما بقي من إجماليّ العرض بعد فواتير الدفعات الحيّة — صفرٌ فلا زرَّ سكّ
            $msRemaining = $msTrack ? round((float) $row->total - $row->liveMilestoneInvoicedTotal(), 3) : 0.0;
            $msCapped = $msTrack && ! $msFullInv && ! $msZeroTotal && $msRemaining <= 0;
            // السكُّ فعلٌ على العرض (quotes:e — كما يفرضه المتحكّم) ويُنشئ مستنداً ماليّاً (fin:a)
            $msCanInvoice = $msTrack && ! $msFullInv && ! $msCapped && ! $msZeroTotal && hub_can(auth()->user(), 'quotes', 'e') && hub_can(auth()->user(), 'fin', 'a');
            // رقمُ الفاتورة ورابطُها لمن يرى الماليّة؛ وغيرُه يرى أنّ فاتورةً سُكّت لا أكثر
            $msFinView = hub_can(auth()->user(), 'fin', 'v');
            // فواتيرُ المعالم كلُّها — الحاليّةُ والسوابقُ (قد تعود سابقةٌ إلى الحياة من الماليّة)
            $msInvIds = $msTrack ? $qMs->flatMap(fn ($m) => $m->invoiceIds())->unique()->values()->all() : [];
            $msInvoices = $msInvIds
                ? \App\Models\FinDocument::withTrashed()->whereIn('id', $msInvIds)->get(['id', 'doc_no', 'state', 'deleted_at'])->keyBy('id')
                : collect();
            $finDead = (array) config('hub.fin.dead', []);
            $msIsLive = fn ($fd) => $fd && ! $fd->trashed() && ($fd->state === null || ! in_array((string) $fd->state, $finDead, true));
        @endphp
        <div class="tblwrap"><table>
            <thead><tr><th>الدفعة</th><th>النسبة</th><th>المبلغ</th><th>المحفّز</th>@if ($msTrack)<th>الحالة</th><th>الفاتورة</th>@endif @if ($canEdit)<th></th>@endif</tr></thead>
            <tbody>
            @foreach ($qMs as $m)
                @php
                    // فاتورةُ المعلم المعروضة: الحيّةُ إن وُجدت (الحاليّةُ أو سابقةٌ عادت إلى الحياة —
                    // القاعدةُ نفسُها التي يحكم بها المتحكّم)، وإلا الحاليّةُ الميتةُ مع بيان حالِها
                    $mLiveId = collect($m->invoiceIds())->first(fn ($id) => $msIsLive($msInvoices[$id] ?? null));
                    $mInv = $mLiveId ? $msInvoices[$mLiveId] : ($m->invoice_id ? ($msInvoices[$m->invoice_id] ?? null) : null);
                    $mInvLive = (bool) $mLiveId;
                    $mInvNote = $mInv && ! $mInvLive ? ' (' . ($mInv->trashed() ? 'محذوفة' : $mInv->state) . ')' : '';
                    // قيمةُ الدفعة بقاعدة المتحكّم نفسِها (`amountDue`، مقرَّبةً) — ما يُقرَّب إلى صفر لا يُسكّ فلا يُعرَض زرُّه
                    $mVal = $m->amountDue($row);
                @endphp
                <tr>
                    <td>{{ $m->title }}</td>
                    <td class="mono">{{ (float) $m->pct ? (float) $m->pct . '%' : '—' }}</td>
                    <td class="mono">{{ (float) $m->amount ? number_format((float) $m->amount, 3) : ((float) $m->pct ? number_format((float) $row->total * (float) $m->pct / 100, 3) : '—') }}</td>
                    <td class="sub">{{ $m->trigger ?: '—' }}</td>
                    @if ($msTrack)
                        <td>
                            @if ($m->reached_at)
                                <span class="bdg ok" title="أُعلن البلوغ">🏁 بُلغ {{ $m->reached_at->toDateString() }}</span>
                                @if ($msCanReach && ! $mInvLive)
                                    <form method="POST" action="{{ route('quotes.act', $row->id) }}" class="inline">@csrf
                                        <input type="hidden" name="do" value="ms.unreach"><input type="hidden" name="ms" value="{{ $m->id }}">
                                        <button class="btn ghost xs" data-confirm="التراجع عن إعلان البلوغ؟">↩︎</button>
                                    </form>
                                @endif
                            @elseif ($msCanReach)
                                <form method="POST" action="{{ route('quotes.act', $row->id) }}" class="inline">@csrf
                                    <input type="hidden" name="do" value="ms.reach"><input type="hidden" name="ms" value="{{ $m->id }}">
                                    <button class="btn ghost xs">🏁 بُلغ</button>
                                </form>
                            @else
                                <span class="sub">لم يُبلَغ</span>
                            @endif
                        </td>
                        <td>
                            @if ($mInv && $msFinView)
                                <a class="chip" href="{{ route('m.show', ['fin', $mInv->id]) }}" title="{{ $mInv->state }}">🧾 {{ $mInv->doc_no }}{{ $mInvNote }}</a>
                            @elseif ($mInv)
                                <span class="chip" title="تفاصيلُ الفاتورة لمن يرى الماليّة">🧾 فاتورةٌ مسكوكة{{ $mInvNote }}</span>
                            @endif
                            @if (! $mInvLive && $msCanInvoice && $mVal > 0)
                                <form method="POST" action="{{ route('quotes.act', $row->id) }}" class="inline">@csrf
                                    <input type="hidden" name="do" value="ms.invoice"><input type="hidden" name="ms" value="{{ $m->id }}">
                                    <button class="btn ghost xs" data-confirm="سكُّ فاتورةِ هذه الدفعة الآن؟">🧾 سُكّ الفاتورة</button>
                                </form>
                            @elseif (! $mInv)
                                <span class="sub">—</span>
                            @endif
                        </td>
                    @endif
                    @if ($canEdit)<td><form method="POST" action="{{ route('quotes.ms.destroy', [$row->id, $m->id]) }}" class="inline">@csrf @method('DELETE')<button class="btn ghost xs bad" data-confirm="حذف؟">حذف</button></form></td>@endif
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @if ($pctSum > 0)<div class="sub" style="margin-top:6px {{ abs($pctSum - 100) > 0.01 ? ';color:var(--bad,inherit)' : '' }}">مجموع النسب: {{ rtrim(rtrim(number_format($pctSum, 2), '0'), '.') }}%@if (abs($pctSum - 100) > 0.01) — يُفترض ١٠٠٪@endif</div>@endif
        @if ($msFullInv ?? false)<div class="sub" style="margin-top:4px">🧾 للعرض فاتورةٌ كاملةٌ حيّة — الدفعاتُ لا تُفوتَر فوقها (أَلغِها من الماليّة إن كان القصدُ الفوترةَ بالدفعات).</div>
        @elseif ($msZeroTotal ?? false)<div class="sub" style="margin-top:4px">🧾 إجماليُّ العرض صفر — لا تُسكّ دفعاتٌ على عرضٍ لم يُسعَّر (اضبط إجماليَّه أولاً).</div>
        @elseif ($msCapped ?? false)<div class="sub" style="margin-top:4px">🧾 فواتيرُ الدفعات الحيّةُ تغطّي إجماليَّ العرض — لا تُسكّ دفعةٌ أخرى (أَلغِ إحداها من الماليّة إن كان ثمّة خطأ).</div>
        @elseif ($msTrack)<div class="sub" style="margin-top:4px">بعد القبول يصير الجدولُ التزاماً: أعلِن بلوغَ الدفعة حين تتحقّق، وسُكّ فاتورتَها — معلمٌ بُلغ ولم يُفوتَر ٣ أيامٍ يظهر في مركز التوصيات.@if ($msRemaining < (float) $row->total) المتبقّي من الإجماليّ بعد الفواتير الحيّة: <span class="mono">{{ number_format($msRemaining, 3) }}</span>.@endif</div>@endif
    @else
        <div class="sub">لا مدفوعات مجدولة — أضِف دفعاتٍ (٣٠٪ عند القبول، ٤٠٪ بعد المرحلة٢…).</div>
    @endif
    @if ($canEdit)
        <form method="POST" action="{{ route('quotes.ms.store', $row->id) }}" style="margin-top:10px">
            @csrf
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px">
                <input class="inp" name="title" placeholder="عنوان الدفعة *" required>
                <input class="inp mono" name="pct" type="number" step="0.01" placeholder="نسبة %">
                <input class="inp" name="trigger" placeholder="المحفّز (عند القبول…)">
            </div>
            <button class="btn p sm" style="margin-top:8px">➕ أضف دفعة</button>
        </form>
    @endif
</div>

@if ($canEdit)
    {{-- أقسامُ العرض الديناميكية: أيّ الأقسامِ السرديّة تظهر في مستند العميل --}}
    @php $qHidden = $row->hiddenSections(); @endphp
    <div class="card">
        <h3 class="cardtitle">🧩 أقسامُ مستند العميل</h3>
        <div class="sub" style="margin-bottom:8px">اختَر ما يظهر في العرض المطبوع — التسعيرُ والغلافُ والقبولُ ثابتةٌ دائماً.</div>
        <form method="POST" action="{{ route('quotes.act', $row->id) }}">@csrf
            <input type="hidden" name="do" value="sections">
            <div class="crow" style="flex-wrap:wrap;gap:10px">
                @foreach (\App\Models\Quote::PROPOSAL_SECTIONS as $key => $label)
                    <label class="chip"><input type="checkbox" name="show[]" value="{{ $key }}" @checked(! in_array($key, $qHidden, true))> {{ $label }}</label>
                @endforeach
            </div>
            <button class="btn ghost sm" style="margin-top:10px">حفظ الأقسام</button>
        </form>
    </div>

    {{-- مكتبةُ الشروط: إدراجُ شروطِ عرضٍ قالبٍ جاهز بلا إعادة كتابة --}}
    @php
        $qTermTpls = hub_scope(\App\Models\Quote::query(), 'quotes')->where('is_template', true)
            ->whereNotNull('terms')->where('terms', '!=', '')->orderBy('title')->limit(50)->get(['id', 'doc_no', 'title']);
    @endphp
    @if ($qTermTpls->isNotEmpty())
        <div class="card">
            <h3 class="cardtitle">📚 مكتبةُ الشروط</h3>
            <div class="sub" style="margin-bottom:8px">أدرِج شروطاً جاهزةً من عرضٍ قالب — تُلحَق بشروطِك أو تستبدلها.</div>
            <form method="POST" action="{{ route('quotes.act', $row->id) }}" class="crow" style="flex-wrap:wrap;gap:8px">@csrf
                <input type="hidden" name="do" value="terms">
                <select class="inp" name="from" required>
                    <option value="">— اختَر قالباً —</option>
                    @foreach ($qTermTpls as $t)
                        <option value="{{ $t->id }}">{{ $t->title ?: $t->doc_no }}</option>
                    @endforeach
                </select>
                <select class="inp" name="mode">
                    <option value="append">إلحاق</option>
                    <option value="replace">استبدال</option>
                </select>
                <button class="btn ghost sm">إدراج الشروط</button>
            </form>
        </div>
    @endif
@endif
