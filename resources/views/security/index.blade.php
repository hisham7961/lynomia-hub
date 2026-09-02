@extends('layouts.app')
@section('title', 'مركز الأمان')
@section('content')
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>النظام</span><span aria-hidden="true">‹</span><b>مركز الأمان</b></nav>
        <h2>🛡️ مركز الأمان</h2>
        <div class="sub">ما المكسور الآن، ومن يصلحه، ومن هو داخلٌ في هذه اللحظة</div>
    </div>
    <form method="POST" action="{{ route('security.lockdown') }}"
          data-confirm="{{ $lockdown ? 'رفع قفل الطوارئ وإعادة الوصول للجميع؟' : 'تفعيل قفل الطوارئ؟ كل الجلسات غير المالكة ستُعلّق فوراً!' }}">
        @csrf
        <button class="btn {{ $lockdown ? '' : 'ghost' }} sm" type="submit"
                style="{{ $lockdown ? 'background:var(--bad);border-color:var(--bad);color:#fff' : 'color:var(--bad);border-color:var(--bad)' }}">
            {{ $lockdown ? '🔓 رفع قفل الطوارئ (مفعّل الآن!)' : '🔒 قفل طوارئ' }}
        </button>
    </form>
</div>

@if ($lockdown)<div class="flash bad" style="position:static;margin-bottom:12px">⚠️ قفل الطوارئ مفعّل — لا يستطيع أحد سوى المالكين الوصول للنظام الآن</div>@endif

{{-- مفاتيحُ الطوارئ المفصولة: كلٌّ يشدّ فرملةً وحدَه — لا زرٌّ واحدٌ خطر --}}
<div class="card" style="margin-bottom:12px">
    <div class="crow" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
        <h3 style="margin:0">🧯 مفاتيح الطوارئ المفصولة</h3>
        <div class="sub" style="margin:0">شدُّ الفرملة فوريّ؛ رفعُها يتطلّب تأكيدَ الهوية — كلُّ تبديلٍ مسجَّلٌ في التدقيق.</div>
    </div>
    <div class="crow" style="gap:10px;margin-top:10px;flex-wrap:wrap">
        @foreach ([['exports', '📤 تصدير البيانات', $freezeExports], ['tokens', '🔑 سكّ مفاتيح API', $freezeTokens]] as [$fk, $flabel, $fon])
            <form method="POST" action="{{ route('security.freeze', $fk) }}"
                  data-confirm="{{ $fon ? 'رفعُ تجميد ' . $flabel . '؟ سيُعاد تفعيلُه — قد يُطلب تأكيدُ الهوية.' : 'تجميدُ ' . $flabel . ' فوراً؟' }}">
                @csrf
                <button class="btn {{ $fon ? '' : 'ghost' }} sm" type="submit"
                        style="{{ $fon ? 'background:var(--bad);border-color:var(--bad);color:#fff' : '' }}">
                    {{ $fon ? '♻️ رفع تجميد ' . $flabel . ' (مجمَّد الآن!)' : '🧊 تجميد ' . $flabel }}
                </button>
            </form>
        @endforeach
    </div>
    @if ($freezeExports || $freezeTokens)
        <div class="flash wn" style="position:static;margin-top:10px">🧊 مُجمَّدٌ الآن:
            {{ $freezeExports ? 'التصدير' : '' }}{{ $freezeExports && $freezeTokens ? ' و' : '' }}{{ $freezeTokens ? 'سكّ الرموز' : '' }}
            — يُصَدّ بالرمز ٤٢٣ حتى يُرفع من هنا.</div>
    @endif
</div>

