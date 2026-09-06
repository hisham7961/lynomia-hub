{{-- ملفُّ العمل (WP-7.3 · spec §5.3 · §5.8) — يتوقّع: $emp $wRange $work $act $wTl $sec $rating.
     ثلاثُ بطاقاتٍ منفصلةُ الطبيعة عمداً: العملُ أرقامُ تنفيذ، والنشاطُ حضورٌ وأفعالٌ
     ذاتُ معنى، والأمنُ في بطاقةٍ **لا تلامسهما** (spec §5.1) للمالك وحدَه.
     ولا زياراتِ صفحاتٍ خام في شيءٍ من هذا (spec §5.8، §5.5). --}}

<h3 style="margin:18px 0 8px">📈 ملفّ العمل</h3>
@include('partials.timerange', ['range' => $wRange])

@if ($work === null)
    @include('partials.empty', ['icon' => '🔗',
        'text' => 'لا حساب نظامٍ مرتبطٌ بهذا الملف — أرقامُ العمل تُقاس على حساب الموظف، فلا رقم قبل الربط'])
@else
    @php
        $wOt = $work['on_time'];
        $wRes = $work['resolution'];
        $wDash = fn ($v) => $v === null ? '—' : $v;   // «لا أرى» لا «صفر»
    @endphp
    @include('partials.cc.kpis', ['items' => [
        ['label' => 'أُنجز في النافذة', 'value' => $wDash($work['completed']),
         'tone' => $work['completed'] === null ? 'g' : 'ok',
         'hint' => $work['may']['tasks']
            ? 'من ختم الإنجاز على المهمة — تعديلٌ لاحقٌ عليها لا يغيّر تاريخَها'
            : 'وحدةُ المهام غيرُ مرئيةٍ لحسابك، فلا رقمَ يُعرض'],
        ['label' => 'مهام مفتوحة', 'value' => $wDash($work['open']), 'tone' => 'g'],
        ['label' => 'متأخّرة الآن', 'value' => $wDash($work['overdue']),
         'tone' => $work['overdue'] ? 'bad' : 'ok',
         'hint' => 'مفتوحةٌ فات موعدُها — لقطةُ اللحظة لا النافذة'],
        ['label' => 'الالتزام بالموعد', 'value' => $wOt['pct'] === null ? '—' : $wOt['pct'] . '٪',
         'tone' => $wOt['pct'] === null ? 'g' : ($wOt['pct'] >= 80 ? 'ok' : ($wOt['pct'] >= 50 ? 'wn' : 'bad')),
         'sub' => $wOt['with_due'] ? $wOt['on_time'] . ' في الموعد من ' . $wOt['with_due'] . ' منجزةٍ لها موعد'
                : 'لا منجزاتٍ لها موعدٌ في النافذة'],
        ['label' => 'تذاكر حُلّت', 'value' => $wDash($work['tickets_resolved']),
         'tone' => 'g', 'sub' => $work['tickets_open'] === null ? null : $work['tickets_open'] . ' مفتوحةٌ الآن'],
        ['label' => 'متوسط الحل', 'value' => $wRes['avg_h'] === null ? '—' : $wRes['avg_h'] . ' س',
         'tone' => 'g',
         'sub' => $wRes['of'] ? 'من ' . $wRes['of'] . ' تذكرة' . ($wRes['capped'] ? ' (عيّنة بسقف ' . \App\Support\ExecutionStats::PEOPLE_SAMPLE_CAP . ')' : '')
                : 'لا تذاكرَ حُلّت في النافذة',
         'hint' => 'من ختم حلّ التذكرة إن وُجد، وإلا من آخر تعديلٍ عليها'],
        ['label' => 'مشاريع', 'value' => $wDash($work['projects_open']), 'tone' => 'g',
         'sub' => $work['projects_managed'] === null ? null : 'يدير ' . $work['projects_managed'],
         'hint' => 'مشاريعُ مختلفةٌ في مهامه المفتوحة'],
        ['label' => 'اعتمادات حُسمت', 'value' => $wDash($work['approvals_decided']), 'tone' => 'g',
         'sub' => $work['approvals_waiting'] === null ? null : $work['approvals_waiting'] . ' تنتظر حسمَه',
         'hint' => 'من ختم الحسم على الاعتماد (decided_by/decided_at) لا من حالته'],
    ]])
@endif

