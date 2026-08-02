@extends('layouts.app')
@section('title', 'تخصيص أودو — ' . $row->name)
@section('content')
<div class="hero">
    <div>
        <h2>🧩 تخصيص أودو — {{ $row->name }}</h2>
        <div class="sub">
            اختر خادمَ هذا المشروع وقنواتِ بيعه: كلُّ قناةٍ (ترنديول، أمازون، نون، المتجر…)
            تتمايز في أودو بمميِّزٍ تختاره — فريقِ بيعٍ أو دفترٍ أو وسمٍ أو موقع — وأرقامُها تظهر في بطاقة المشروع.
        </div>
    </div>
    <div class="spacer"></div>
    <a class="btn ghost sm" href="{{ route('m.show', ['projects', $row->id]) }}">↪ صفحة المشروع</a>
</div>

@if ($err)<div class="ferr" style="margin-bottom:10px">⚠️ {{ $err }}</div>@endif

<div class="kids">
    {{-- ═ الخادم ═ --}}
    <div class="card kid">
        <h3>🖥️ خادم أودو لهذا المشروع</h3>
        <div class="sub">الحالي: <b>{{ $cli->label() }}</b>@if ($cli->error()) — <span class="ferr">{{ $cli->error() }}</span>@endif</div>
        @if (hub_is_owner())
            <form method="POST" action="{{ route('odoo.project.conn', $row->id) }}" class="crow" style="margin-top:8px">
                @csrf
                <select class="inp" name="conn" style="max-width:280px">
                    @foreach ($conns as $c)
                        <option value="{{ $c['id'] }}" @selected($cli->id() === $c['id'])>{{ $c['name'] }}{{ $c['ready'] ? '' : ' — ناقص البيانات' }}</option>
                    @endforeach
                </select>
                <button class="btn sm">تعيين</button>
            </form>
            <div class="sub" style="margin-top:6px">تغييرُ الخادم يغيّر كلَّ أرقام أودو في هذا المشروع — البطاقة والقنوات معاً.</div>
        @else
            <div class="sub" style="margin-top:6px">اختيارُ الخادم قرارُ مالك — تديرُ القنوات أدناه وحدها.</div>
        @endif
    </div>

    {{-- ═ القنوات الحالية ═ --}}
    <div class="card kid" id="chans">
        <h3>🛍 قنوات البيع المعرّفة ({{ count($channels) }})</h3>
        @if (! $channels)
            <div class="sub">لا قنوات بعد — أضِف من الجدول أدناه: اختر المميِّز ثم اضغط «إضافة» بجانب فريقك أو دفترك.</div>
        @else
            <table class="mini">
                @foreach ($channels as $c)
                    <tr>
                        <td>{{ $c['icon'] ?? '🛒' }} <b>{{ $c['label'] }}</b>
                            <div class="sub">{{ ['team' => 'فريق بيع', 'journal' => 'دفتر', 'tag' => 'وسم', 'website' => 'موقع'][$c['mode']] ?? $c['mode'] }}
                                · {{ $c['ref_name'] ?: '#' . $c['ref_id'] }}</div></td>
                        <td class="acts">
                            <form method="POST" action="{{ route('odoo.project.channel.del', $row->id) }}" class="inline"
                                  data-confirm="حذف قناة «{{ $c['label'] }}»؟">
                                @csrf<input type="hidden" name="key" value="{{ $c['key'] }}">
                                <button class="btn ghost xs danger">🗑</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </table>
            <form method="POST" action="{{ route('odoo.project.refresh', $row->id) }}" style="margin-top:8px">@csrf
                <button class="btn ghost xs">🔄 تحديث الأرقام الآن</button></form>
        @endif
    </div>
</div>

{{-- ═ اكتشاف المميِّزات حيّاً من أودو ═ --}}
<div class="card">
    <h3>➕ أضِف قناةً — ما الذي يفرّق قنواتك في أودو؟</h3>
    <div class="crow" style="margin:8px 0;flex-wrap:wrap;gap:6px">
        @foreach (['team' => '👥 فرق البيع', 'journal' => '📒 الدفاتر', 'tag' => '🏷️ الوسوم', 'website' => '🌐 المواقع'] as $mk => $ml)
            <a class="btn {{ $mode === $mk ? 'p' : 'ghost' }} sm" href="{{ route('odoo.project', $row->id) }}?mode={{ $mk }}#add">{{ $ml }}</a>
        @endforeach
    </div>
    <div class="sub" id="add" style="margin-bottom:8px">
        @if ($mode === 'team')<b>فريق البيع</b> الأنسبُ حين لكل منصةٍ فريقُ مبيعاتٍ في أودو (وهو ما تفعله موصلات المنصات غالباً) — تُحصى <b>أوامر البيع المؤكدة</b>.
        @elseif ($mode === 'journal')<b>الدفتر</b> الأنسبُ حين لكل منصةٍ دفترُ فواتيرَ مستقل — تُحصى <b>فواتير العملاء المرحّلة</b> لا الأوامر.
        @elseif ($mode === 'tag')<b>الوسم</b> الأنسبُ حين تُوسم أوامرُ كل منصةٍ بوسمها — تُحصى <b>أوامر البيع المؤكدة</b>.
        @else<b>الموقع</b> الأنسبُ مع Odoo Websites: لكل متجرٍ موقعُه — تُحصى <b>أوامر البيع المؤكدة</b>.@endif
    </div>

    @if ($options)
        <div class="tblwrap">
            <table class="tbl">
                <thead><tr><th>الاسم في أودو</th><th>المعرف</th><th>أضِف كقناة</th></tr></thead>
                <tbody>
                    @foreach ($options as $o)
                        <tr>
                            <td>{{ $o['name'] }}@if (! empty($o['code']))<span class="sub mono ltr"> · {{ $o['code'] }}</span>@endif</td>
                            <td class="mono ltr">{{ $o['id'] }}</td>
                            <td>
                                <form method="POST" action="{{ route('odoo.project.channel.add', $row->id) }}" class="crow">
                                    @csrf
                                    <input type="hidden" name="mode" value="{{ $mode }}">
                                    <input type="hidden" name="ref_id" value="{{ $o['id'] }}">
                                    <input type="hidden" name="ref_name" value="{{ $o['name'] }}">
                                    <input class="inp" name="label" list="chpresets" required maxlength="60"
                                           placeholder="اسم القناة عندك" style="max-width:170px" value="">
                                    <input class="inp" name="icon" maxlength="8" placeholder="🛒" style="max-width:64px">
                                    <button class="btn p xs">إضافة</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <datalist id="chpresets">
            <option value="ترنديول"><option value="أمازون"><option value="نون"><option value="المتجر الإلكتروني">
        </datalist>
    @elseif (! $err)
        <div class="sub">لا نتائج لهذا المميِّز في خادمك — جرّب مميِّزاً آخر من الأزرار أعلاه.</div>
    @endif
</div>
@endsection
