{{-- (WP-6.1) غرفةُ قيادة الحادثة — يتوقع $row و$module من modules/show:84.
     الرأسُ يجيب أسئلةَ §8.1 الخمسة: ما شدّتُها؟ من قائدُها؟ متى بدأت وكُشفت
     وأُقرّت وحُلّت؟ وكم دامت؟ — و«أُقرّت» من سكّة record_acks نفسِها التي
     ترسم بطاقةَ الإقرار في هذه الصفحة، لا من عمودٍ ثانٍ يتخلّف عنها. --}}
@php
    $ihLead = $row->lead_id ? (hub_ref_labels('users', [$row->lead_id])[$row->lead_id] ?? 'مستخدم محذوف') : null;
    $ihAck = \App\Support\Acks::state('incidents', $row);
    $ihAckAt = $ihAck ? (collect($ihAck['people'])->firstWhere('acked', true)['at'] ?? null) : null;

    /*
     * المدّة = `resolved_at - started_at` **بالدقائق**، وبسقوطٍ إلى `downtime_min`.
     * نفسُ تعريف MTTR في `hub_app_quality` (app/Support/helpers.php —
     * `AVG(downtime_min) mttr` بالدقائق، وتعرضه شاشةُ جودة التطبيقات بلاحقة «د»)
     * — كي لا تقول هذه الشاشةُ رقماً وشاشةُ الجودة رقماً آخر عن الحادثة نفسِها.
     */
    $ihDur = ($row->started_at && $row->resolved_at)
        ? (int) round($row->started_at->diffInMinutes($row->resolved_at))
        : ($row->downtime_min !== null ? (int) $row->downtime_min : null);
    $ihRec = $row->downtime_min !== null ? (int) $row->downtime_min : null;
    $ihGap = ($row->started_at && $row->detected_at)
        ? (int) round($row->started_at->diffInMinutes($row->detected_at)) : null;

    $ihHigh = in_array((string) $row->severity, ['حرج', 'عالي'], true);
    $ihSevTone = $ihHigh ? 'bad' : ((string) $row->severity === 'متوسط' ? 'wn' : '');
    $ihFmt = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('Y-m-d H:i') : null;
    $ihSecurity = ($row->kind ?? data_get($row->meta, 'kind')) === 'security';
@endphp

<div class="card">
    <h3 class="cardtitle">🚨 غرفة قيادة الحادثة
        @if ($row->severity)<span class="bdg {{ $ihSevTone }}">{{ $row->severity }}</span>@endif
        @if ($row->status)<span class="bdg {{ hub_tone($row->status) }}">{{ $row->status }}</span>@endif
        @if ($ihSecurity)<span class="bdg i">🛡️ حادثة أمنيّة</span>@endif
    </h3>

    {{-- القائد أولاً: حادثةٌ بلا قائدٍ تُقال كما هي — لا «أُقرّت» ولا صمت --}}
    @if ($ihLead)
        <div class="sub" style="margin-bottom:8px">👤 قائد الحادثة: <b>{{ $ihLead }}</b>
            @if (data_get($row->meta, 'auto')) · فُتحت آليّاً وأُسند القائدُ افتراضياً — أعد الإسناد لمن يتولّاها فعلاً @endif
        </div>
    @else
        <div class="sub" style="margin-bottom:8px;color:var(--bad)">⚠️ <b>بلا قائد</b> —
            لا أحد مسؤولٌ عن هذه الحادثة الآن، ولا يُقرّ تسلُّمَها أحدٌ حتى يُسند «قائد الحادث» من التعديل.
        </div>
    @endif

    <div class="cards">
        <div class="stat"><span class="ico">🕒</span>
            <b>{{ $ihFmt($row->started_at) ?? '—' }}</b><span>بدأت</span></div>
        <div class="stat"><span class="ico">🔎</span>
            <b>{{ $ihFmt($row->detected_at) ?? '—' }}</b>
            <span>كُشفت @if ($ihGap !== null) · فجوة الكشف {{ $ihGap }} د @endif</span></div>
        <div class="stat"><span class="ico">🖊️</span>
            @if ($ihAckAt)
                <b>{{ $ihFmt($ihAckAt) }}</b><span>أُقرّت — تسلّمها القائد</span>
            @elseif ($ihLead)
                <b class="txt-bad">لم تُقرّ بعد</b><span>القائد لم يُقرّ تسلُّمَها</span>
            @else
                <b class="txt-bad">بلا قائد</b><span>لا مُقِرَّ لها أصلاً</span>
            @endif
        </div>
        <div class="stat"><span class="ico">✅</span>
            <b>{{ $ihFmt($row->resolved_at) ?? '—' }}</b><span>حُلّت (استُعيدت الخدمة)</span></div>
        <div class="stat"><span class="ico">⏱️</span>
            @if ($ihDur !== null)
                <b class="mono" data-ih-dur>{{ $ihDur }}<small> د</small></b>
                <span>المدّة @if (! ($row->started_at && $row->resolved_at)) · من «مدة التعطل» المسجّلة @endif</span>
            @else
                <b>—</b><span>لا مدّة بعد — سجّل وقتَ الاستعادة أو مدةَ التعطل</span>
            @endif
        </div>
    </div>

    {{-- رقمان عن الشيء الواحد يتناقضان؟ يُقال لا يُخفى — فشاشة الجودة تقرأ المسجَّل --}}
    @if ($row->started_at && $row->resolved_at && $ihRec !== null && abs($ihDur - $ihRec) > 1)
        <div class="sub" style="margin-top:8px;color:var(--wn)">
            ⚠️ المحسوب من الطابعين {{ $ihDur }} د و«مدة التعطل» المسجّلة {{ $ihRec }} د —
            وحّدهما، فمؤشّر MTTR في شاشة الجودة يقرأ المسجّلةَ.
        </div>
    @endif