{{-- وضعية الأمان: فحوصٌ حيّة لكلٍّ منها علاجٌ وموضعه — لا أرقامٌ عارية --}}
<div class="card" style="margin-bottom:12px">
    <div class="crow" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
        <h3 style="margin:0">🩺 وضعية الأمان</h3>
        <div class="crow" style="gap:6px">
            <span class="bdg {{ $summary['bad'] ? 'bad' : 'ok' }}">{{ $summary['bad'] }} مكسور</span>
            <span class="bdg wn">{{ $summary['wn'] }} تحذير</span>
            <span class="bdg ok">{{ $summary['ok'] }} سليم</span>
            <span class="bdg">الدرجة {{ $summary['score'] }}٪</span>
        </div>
    </div>
    <div class="sub" style="margin:4px 0 10px">مرتَّبةٌ بالأسوأ أولاً — كل بندٍ يقول لماذا يهمّ وكيف يُصلَح وأين.</div>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px">
        @foreach (collect($posture)->sortBy(fn ($c) => ['bad' => 0, 'wn' => 1, 'ok' => 2][$c['tone']]) as $c)
            <div style="padding:10px 12px;border:1px solid var(--ln);border-radius:11px;
                        background:{{ $c['tone'] === 'ok' ? 'transparent' : 'color-mix(in srgb, var(--' . $c['tone'] . ') 9%, transparent)' }}">
                <div class="crow" style="gap:6px;align-items:baseline;flex-wrap:wrap">
                    <span>{{ $c['tone'] === 'ok' ? '✅' : ($c['tone'] === 'wn' ? '⚠️' : '⛔') }}</span>
                    <b style="font-size:13px">{{ $c['label'] }}</b>
                    @if ($c['value'])<span class="bdg {{ $c['tone'] }}">{{ $c['value'] }}</span>
                    @elseif ($c['n'])<span class="bdg {{ $c['tone'] }}">{{ $c['n'] }}</span>@endif
                </div>
                <div class="sub" style="margin-top:5px;font-size:12px;line-height:1.8">{{ $c['why'] }}</div>
                @if ($c['fix'])
                    <div style="margin-top:6px;font-size:12px"><b>العلاج:</b> {{ $c['fix'] }}</div>
                    <a class="btn ghost xs" style="margin-top:6px" href="{{ $c['url'] }}">اذهب لإصلاحه ←</a>
                @endif
            </div>
        @endforeach
    </div>
</div>

<div class="cards">
    <div class="stat"><span class="ico">🟢</span><b>{{ $kpi['live'] }}</b><span>جلسات نشطة الآن</span></div>
    <div class="stat"><span class="ico">👥</span><b>{{ $kpi['users'] }}</b><span>مستخدمون</span></div>
    <div class="stat"><span class="ico">🛡️</span><b>{{ $kpi['twofa'] }}/{{ $kpi['users'] }}</b><span>مفعّلو المصادقة الثنائية</span></div>
    <div class="stat"><span class="ico">🔒</span><b class="{{ $kpi['locked'] ? 'txt-bad' : '' }}">{{ $kpi['locked'] }}</b><span>حسابات مقفلة الآن</span></div>
    <div class="stat"><span class="ico">😴</span><b class="{{ $kpi['idle'] ? 'txt-bad' : '' }}">{{ $kpi['idle'] }}</b><span>خاملون +٦٠ يوماً</span></div>
    <div class="stat"><span class="ico">🚫</span><b class="{{ $kpi['failed7'] ? 'txt-bad' : '' }}">{{ $kpi['failed7'] }}</b><span>محاولات فاشلة (٧ أيام)</span></div>
    <div class="stat"><span class="ico">📡</span><b class="{{ $kpi['denied7'] ? 'txt-bad' : '' }}">{{ $kpi['denied7'] }}</b><span>وصولٌ مرفوض (٧ أيام)</span></div>
    <div class="stat"><span class="ico">🗝️</span><b class="{{ $kpi['stale'] ? 'txt-bad' : '' }}">{{ $kpi['stale'] }}</b><span>أسرار لم تُغيَّر منذ ٦ أشهر</span></div>
    <a class="stat" href="{{ route('m.index', 'incidents') }}"><span class="ico">🚨</span><b class="{{ ($kpi['secinc'] ?? 0) ? 'txt-bad' : '' }}">{{ $kpi['secinc'] ?? 0 }}</b><span>حوادث أمنيّة مفتوحة</span></a>
