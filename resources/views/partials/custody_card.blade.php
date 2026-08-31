{{-- بطاقةُ العهدة — تتوقع $row (الأصل). هويّةٌ وحيازةٌ ومواصفاتٌ وتصاريح.
     كانت صفحةُ الأصل جدولَ حقولٍ كبقيّة الوحدات: تقول ما سُجّل ولا تُدير عهدة.
     هنا ما تحتاجه العهدةُ فعلاً في مكانٍ واحد: كودُها المطبوع، ومَن يحملها،
     ومواصفاتُها الداخلية، وتصاريحُ خروجها، وسجلُّ من حملها قبل. --}}
@php
    $cuCan   = hub_can(auth()->user(), 'assets', 'e');
    $cuCat   = \App\Support\Custody::cat($row->type);
    $cuSpecT = \App\Support\Custody::specTemplate($row->type);
    $cuSpecs = (array) ($row->specs ?? []);
    $cuHist  = \App\Support\Custody::history($row->id, 12);
    $cuHolder = $row->holder_id ? (hub_ref_labels('users', [$row->holder_id])[$row->holder_id] ?? 'حسابٌ محذوف') : null;
    $cuOpen  = collect($cuHist)->where('status', 'ساري')->values();
    $cuQr    = \App\Support\Qr::svg(route('m.show', ['assets', $row->id]), 96);
    $cuToday = now()->toDateString();
@endphp

<div class="card">
    <h3 class="cardtitle">🏷️ هويّة العهدة
        <span class="sub">كودٌ يولّده النظام — غير سيريال المصنع</span>
    </h3>
    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
        <div style="flex:none;line-height:0">{!! $cuQr ?: '' !!}</div>
        <div style="flex:1;min-width:200px;display:flex;flex-direction:column;gap:7px">
            <div>
                <div class="sub">كود العهدة</div>
                <b class="mono" style="font-size:21px;letter-spacing:-.02em">{{ $row->code ?: '—' }}</b>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <a class="bdg" href="{{ route('custody.category', $cuCat['code']) }}">
                    {{ $cuCat['icon'] }} {{ $cuCat['name'] }} · <span class="mono">{{ $cuCat['code'] }}</span>
                </a>
                @if ($row->serial)<span class="bdg g mono" dir="ltr">S/N {{ \Illuminate\Support\Str::limit($row->serial, 24) }}</span>@endif
                @if ($row->loc)<span class="bdg g">📍 {{ \Illuminate\Support\Str::limit($row->loc, 30) }}</span>@endif
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start">
            <a class="btn ghost sm" href="{{ route('custody.label', $row->id) }}" target="_blank" rel="noopener">🏷️ ملصق ٤٠×٣٠ مم</a>
            <a class="btn ghost sm" href="{{ route('custody.spec', $row->id) }}" target="_blank" rel="noopener">📄 بطاقة A5</a>
            <a class="btn ghost sm" href="{{ route('custody.catalog') }}">🗂️ الكتالوج</a>
        </div>
    </div>
</div>