</div>

{{-- ── Control Plane: Phase 6 (WP-6.2 · §8.3) ── **الأثرُ في نافذة الحادثة**.
     المصارحةُ هنا جوهرُ البطاقة: `error_events.count/users` عدّاداتٌ **تراكمية**
     منذ أول ظهور الخطأ — لو عُرضت هنا لقالت الشاشةُ «أصاب ٤٠٠ مستخدماً» عن
     حادثةِ ساعتين، وهو رقمٌ مخترَع. فالعددُ يُحسب من `error_occurrences` (وقوعةٌ
     لها لحظة) ضمن [بدأت، حُلّت|الآن]، والتعطّلُ من سلسلة `up` في `metric_points`
     للخادم المرتبط — وحيث لا قياسَ يُقال «لا قياس» لا صفر. --}}
@php
    $ihFrom = $row->started_at;
    $ihTo = $row->resolved_at ?: now();
    $ihWin = $ihFrom && $ihTo && $ihFrom <= $ihTo;

    // ثلاثةُ أرقامٍ باستعلامٍ واحد لا بثلاثة: الصفحةُ تُفتح في وسط حادثةٍ حيّة.
    // و`count(distinct user_id)` يُسقط NULL في المحرّكين معاً — فهو نفسُه
    // «المسجَّلون وحدَهم»، والمدى على العمود المفهرس (eo_at_idx) لا whereDate.
    $ihOcc = $ihErrWin = $ihUsers = 0;
    $ihOccTbl = $ihWin && \Illuminate\Support\Facades\Schema::hasTable('error_occurrences');
    if ($ihOccTbl) {
        $ihR = \Illuminate\Support\Facades\DB::table('error_occurrences')
            ->where('occurred_at', '>=', $ihFrom)->where('occurred_at', '<=', $ihTo)
            ->selectRaw('count(*) as occ, count(distinct error_event_id) as errs, count(distinct user_id) as usrs')
            ->first();
        $ihOcc = (int) ($ihR->occ ?? 0);
        $ihErrWin = (int) ($ihR->errs ?? 0);
        $ihUsers = (int) ($ihR->usrs ?? 0);
    }

    $ihChecks = $ihDown = 0;
    $ihUpTbl = $ihWin && $row->server_id && \Illuminate\Support\Facades\Schema::hasTable('metric_points');
    if ($ihUpTbl) {
        // فهرسُ (module, record_id, metric, at) القائم يخدم الشرطَ كما هو،
        // والمتعثّرُ يُعدّ بـCASE داخل الاستعلام نفسِه (لا استعلامَ ثانٍ)
        $ihU = \Illuminate\Support\Facades\DB::table('metric_points')
            ->where('module', 'servers')->where('record_id', $row->server_id)->where('metric', 'up')
            ->where('at', '>=', $ihFrom)->where('at', '<=', $ihTo)
            ->selectRaw('count(*) as checks, sum(case when value = 0 then 1 else 0 end) as down')
            ->first();
        $ihChecks = (int) ($ihU->checks ?? 0);
        $ihDown = (int) ($ihU->down ?? 0);
    }

    // الوحداتُ المتأثّرة من مراجع الأدلّة — والمحجوبُ يُعدّ ولا يُسمّى
    $ihMods = \Illuminate\Support\Facades\Schema::hasTable('incident_links')
        ? \Illuminate\Support\Facades\DB::table('incident_links')->where('incident_id', $row->id)
            ->whereNotNull('module')
            ->select('module', \Illuminate\Support\Facades\DB::raw('count(*) as n'))
            ->groupBy('module')->orderBy('module')->limit(12)->get()
        : collect();
    $ihModsSeen = $ihMods->filter(fn ($m) => hub_can(auth()->user(), $m->module, 'v'));
    $ihModsBlind = $ihMods->count() - $ihModsSeen->count();