@if ($act)
    {{-- النشاط: حضورٌ من نبضة الجلسة وأفعالٌ **ذاتُ معنى** — لا عدَّ زياراتٍ ولا ترتيبَ بها --}}
    <div class="card" style="margin-top:12px">
        <h3>🕘 النشاط داخل النظام
            <span class="sub">· أفعالٌ غيّرت بيانات، لا تنقّلٌ بين الصفحات</span></h3>
        <div class="cards" style="margin-top:8px">
            <div class="stat"><span class="ico">🚪</span>
                <b class="mono">{{ $act['first_at'] ? substr((string) $act['first_at'], 0, 16) : '—' }}</b>
                <span>أول ظهورٍ في النافذة</span></div>
            <div class="stat"><span class="ico">👋</span>
                <b class="mono">{{ $act['last_at'] ? substr((string) $act['last_at'], 0, 16) : '—' }}</b>
                <span>آخر ظهور</span></div>
            <div class="stat"><span class="ico">📆</span><b>{{ $act['active_days'] }}</b>
                <span>أيامٌ فيها نبضةُ جلسة</span></div>
            <div class="stat"><span class="ico">✍️</span><b>{{ $act['actions']['n'] }}</b>
                <span>أفعالٌ ذاتُ معنى</span></div>
        </div>
        @if (count($act['actions']['rows']))
            <div class="tblwrap" style="margin-top:10px"><table class="tbl">
                <thead><tr><th scope="col">الوحدة</th><th scope="col">أفعال</th></tr></thead>
                <tbody>
                @foreach ($act['actions']['rows'] as $ar)
                    <tr>
                        <td>{{ hub_mod($ar['module'])['label'] ?? $ar['module'] }}</td>
                        <td class="acts"><b>{{ $ar['n'] }}</b></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
        <div class="sub" style="margin-top:8px">
            «الأفعالُ ذاتُ المعنى» = إضافةٌ وتعديلٌ وحذفٌ وتصديرٌ على وحداتٍ يراها حسابُك —
            لا تشمل الدخولَ ولا الخروجَ ولا الاطّلاعَ الحسّاس، ولا تُقاس بعدد الزيارات.
            و«أيامٌ نشطة» أيامٌ سُجّلت فيها نبضةُ جلسة، لا ساعاتِ عملٍ مُحصاة.
        </div>
    </div>
@endif

@if (count($wTl))
    <div class="card" style="margin-top:12px">
        <h3>🧾 خطُّه الزمنيّ <span class="sub">· ما أنجزه فعلاً في النافذة</span></h3>
        <div class="tl tlv2">
            @foreach ($wTl as $e)
                <div class="tlrow">
                    <span class="tlico">{{ $e['ico'] }}</span>
                    <span class="tlmain">
                        <b>{{ $e['label'] }}</b>
                        @if ($e['title'] !== '')
                            —
                            @if ($e['url'])<a href="{{ hub_safe_url($e['url']) }}">{{ $e['title'] }}</a>
                            @else {{ $e['title'] }} @endif
                        @endif
                    </span>
                    <span class="mono sub tlat">{{ \Illuminate\Support\Carbon::parse($e['at'])->format('Y-m-d H:i') }}</span>
                </div>
            @endforeach
        </div>
        <div class="sub" style="margin-top:8px">مهامٌّ أُنجزت وتذاكرُ حُلّت واعتماداتٌ حُسمت وتعليقاتٌ وقيودُ تدقيقٍ ذاتُ معنى — بلا أثرِ تنقّلٍ بين الصفحات.</div>
    </div>
@endif

@if ($sec)
    {{-- الأمن في بطاقةٍ منفصلةٍ تماماً (WP-7.1 · spec §5.1): درجةُ الشك لا تُخلط
         بأرقام الأداء أعلاه ولا تُقرأ منها — وهي للمالك وحدَه. --}}
    <div class="card" style="margin-top:12px">
        <h3>🛡️ مخاطر النشاط الأمني
            <span class="sub">· معزولةٌ عن أرقام الأداء أعلاه · للمالك</span></h3>
        <div class="cards" style="margin-top:8px">
            <div class="stat"><span class="ico">📊</span>
                <b class="{{ $sec['tone'] === 'bad' ? 'txt-bad' : '' }}">{{ $sec['score'] }}/100</b>
                <span>نسبة الشك</span></div>
            <div class="stat"><span class="ico">🌙</span><b>{{ $sec['night_h'] }}</b><span>ساعات ٠٠–٠٦</span></div>
            <div class="stat"><span class="ico">🔑</span><b>{{ $sec['failed'] }}</b><span>محاولات دخول فاشلة</span></div>
            <div class="stat"><span class="ico">💻</span><b>{{ $sec['devices'] }}</b><span>أجهزة مختلفة</span></div>
        </div>
        <div class="sub" style="margin-top:8px">
            إشارةُ مراجعةٍ لا حكمَ إدانة، ولا تدخل أيَّ رقمٍ من أرقام العمل أعلاه.
            <a href="{{ route('activity.show', $emp->user_id) }}">التفصيل الكامل في مركز النشاط ←</a>
        </div>
    </div>
@endif
