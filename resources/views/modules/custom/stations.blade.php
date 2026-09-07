{{-- لوحةُ المحطة (Work OS · الطور F · WP-F.1 · §25–27) — تتوقّع $row (المحطة).
     هويّةُ المقعد (كودٌ يُمسَح)، ثم شاغلُه الآن مع الإسناد/الإخلاء، ثم تاريخُ من
     جلس عليه ومتى أُخلي — الأثرُ الذي لا يقوله «المُسنَد إليه الآن» وحدَه. --}}
@php
    $stCan     = hub_can(auth()->user(), 'stations', 'e');
    $stHolder  = $row->current_employee_id
        ? (hub_ref_labels('users', [$row->current_employee_id])[$row->current_employee_id] ?? 'حسابٌ محذوف')
        : null;
    $stQr      = $row->code ? \App\Support\Qr::svg(route('stations.code', $row->code), 96) : '';
    // ترتيبٌ حتميّ (C13): الأحدثُ أولاً ثم id — الجدولُ يمرّ على كلّ الصفوف
    $stHist    = \App\Models\StationAssignment::where('station_id', $row->id)
        ->orderByDesc('at')->orderByDesc('id')->limit(20)->get();
    $stNames   = \Illuminate\Support\Facades\DB::table('users')
        ->whereIn('id', $stHist->pluck('user_id')->merge($stHist->pluck('by_id'))->filter()->unique())
        ->pluck('name', 'id');
@endphp

{{-- ── هويّةُ المقعد: كودٌ يُطبَع على الملصق ويُمسَح بـs/{code} ── --}}
<div class="card">
    <h3 class="cardtitle">🪑 هويّة المحطة
        <span class="sub">كودٌ يولّده النظام — يُطبَع على المقعد ويُمسَح</span>
    </h3>
    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
        <div style="flex:none;line-height:0">{!! $stQr ?: '' !!}</div>
        <div style="flex:1;min-width:200px;display:flex;flex-direction:column;gap:7px">
            <div>
                <div class="sub">كود المحطة</div>
                <b class="mono" style="font-size:21px;letter-spacing:-.02em">{{ $row->code ?: '—' }}</b>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                @if ($row->facility)<span class="bdg g">🏢 {{ \Illuminate\Support\Str::limit($row->facility, 30) }}</span>@endif
                @if ($row->zone)<span class="bdg g">🗺️ {{ \Illuminate\Support\Str::limit($row->zone, 24) }}</span>@endif
                @if ($row->desk)<span class="bdg g">🪑 {{ \Illuminate\Support\Str::limit($row->desk, 20) }}</span>@endif
                @if ($row->dept)<span class="bdg g">🏷️ {{ \Illuminate\Support\Str::limit($row->dept, 24) }}</span>@endif
            </div>
        </div>
    </div>
</div>

{{-- ── الإشغال: من يجلس الآن، والإسنادُ والإخلاء (المسارُ الوحيد لكتابة الشاغل) ── --}}
<div class="card">
    <h3 class="cardtitle">👤 الإشغال
        @if ($stHolder)<span class="bdg ok">لـ{{ $stHolder }}</span>
        @else<span class="bdg g">متاحة — بلا شاغل</span>@endif
    </h3>

    @if ($stCan)
        <div class="two-forms">
            <form method="POST" action="{{ route('stations.assign', $row->id) }}">
                @csrf
                <div class="sub" style="margin-bottom:7px">👤 <b>إسنادُ المحطة لموظف</b> — يُسجَّل في تاريخ الإسناد ويُدقَّق.</div>
                <div class="fg">
                    <div class="fld">
                        <label for="st-user">المُسنَد إليه</label>
                        <select class="inp" id="st-user" name="user_id" required>
                            <option value=""></option>
                            @foreach (hub_ref_options('users', $row->current_employee_id) as $uid => $uname)
                                <option value="{{ $uid }}" @selected((string) $row->current_employee_id === (string) $uid)>{{ $uname }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="fld fw">
                        <label for="st-note">ملاحظة (اختياري)</label>
                        <input class="inp" id="st-note" name="note" maxlength="500" placeholder="مثال: مقعدٌ دائمٌ في قسم الدعم">
                    </div>
                </div>
                <button class="btn p sm" style="margin-top:10px">👤 تسجيل الإسناد</button>
            </form>

            @if ($row->current_employee_id)
                <form method="POST" action="{{ route('stations.vacate', $row->id) }}"
                      data-confirm="إخلاءُ المحطة من {{ $stHolder }}؟ تعود «متاحة» ويبقى الإخلاءُ أثراً في التاريخ.">
                    @csrf
                    <div class="sub" style="margin-bottom:7px">📤 <b>إخلاءُ المحطة</b> — عند مغادرة الموظف أو تغيير مقعده.</div>
                    <div class="fg">
                        <div class="fld fw">
                            <label for="st-vnote">ملاحظة (اختياري)</label>
                            <input class="inp" id="st-vnote" name="note" maxlength="500" placeholder="مثال: انتقل لمقعدٍ آخر">
                        </div>
                    </div>
                    <button class="btn sm" style="margin-top:10px">📤 تسجيل الإخلاء</button>
                </form>
            @endif
        </div>
    @else
        <div class="sub">تسجيلُ الإسناد والإخلاء يتطلّب صلاحية تعديل المحطات.</div>
    @endif
</div>

{{-- ── تاريخُ الإسناد: من جلس ومتى أُخلي — يبقى بعد مغادرة الموظف ── --}}
@if ($stHist->count())
<div class="card">
    <h3 class="cardtitle">🕐 تاريخ الإسناد <span class="sub">من جلس عليها ومتى — ما لا يقوله «الشاغل الحالي»</span></h3>
    <table class="tbl">
        <thead><tr><th style="width:150px">التاريخ</th><th style="width:110px">الحركة</th>
            <th>الطرف</th><th>ملاحظة</th></tr></thead>
        <tbody>
        @foreach ($stHist as $h)
            <tr>
                <td class="mono sub">{{ $h->at ? $h->at->format('Y-m-d H:i') : '—' }}</td>
                <td>
                    <span class="bdg {{ $h->action === 'vacate' ? 'g' : 'ok' }}">
                        {{ $h->action === 'vacate' ? 'إخلاء' : 'إسناد' }}
                    </span>
                </td>
                <td>{{ $h->user_id ? ($stNames[$h->user_id] ?? 'حسابٌ محذوف') : 'بلا شخص' }}</td>
                <td class="sub">{{ \Illuminate\Support\Str::limit($h->note ?? '', 60) ?: '—' }}
                    <div class="sub" style="font-size:11px">بيد {{ $h->by_id ? ($stNames[$h->by_id] ?? '—') : '—' }}</div></td>
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