@endphp

<div class="card">
    <h3 class="cardtitle">📉 الأثر في نافذة الحادثة</h3>
    @if (! $ihWin)
        @include('partials.empty', ['icon' => '📉',
            'text' => 'لا نافذةَ بعد — سجّل «بدأت» (ووقتَ الاستعادة إن انتهت) ليُحسب الأثرُ من مصادره لا من عدّاداتٍ تراكمية'])
    @else
        <div class="sub" style="margin-bottom:8px">
            النافذة: <bdi class="mono ltr">{{ $ihFrom->format('Y-m-d H:i') }}</bdi> ←
            <bdi class="mono ltr">{{ \Illuminate\Support\Carbon::parse($ihTo)->format('Y-m-d H:i') }}</bdi>
            @if (! $row->resolved_at) <span class="bdg wn">مستمرّة — الحدّ الأعلى هو الآن</span> @endif
        </div>
        <div class="cards">
            <div class="stat"><span class="ico">🐞</span>
                <b class="mono" data-ih-errwin>{{ $ihErrWin }}</b><span>أخطاء نشطة في النافذة</span></div>
            <div class="stat"><span class="ico">📌</span>
                <b class="mono" data-ih-occ>{{ $ihOcc }}</b><span>وقوعاتٌ مسجَّلة داخلها</span></div>
            <div class="stat"><span class="ico">👥</span>
                <b class="mono" data-ih-users>{{ $ihUsers }}</b><span>مستخدمون أصابهم عطلٌ (المسجَّلون وحدَهم)</span></div>
            <div class="stat"><span class="ico">📴</span>
                @if ($ihUpTbl && $ihChecks)
                    <b class="mono" data-ih-down>{{ $ihDown }}</b>
                    <span>فحصُ إتاحةٍ متعثّر من {{ $ihChecks }}</span>
                @else
                    {{-- «لا قياس» ليس «صفراً»: صفرٌ هنا يقول «لم تتعثّر الخدمة» وهو
                         ادّعاءٌ لا دليلَ عليه حين لا فحصَ أصلاً — فالشرطةُ أصدق --}}
                    <b class="mono">—</b>
                    <span>{{ $row->server_id ? 'لا فحوصَ إتاحةٍ في النافذة — لا قياسَ لا صفر' : 'بلا خادمٍ مرتبط — لا سلسلةَ إتاحةٍ تُقرأ' }}</span>
                @endif
            </div>
        </div>

        @if ($ihModsSeen->isNotEmpty() || $ihModsBlind)
            <div class="sub" style="margin-top:8px">🎯 <b>ما مسّته الأدلّة:</b>
                @foreach ($ihModsSeen as $m)
                    <span class="bdg g">{{ hub_mod($m->module)['label'] ?? $m->module }} · {{ $m->n }}</span>
                @endforeach
                @if ($ihModsBlind)<span class="bdg">{{ $ihModsBlind }} وحدةٌ خارج صلاحيتك</span>@endif
            </div>
        @endif
        @if (filled($row->affected))
            <div class="sub" style="margin-top:6px">🧩 <b>الخدماتُ المتأثّرة (كما سُجّلت):</b> {{ $row->affected }}</div>
        @endif

        <div class="sub" style="margin-top:8px">
            ⚖️ الأرقامُ أعلاه من <bdi class="mono ltr">error_occurrences</bdi> و<bdi class="mono ltr">metric_points</bdi>
            داخل النافذة وحدَها. عدّادا الخطأ التراكميّان (<bdi class="mono ltr">count/users</bdi>) يقيسان عمرَ الخطأ
            كلَّه لا هذه الحادثة، فلا يُعرضان هنا. و«المستخدمون» المسجَّلون فقط — الزائرُ بلا حساب لا يُحصى.
        </div>
    @endif