</div>

{{-- خريطةُ الانكشاف: من يطاله اختراقُ حسابٍ واحد — بعلاقاتٍ فعلية لا رقمٍ أسود --}}
<div class="card" style="margin-bottom:12px">
    <div class="crow" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
        <h3 style="margin:0">🗺️ خريطة الانكشاف — نطاقُ الأثر</h3>
        <div class="crow" style="gap:6px">
            <span class="bdg {{ $exposureSummary['high'] ? 'bad' : 'ok' }}">{{ $exposureSummary['exposed'] }} حسابٌ منكشف</span>
            <span class="bdg wn">{{ $exposureSummary['owners'] }} مالك</span>
            <span class="bdg {{ $exposureSummary['no2fa'] ? 'bad' : 'ok' }}">{{ $exposureSummary['no2fa'] }} بلا تحقّقٍ ثنائي</span>
            <span class="bdg">{{ $exposureSummary['live'] }} حيٌّ الآن</span>
        </div>
    </div>
    <div class="sub" style="margin:4px 0 10px">الحساباتُ عالية الامتياز مرتَّبةٌ بالأخطر أولاً — لو سُرق أحدُها، ماذا يطال؟ الدرجةُ مفسَّرةُ العوامل.</div>
    @if (empty($exposure))
        <div class="sub">لا حساباتٍ عالية الامتياز خارج المالك — انكشافٌ ضيّق.</div>
    @else
    <div class="tblwrap">
        <table class="tbl">
            <thead><tr><th>الحساب</th><th>الدور والنطاق</th><th>الرايات الحسّاسة</th><th>حيّ/أجهزة</th><th>الانكشاف</th></tr></thead>
            <tbody>
            @foreach ($exposure as $x)
                <tr>
                    <td>
                        <b>{{ $x['name'] }}</b>{!! $x['is_owner'] ? ' <span class="bdg bad">مالك</span>' : '' !!}
                        @if (! $x['twofa'])<span class="bdg wn" title="بلا تحقّقٍ بخطوتين">بلا 2FA</span>@endif
                        @if ($x['locked'])<span class="bdg">مقفل</span>@endif
                        <div class="sub" style="font-size:11px">{{ $x['email'] }}</div>
                    </td>
                    <td>{{ $x['role'] }}<div class="sub" style="font-size:11px">{{ $x['scope_label'] }}</div></td>
                    <td>
                        @forelse ($x['flag_labels'] as $fl)<span class="bdg wn" style="margin:1px">{{ $fl }}</span>@empty<span class="sub">—</span>@endforelse
                    </td>
                    <td>{{ $x['live'] }} / {{ $x['devices'] }}</td>
                    <td>
                        <span class="bdg {{ $x['band'] }}">{{ $x['score'] }}</span>
                        <div class="sub" style="font-size:11px;line-height:1.7">{{ implode(' · ', $x['factors']) }}</div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>