{{-- ── الحيازة: من يحملها الآن، وتسليمُها واستردادُها ── --}}
<div class="card">
    <h3 class="cardtitle">🤲 الحيازة
        @if ($cuHolder)<span class="bdg ok">بيد {{ $cuHolder }}</span>
        @else<span class="bdg g">في المخزن — بلا حائز</span>@endif
    </h3>

    @if ($cuCan)
        <div class="two-forms">
            <form method="POST" action="{{ route('custody.handover', $row->id) }}">
                @csrf
                <div class="sub" style="margin-bottom:7px">🤲 <b>تسليمُ العهدة لمستخدم</b> — يُسجَّل في سجل الحيازة ويُشعَر المستلم.</div>
                <div class="fg">
                    <div class="fld">
                        <label for="cu-user">المستلم</label>
                        <select class="inp" id="cu-user" name="userId" required>
                            <option value=""></option>
                            @foreach (hub_ref_options('users', $row->holder_id) as $uid => $uname)
                                <option value="{{ $uid }}" @selected((string) $row->holder_id === (string) $uid)>{{ $uname }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="fld">
                        <label for="cu-at">تاريخ التسليم</label>
                        <input class="inp" id="cu-at" type="date" name="at" value="{{ $cuToday }}" required>
                    </div>
                    <div class="fld">
                        {{-- سياقُ التسليم: لأي مشروعٍ أُعطيت — يبقى في السجل بعد
                             انتقال الموظف، فيُعرف أن اللابتوب كان لعُهدة مشروع كذا --}}
                        <label for="cu-proj">لمشروع (اختياري)</label>
                        <select class="inp" id="cu-proj" name="projectId">
                            <option value=""></option>
                            @foreach (hub_ref_options('projects') as $cupid => $cupname)
                                <option value="{{ $cupid }}">{{ $cupname }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="fld fw">
                        <label for="cu-note">ملاحظة (اختياري)</label>
                        <input class="inp" id="cu-note" name="note" maxlength="500" placeholder="مثال: مع الشاحن والحقيبة">
                    </div>
                </div>
                <button class="btn p sm" style="margin-top:10px">🤲 تسجيل التسليم</button>
            </form>

            @if ($row->holder_id)
                <form method="POST" action="{{ route('custody.recover', $row->id) }}"
                      data-confirm="استرداد العهدة من {{ $cuHolder }}؟ تعود «متاحة» بلا حائز.">
                    @csrf
                    <div class="sub" style="margin-bottom:7px">📦 <b>استردادُ العهدة للمخزن</b> — عند انتهاء الخدمة أو تغيير الجهاز.</div>
                    <div class="fg">
                        <div class="fld">
                            <label for="cu-rat">تاريخ الاسترداد</label>
                            <input class="inp" id="cu-rat" type="date" name="at" value="{{ $cuToday }}" required>
                        </div>
                        <div class="fld">
                            <label for="cu-rnote">ملاحظة (اختياري)</label>
                            <input class="inp" id="cu-rnote" name="note" maxlength="500" placeholder="مثال: أُعيدت بحالة سليمة">
                        </div>
                    </div>
                    <button class="btn sm" style="margin-top:10px">📦 تسجيل الاسترداد</button>
                </form>
            @endif
        </div>
        <div class="sub" style="margin-top:10px">
            💡 التسليمُ قيدٌ في السجل؛ و<b>الإثباتُ الموقّع</b> من بطاقة «استلام العهدة»
            (تظهر في هذه الصفحة بعد التسليم) أو بطلب توقيعٍ إلكترونيّ.
        </div>
    @else
        <div class="sub">تسجيلُ التسليم والاسترداد يتطلّب صلاحية تعديل الأصول.</div>
    @endif
</div>

{{-- ── المواصفات الداخلية: قالبٌ لكل صنف ── --}}
<div class="card">
    <h3 class="cardtitle">⚙️ المواصفات الداخلية
        <span class="sub">قالبُ صنف «{{ $cuCat['name'] }}» — تُطبَع في بطاقة A5</span>
    </h3>
    @if (! count($cuSpecT))
        <div class="sub">لا قالبَ مواصفاتٍ لهذا الصنف بعد.</div>
    @elseif ($cuCan)
        <form method="POST" action="{{ route('custody.specs', $row->id) }}">
            @csrf
            <div class="fg">
                @foreach ($cuSpecT as $sf)
                    <div class="fld">
                        <label for="sp-{{ $sf['key'] }}">{{ $sf['label'] }}</label>
                        <input class="inp" id="sp-{{ $sf['key'] }}" name="specs[{{ $sf['key'] }}]"
                               maxlength="200" value="{{ $cuSpecs[$sf['key']] ?? '' }}"
                               @if (! empty($sf['ltr'])) dir="ltr" @endif>
                    </div>
                @endforeach
            </div>
            <button class="btn p sm" style="margin-top:12px">💾 حفظ المواصفات</button>
        </form>
    @else
        <dl class="detail">
            @forelse (\App\Support\Custody::specRows($row) as $sr)
                <div class="drow"><dt>{{ $sr['label'] }}</dt>
                    <dd @if ($sr['ltr']) dir="ltr" @endif>{{ $sr['val'] }}</dd></div>
            @empty
                <div class="sub">لم تُسجَّل مواصفاتٌ بعد.</div>
            @endforelse
        </dl>
    @endif
</div>

{{-- ── تصاريح النقل والخروج ── --}}
<div class="card">
    <h3 class="cardtitle">🚪 تصاريح النقل والخروج
        @if ($cuOpen->count())<span class="bdg wn">{{ $cuOpen->count() }} سارٍ</span>@endif
    </h3>

    @if ($cuOpen->count())
        <table class="tbl" style="margin-bottom:14px">
            <thead><tr><th>التصريح</th><th>النوع</th><th>الجهة</th><th>العودة</th><th class="acts"></th></tr></thead>
            <tbody>
            @foreach ($cuOpen as $op)
                <tr>
                    <td><a class="mono" href="{{ route('custody.permit.doc', [$row->id, $op['id']]) }}">{{ $op['permit'] }}</a></td>
                    <td>{{ $op['action'] }}</td>
                    <td>{{ $op['to'] ?: ($op['who'] ?? '—') }}</td>
                    <td>
                        @if ($op['due'])
                            <span class="mono {{ $op['late'] ? '' : 'sub' }}">{{ $op['due'] }}</span>
                            @if ($op['late'])<span class="bdg bad">متأخرة</span>@endif
                        @else<span class="sub">—</span>@endif
                    </td>
                    <td class="acts">
                        <a class="btn ghost xs" href="{{ route('custody.permit.doc', [$row->id, $op['id']]) }}" target="_blank" rel="noopener">📄 الورقة</a>
                        @if ($cuCan)
                            <form method="POST" action="{{ route('custody.permit.return', [$row->id, $op['id']]) }}" class="inline">
                                @csrf<input type="hidden" name="at" value="{{ $cuToday }}">
                                <button class="btn ghost xs">✅ سجّل العودة</button>
                            </form>
                            <form method="POST" action="{{ route('custody.permit.cancel', [$row->id, $op['id']]) }}" class="inline"
                                  data-confirm="إلغاء التصريح {{ $op['permit'] }}؟ يبقى في السجل أثراً.">
                                @csrf<button class="btn ghost xs dn">🚫 إلغاء</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @if ($cuCan)
        <form method="POST" action="{{ route('custody.permit', $row->id) }}">
            @csrf
            <div class="fg">
                <div class="fld">
                    <label for="pm-kind">نوع التصريح</label>
                    <select class="inp" id="pm-kind" name="kind" required>
                        @foreach (\App\Support\Custody::PERMITS as $k)
                            <option value="{{ $k }}">{{ $k }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fld">
                    <label for="pm-at">تاريخ الحركة</label>
                    <input class="inp" id="pm-at" type="date" name="at" value="{{ $cuToday }}" required>
                </div>
                <div class="fld">
                    <label for="pm-due">العودة المتوقّعة <span class="sub">(للخروج المؤقت)</span></label>
                    <input class="inp" id="pm-due" type="date" name="due">
                </div>
                <div class="fld">
                    <label for="pm-user">المنقول إليه <span class="sub">(للنقل)</span></label>
                    <select class="inp" id="pm-user" name="userId">
                        <option value=""></option>
                        @foreach (hub_ref_options('users') as $uid => $uname)
                            <option value="{{ $uid }}">{{ $uname }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fld">
                    <label for="pm-to">الجهة / الموقع</label>
                    <input class="inp" id="pm-to" name="to" maxlength="300" placeholder="مثال: ورشة الصيانة — شركة الأفق">
                </div>
                <div class="fld fw">
                    <label for="pm-note">السبب</label>
                    <input class="inp" id="pm-note" name="note" maxlength="500" placeholder="مثال: إصلاح لوحة المفاتيح تحت الضمان">
                </div>
            </div>
            <button class="btn p sm" style="margin-top:10px">📄 إصدار التصريح</button>
        </form>
        <div class="sub" style="margin-top:10px">
            @foreach (\App\Support\Custody::PERMIT_HINTS as $k => $why)
                <div>• <b>{{ $k }}:</b> {{ $why }}</div>
            @endforeach
        </div>
    @else
        <div class="sub">إصدارُ التصاريح يتطلّب صلاحية تعديل الأصول.</div>
    @endif
</div>

{{-- ── سجل الحيازة ── --}}
@if (count($cuHist))
<div class="card">
    <h3 class="cardtitle">🕐 سجل الحيازة <span class="sub">من حملها ومتى — ما لا يقوله «الحائز الحالي»</span></h3>
    <table class="tbl">
        <thead><tr><th style="width:110px">التاريخ</th><th style="width:110px">الحركة</th><th>الطرف</th>
            <th style="width:150px">التصريح</th><th>ملاحظة</th></tr></thead>
        <tbody>
        @foreach ($cuHist as $h)
            <tr>
                <td class="mono sub">{{ $h['at'] }}</td>
                <td>
                    <span class="bdg {{ in_array($h['action'], ['استرداد', 'خروج نهائي'], true) ? 'g' : ($h['late'] ? 'bad' : '') }}">
                        {{ $h['action'] }}
                    </span>
                </td>
                <td>{{ $h['who'] }}@if ($h['to'])<div class="sub">→ {{ $h['to'] }}</div>@endif</td>
                <td>
                    @if ($h['permit'])
                        <a class="mono" href="{{ route('custody.permit.doc', [$row->id, $h['id']]) }}">{{ $h['permit'] }}</a>
                        @if ($h['status'] && $h['status'] !== 'ساري')<span class="bdg g">{{ $h['status'] }}</span>@endif
                    @else<span class="sub">—</span>@endif
                </td>
                <td class="sub">{{ \Illuminate\Support\Str::limit($h['note'] ?? '', 60) ?: '—' }}
                    <div class="sub" style="font-size:11px">بيد {{ $h['by'] }}</div></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

<style>
.two-forms{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px}
.two-forms > form{background:var(--bg2);border:1px solid var(--ln);border-radius:var(--r);padding:14px}
</style>