</div>

{{-- (WP-6.2) دليلٌ يُضاف من الحادثة نفسِها: ما لا شاشةَ مصدرٍ له (مكالمة، رسالةُ
     عميل، قرارُ غرفة) يُقيَّد ملاحظةً — فالخطُّ الزمنيّ لا يبقى ناقصاً لأن المصدرَ
     خارج النظام. والمرجعُ اختياريّ: بلا مرجعٍ لا فريدَ منطقيّ فتُضاف الملاحظاتُ كما تُكتب. --}}
@if (hub_can(auth()->user(), 'incidents', 'e'))
    <div class="card">
        <h3 class="cardtitle">🔗 أضف دليلاً لهذه الحادثة</h3>
        <form method="POST" action="{{ route('incidents.link', $row->id) }}"
              style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            @csrf
            <label class="vh" for="ilk">نوع الدليل</label>
            <select class="inp" id="ilk" name="kind" style="max-width:150px">
                @foreach (['note' => 'ملاحظة', 'error' => 'خطأ', 'audit' => 'قيد تدقيق', 'security' => 'حدث أمنيّ',
                           'request' => 'طلب', 'alert' => 'تنبيه', 'task' => 'مهمة', 'deploy' => 'نشر'] as $k => $l)
                    <option value="{{ $k }}">{{ $l }}</option>
                @endforeach
            </select>
            <label class="vh" for="ils">ملخّص الدليل</label>
            <input class="inp" id="ils" name="summary" maxlength="300" required
                   placeholder="ملخّصٌ للبشر (إلزامي)" style="min-width:260px;flex:1">
            <label class="vh" for="ilr">المرجع</label>
            <input class="inp" id="ilr" name="ref" maxlength="120"
                   placeholder="مرجعٌ اختياري: بصمة خطأ / معرّف طلب / audit#123" style="max-width:240px">
            <button class="btn">🔗 اربط الدليل</button>
        </form>
        <div class="sub" style="margin-top:6px">
            الأدلّةُ تظهر في «ما جرى على هذا السجل» أسفل الصفحة مدموجةً مع القيود الآليّة والنشر وفروق الحالة والقائد.
        </div>
    </div>
@endif

{{-- (§8.6) مراجعةُ ما بعد الحادثة — للشدّتين العاليتين وحدهما، بالحقول القائمة
     (postmortem/lessons/prevention/review_date/att_id) وملخّصٍ اختياريّ في meta.pir --}}
@if ($ihHigh)
    @php
        $ihPirSummary = trim((string) data_get($row->meta, 'pir.summary'));
        $ihPirAny = $row->postmortem || filled($row->lessons) || filled($row->prevention)
            || $row->review_date || $row->att_id || $ihPirSummary !== '';
    @endphp
    <div class="card">
        <h3 class="cardtitle">📝 مراجعة ما بعد الحادثة (PIR)
            <span class="bdg {{ $row->postmortem ? 'ok' : 'wn' }}">{{ $row->postmortem ? 'كُتب التقرير' : 'التقرير لم يُكتب' }}</span>
        </h3>
        @if (! $ihPirAny)
            @include('partials.empty', ['icon' => '📝',
                'text' => 'لا مراجعة بعد — حادثةٌ ' . $row->severity . ' تستحق PIR: ماذا تعلّمنا، وما الوقاية، ومتى تُراجَع؟',
                'cta' => route('m.edit', ['incidents', $row->id]), 'ctaLabel' => '✍️ ابدأ المراجعة'])
        @else
            @if ($ihPirSummary !== '')
                <div style="margin-bottom:8px"><b>الملخّص التنفيذي:</b> {{ $ihPirSummary }}</div>
            @endif
            @if (filled($row->lessons))
                <div class="sub" style="margin-bottom:6px">🎓 <b>الدروس المستفادة:</b> {{ $row->lessons }}</div>
            @endif
            @if (filled($row->prevention))
                <div class="sub" style="margin-bottom:6px">🛡️ <b>الوقاية من التكرار:</b> {{ $row->prevention }}</div>
            @endif
            <div class="sub">
                @if ($row->review_date) 📅 مراجعةُ تنفيذ الوقاية: {{ $row->review_date->format('Y-m-d') }} @endif
                @if ($row->att_id) · <a href="{{ route('file.show', $row->att_id) }}" target="_blank" rel="noopener">📎 مرفق المراجعة ↗</a> @endif
            </div>
        @endif
    </div>
@endif