{{-- رادارُ الكشف الحيّ: من طرق باباً لا يملك مفتاحه — وصولٌ مرفوض (٤٠٣) وتخمينُ روابط --}}
<div class="card" style="margin-bottom:12px">
    <div class="crow" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
        <h3 style="margin:0">📡 رادار الكشف الحيّ <span class="sub">(٧ أيام)</span></h3>
        <div class="crow" style="gap:6px">
            <span class="bdg {{ $radar['total'] ? 'bad' : 'ok' }}">{{ $radar['total'] }} محاولة مرفوضة</span>
            <span class="bdg wn">{{ $radar['ips'] }} عنوان</span>
            <span class="bdg {{ $radar['anon'] ? 'wn' : 'ok' }}">{{ $radar['anon'] }} من غير مستخدم</span>
        </div>
    </div>
    <div class="sub" style="margin:4px 0 10px">
        كل طرقةٍ على بابٍ بلا مفتاح: وصولٌ مرفوض (٤٠٣) خارج الصلاحية، أو تخمينُ رابط توقيعٍ من غير مستخدم.
        مراقبةٌ حيّة — لا كلمةَ مرورٍ خاطئة (تلك في «المحاولات الفاشلة») بل محاولةُ فتحِ ما لا يُملَك.
    </div>

    @if ($threats->count())
        <div style="margin-bottom:10px;padding:8px 10px;border-radius:9px;border:1px solid var(--ln);
                    background:color-mix(in srgb, var(--bad) 9%, transparent)">
            <b style="font-size:13px">⛔ عناوين تكرّر الطرق</b>
            <div class="sub" style="margin-top:4px">عنوانٌ يطرق مراراً وأهدافُه تتعدّد تقصٍّ لا خطأ — راجعه، وفكّر في حصر الوصول بالشبكة من ملفات المستخدمين.</div>
            @foreach ($threats as $t)
                <div class="sub mono ltr" style="font-size:12px">
                    {{ $t->ip }} — {{ $t->hits }} محاولة على {{ $t->targets }} مسار{{ (int) $t->anon ? ' · ' . $t->anon . ' من غير مستخدم' : '' }}
                </div>
            @endforeach
        </div>
    @endif

    <table class="mini">
        @forelse ($denials as $d)
            <tr>
                <td>
                    <span class="bdg {{ $d->kind === 'تخمين رابط' ? 'wn' : 'bad' }}">{{ $d->kind }}</span>
                    <b>{{ $d->uname ?? 'زائرٌ غير مستخدم' }}</b>
                    <div class="sub mono ltr" title="{{ $d->path }}">
                        {{ $d->method }} {{ \Illuminate\Support\Str::limit($d->path, 44) }} · {{ \Illuminate\Support\Carbon::parse($d->created_at)->diffForHumans() }}
                    </div>
                </td>
                <td class="mono ltr acts">{{ $d->ip ?: '—' }}</td>
            </tr>
        @empty
            <tr><td class="sub" style="padding:12px;text-align:center">لا محاولات وصولٍ مرفوضة — الرادار صافٍ 🟢</td></tr>
        @endforelse
    </table>
</div>

{{-- السجلُّ الأمنيّ الموحَّد: تصنيفٌ قانونيّ واحد فوق التدقيق ورادار المنع — لا جدولَ ثانٍ --}}
<div class="card" id="secevents">
    <div class="crow" style="justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
        <h3 style="margin:0">🧾 السجل الأمني الموحّد <span class="sub">(٧ أيام)</span></h3>
        <form method="GET" class="crow" style="gap:6px">
            <label class="vh" for="ev">تصفية بالحدث</label>
            <select class="inp" id="ev" name="ev" onchange="this.form.submit()">
                <option value="">كل الأحداث ({{ array_sum($eventCounts) }})</option>
                @foreach (\App\Support\SecurityEvents::CODES as $code => [$label, $sev])
                    <option value="{{ $code }}" @selected($eventCode === $code)>{{ $label }} · {{ $code }}@if (isset($eventCounts[$code])) ({{ $eventCounts[$code] }})@endif</option>
                @endforeach
            </select>
        </form>
    </div>
    <div class="sub" style="margin:4px 0 10px">
        من فعل ماذا أمنياً — دخولٌ وفشلُه، وكشفُ سرّ، وتغييرُ دورٍ أو سياسة، وسكُّ مفتاح، ومنعُ وصول — بكودٍ آليٍّ ثابتٍ يُصفّى به ويُربط بمعرّف الطلب. المصدر: سلسلة التدقيق المختومة ورادار المنع.
    </div>
    <div class="crow" style="gap:6px;flex-wrap:wrap;margin-bottom:8px">
        @foreach (array_slice($eventCounts, 0, 8, true) as $code => $n)
            @php [$lbl, $sev] = \App\Support\SecurityEvents::CODES[$code]; @endphp
            <a class="bdg {{ \App\Support\SecurityEvents::SEVERITY_TONE[$sev] }}" href="{{ route('security.index', ['ev' => $code]) }}#secevents" title="{{ $code }}">{{ $lbl }} {{ $n }}</a>
        @endforeach
    </div>
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>الحدث</th><th>من</th><th>ماذا</th><th>من أين</th><th>متى</th></tr></thead>
        <tbody>
        @forelse ($events as $e)
            <tr>
                <td><span class="bdg {{ $e['tone'] }}" title="{{ $e['severity'] }}">{{ $e['label'] }}</span><div class="sub mono ltr" style="font-size:10px">{{ $e['code'] }}</div></td>
                <td>{{ $e['user'] ?? ($e['user_id'] ? 'مستخدم محذوف' : 'زائرٌ غير مستخدم') }}</td>
                <td style="max-width:320px">{{ $e['name'] }}@if ($e['module'])<div class="sub">{{ hub_mod($e['module'])['label'] ?? $e['module'] }}</div>@endif
                    @if (! empty($e['request_id']))<div class="sub mono ltr" style="font-size:10px" title="معرّف الطلب">{{ $e['request_id'] }}</div>@endif</td>
                <td class="mono ltr sub">{{ $e['ip'] ?: '—' }}</td>
                <td class="sub">{{ \Illuminate\Support\Carbon::parse($e['at'])->diffForHumans() }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="sub" style="padding:12px;text-align:center">لا أحداث أمنية{{ $eventCode ? ' من هذا النوع' : '' }} خلال ٧ أيام.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>

<div class="kids">
    <div class="card kid">
        <h3>🖥️ الجلسات <span class="sub">(النشطة أولاً — ولكلٍّ زرّ إنهاء)</span></h3>
        <table class="mini">
            @forelse ($sessions as $s)
                <tr>
                    <td>
                        <b>{{ $s->uname ?? '—' }}</b>
                        @if ($s->revoked)<span class="bdg bad">مُنهاة</span>
                        @elseif ($s->live)<span class="bdg ok">نشطة الآن</span>@endif
                        @if ($s->mine)<span class="bdg">جلستك</span>@endif
                        <div class="sub" title="{{ $s->device }}">
                            {{ $s->ip ?: 'بلا عنوان' }} · دخلت {{ \Illuminate\Support\Carbon::parse($s->started_at)->diffForHumans() }}
                            @if ($s->last_seen_at) · آخر ظهور {{ \Illuminate\Support\Carbon::parse($s->last_seen_at)->diffForHumans() }}@endif
                        </div>
                    </td>
                    <td class="acts">
                        @unless ($s->revoked || $s->mine)
                            <form method="POST" action="{{ route('security.session.revoke', $s->id) }}" style="display:inline"
                                  data-confirm="إنهاء هذه الجلسة؟ يخرج الجهاز عند أول طلب، ورمز «تذكّرني» يُدوَّر فتُنهى بقية أجهزة هذا المستخدم أيضاً.">
                                @csrf<button class="btn ghost xs" style="color:var(--bad);border-color:var(--bad)">أنهِ الجلسة</button>
                            </form>
                        @endunless
                    </td>
                </tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا جلسات مسجلة</td></tr>
            @endforelse
        </table>
        <div class="sub" style="margin-top:6px">
            الإنهاء يسري عند أول طلبٍ للجهاز، ويُدوَّر معه رمز «تذكّرني» — بغير ذلك تُبعث الجلسة من كعكةٍ عمرها ٤٠٠ يوم.
        </div>
    </div>

    <div class="card kid">
        <h3>🚫 آخر المحاولات الفاشلة</h3>
        <table class="mini">
            @forelse ($failed as $f)
                <tr><td class="mono ltr" style="font-size:12px">{{ \Illuminate\Support\Str::limit($f->name, 28) }}<div class="sub" title="{{ $f->device }}">{{ \Illuminate\Support\Carbon::parse($f->created_at)->diffForHumans() }}</div></td>
                    <td class="mono ltr acts">{{ $f->ip }}</td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا محاولات فاشلة 🎉</td></tr>
            @endforelse
        </table>
        @if ($knocking->count())
            <div style="margin-top:8px;padding:8px 10px;border-radius:9px;border:1px solid var(--ln);
                        background:color-mix(in srgb, var(--bad) 9%, transparent)">
                <b style="font-size:13px">⛔ عناوين تطرق أكثر من حساب</b>
                <div class="sub" style="margin-top:4px">تخمينٌ لا نسيان — والقفل مربوطٌ بالبريد لا بالعنوان، فقد يكون إقفالاً متعمَّداً لحسابات الفريق.</div>
                @foreach ($knocking as $k)
                    <div class="sub mono ltr" style="font-size:12px">{{ $k->ip }} — {{ $k->hits }} محاولة على {{ $k->targets }} حساباً</div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="card kid">
        <h3>😴 مستخدمون خاملون <span class="sub">(+٦٠ يوماً بلا دخول)</span></h3>
        <table class="mini">
            @forelse ($idleUsers as $u)
                <tr><td>{{ $u->name }}<div class="sub">{{ $u->email }} · {{ $u->last_login_at ? 'آخر دخول ' . \Illuminate\Support\Carbon::parse($u->last_login_at)->diffForHumans() : 'لم يدخل قط' }}</div></td>
                    <td class="acts">
                        @unless ($u->totp_enabled)<span class="bdg wn" title="بلا مصادقة ثنائية">بلا 2FA</span>@endunless
                        <form method="POST" action="{{ route('security.user.revoke', $u->id) }}" style="display:inline"
                              data-confirm="إنهاء كل جلسات «{{ $u->name }}» على كل الأجهزة؟">
                            @csrf<button class="btn ghost xs">أنهِ جلساته</button>
                        </form>
                    </td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">الجميع نشطون 💪</td></tr>
            @endforelse
        </table>
        @if ($idleUsers->count())<div class="sub" style="margin-top:6px">راجعهم من صفحة «المستخدمون» — أوقف من لم يعد يحتاج الوصول</div>@endif
    </div>

    <div class="card kid">
        <h3>🗝️ أسرار تحتاج تدويراً <span class="sub">(٦ أشهر بلا تغيير)</span></h3>
        <table class="mini">
            @forelse ($staleSecrets as $s)
                <tr><td><a href="{{ route('m.show', ['vault', $s->id]) }}">{{ \Illuminate\Support\Str::limit($s->title, 30) }}</a>
                    <div class="sub">{{ $s->type }} · آخر تغيير {{ \Illuminate\Support\Carbon::parse($s->updated_at)->diffForHumans() }}</div></td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">كل الأسرار محدّثة 🎉</td></tr>
            @endforelse
        </table>
    </div>

    <div class="card kid">
        <h3>⚖️ مراجعة الأدوار والصلاحيات</h3>
        <table class="mini">
            @foreach ($roles as $r)
                <tr>
                    <td>{{ $r->name }} @if ($r->is_owner)<span class="bdg bad">مالك — صلاحية مطلقة</span>@endif
                        <div class="sub">{{ $r->users }} مستخدم · {{ $r->is_owner ? 'كل الوحدات' : $r->mods . ' وحدة' }}{{ $r->scope === 'proj' ? ' · محدود بمشاريعه' : '' }}{{ count($r->flags) ? ' · أعلام: ' . implode('، ', $r->flags) : '' }}</div></td>
                </tr>
            @endforeach
        </table>
        <div style="margin-top:8px"><a class="btn ghost xs" href="{{ route('roles.index') }}">إدارة الأدوار ←</a></div>
    </div>

    <div class="card kid">
        <h3>📤 سجل التصدير</h3>
        <table class="mini">
            @forelse ($exports as $e)
                <tr><td>{{ $e->uname ?? '—' }} — {{ hub_mod($e->module)['label'] ?? $e->module }}<div class="sub">{{ $e->name }} · {{ \Illuminate\Support\Carbon::parse($e->created_at)->diffForHumans() }}</div></td>
                    <td class="mono ltr acts">{{ $e->ip }}</td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا عمليات تصدير بعد</td></tr>
            @endforelse
        </table>
    </div>
</div>
@endsection
