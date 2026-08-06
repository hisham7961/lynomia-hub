@extends('layouts.app')
@section('title', 'سجل التدقيق')
@section('content')
@include('partials.pagehead', ['icon' => '🧾', 'title' => 'سجل التدقيق', 'crumb' => 'النظام',
    'sub' => 'من فعل ماذا، بأي سجل، من أي عنوان — ومختومٌ ببصمةٍ متشابكة تكشف أي عبثٍ مباشر بالقاعدة'])

<div class="cards" style="margin-bottom:12px">
    <div class="kpi {{ $chain['ok'] ? '' : 'bad' }}">
        <div class="lbl">🔗 سلامة السلسلة</div>
        <div class="val" style="font-size:16px">{{ $chain['ok'] ? 'سلسلة سليمة' : '⚠️ عبث' }}</div>
        <div class="sub">{{ $chain['ok'] ? $chain['label'] : $chain['why'] }}</div>
    </div>
    <div class="kpi {{ $pulse['deletes'] ? 'wn' : '' }}">
        <div class="lbl">🗑️ حذف اليوم</div><div class="val">{{ $pulse['deletes'] }}</div>
        <div class="sub">أثرٌ لا يُستردّ</div>
    </div>
    <div class="kpi {{ $pulse['exports'] ? 'wn' : '' }}">
        <div class="lbl">📤 تصدير اليوم</div><div class="val">{{ $pulse['exports'] }}</div>
        <div class="sub">بياناتٌ غادرت النظام</div>
    </div>
    <div class="kpi {{ $pulse['offhours'] ? 'wn' : '' }}">
        <div class="lbl">🌙 خارج الدوام</div><div class="val">{{ $pulse['offhours'] }}</div>
        <div class="sub">عملٌ في وقتٍ غير معتاد</div>
    </div>
    <div class="kpi {{ count($pulse['newIps']) ? 'wn' : '' }}">
        <div class="lbl">🌐 عناوين جديدة</div><div class="val">{{ count($pulse['newIps']) }}</div>
        <div class="sub ltr mono">{{ implode(' · ', array_slice($pulse['newIps'], 0, 3)) ?: 'لا جديد' }}</div>
    </div>
</div>

@if (! $chain['ok'])
    <div class="card" style="border-color:var(--bad);margin-bottom:12px">
        <b>⚠️ سلسلة التدقيق مكسورة:</b> {{ $chain['why'] }}
        {{-- كان الإرشادُ سطرَ طرفيةٍ لا يملكها صاحبُ استضافةٍ مشتركة — فالفاحصُ
             الوحيدُ لهذا الضمان لا يُشغَّل أبداً. الآن زرٌّ في مركز التشغيل. --}}
        <div class="sub" style="margin-top:6px">الشاشة تفحص الذيل فقط لأن فحص الجدول كاملاً لا يُحتمل مع كل فتحة —
            @if (hub_is_owner())
                <form method="POST" action="{{ route('ops.verifyaudit') }}" class="inline">@csrf
                    <button class="btn ghost xs">🔗 افحص السلسلة كاملةً الآن</button></form>
                لتحديد أول قيدٍ متأثّر ومدى الضرر.
            @else
                اطلب من مالك النظام فحصَ السلسلة كاملةً من مركز التشغيل ⚙️ لتحديد أول قيدٍ متأثّر ومدى الضرر.
            @endif
        </div>
    </div>
@endif

<div class="toolbar">
    <form class="filters" method="GET">
        <select class="inp" name="user">
            <option value="">كل المستخدمين</option>
            @foreach ($users as $uid => $un)<option value="{{ $uid }}" @selected(request('user') === (string) $uid)>{{ $un }}</option>@endforeach
        </select>
        <select class="inp" name="action">
            <option value="">كل الإجراءات</option>
            @foreach ($actions as $act)<option value="{{ $act }}" @selected(request('action') === $act)>{{ $act }}</option>@endforeach
        </select>
        <select class="inp" name="module">
            <option value="">كل الوحدات</option>
            @foreach (hub_modules() as $mk => $md)<option value="{{ $mk }}" @selected(request('module') === $mk)>{{ $md['label'] }}</option>@endforeach
        </select>
        <input class="inp ltr" type="date" name="from" value="{{ request('from') }}" title="من تاريخ">
        <input class="inp ltr" type="date" name="to" value="{{ request('to') }}" title="إلى تاريخ">
        <input class="inp ltr" name="ip" value="{{ request('ip') }}" placeholder="IP" style="max-width:130px">
        <input class="inp" type="search" name="q" value="{{ request('q') }}" placeholder="اسم السجل أو السبب…">
        <button class="btn sm">بحث</button>
        @if (request()->query())<a class="btn ghost sm" href="{{ route('audit.index') }}">✕ مسح</a>@endif
    </form>
</div>

<div class="card pad0">
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>الوقت</th><th>المستخدم</th><th>الإجراء</th><th>الوحدة</th><th>السجل والتغيير</th><th>من أين</th></tr></thead>
        <tbody>
        @forelse ($rows as $a)
            @php $diff = \App\Support\Audit::diff($a->module, $a->before ?? null, $a->after ?? null); @endphp
            <tr>
                <td class="mono" title="{{ $a->created_at }}">{{ \Illuminate\Support\Carbon::parse($a->created_at)->format('m-d H:i') }}</td>
                <td>{{ $a->user_name ?? '—' }}</td>
                <td><span class="bdg {{ $a->action === 'حذف' ? 'bad' : ($a->action === 'إضافة' ? 'ok' : (str_contains($a->action, 'تصدير') || str_contains($a->action, 'عرض سرّ') ? 'wn' : '')) }}">{{ $a->action }}</span></td>
                <td>@if ($a->module)<a href="{{ route('m.index', $a->module) }}">{{ hub_mod($a->module)['label'] ?? $a->module }}</a>@else <span class="sub">—</span> @endif</td>
                <td>
                    @if ($a->module && $a->record_id)
                        <a href="{{ route('m.show', [$a->module, $a->record_id]) }}"><b>{{ \Illuminate\Support\Str::limit($a->name, 44) ?: 'سجل' }}</b></a>
                    @else
                        <b>{{ \Illuminate\Support\Str::limit($a->name, 44) ?: '—' }}</b>
                    @endif
                    @if ($a->reason)<div class="sub">💬 السبب: {{ \Illuminate\Support\Str::limit($a->reason, 70) }}</div>@endif

                    @if (count($diff))
                        <div style="margin-top:6px;display:flex;flex-direction:column;gap:3px">
                            @foreach (array_slice($diff, 0, 6) as $d)
                                <div class="sub" style="font-size:12px">
                                    <b>{{ $d['label'] }}:</b>
                                    <span style="text-decoration:line-through;opacity:.65">{{ $d['from'] }}</span>
                                    <span style="opacity:.5">←</span>
                                    <span class="bdg ok">{{ $d['to'] }}</span>
                                </div>
                            @endforeach
                            @if (count($diff) > 6)
                                <details><summary class="sub pointer">و{{ count($diff) - 6 }} حقلاً آخر ▾</summary>
                                    @foreach (array_slice($diff, 6) as $d)
                                        <div class="sub" style="font-size:12px"><b>{{ $d['label'] }}:</b>
                                            <span style="opacity:.65">{{ $d['from'] }}</span> ← {{ $d['to'] }}</div>
                                    @endforeach
                                </details>
                            @endif
                        </div>
                    @endif
                </td>
                <td class="mono ltr" style="font-size:11px">
                    {{ $a->ip ?: '—' }}
                    @if ($a->device)<div class="sub" title="{{ $a->device }}">{{ \Illuminate\Support\Str::limit($a->device, 26) }}</div>@endif
                    @if (empty($a->hash))<div class="bdg wn" title="قيدٌ بلا بصمة">بلا ختم</div>@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty"><span class="big">🧾</span>لا سجلات تطابق المرشِّحات</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
{{ $rows->links('partials.pagination_simple') }}

<div class="card" style="margin-top:12px">
    <h3>كيف يُقرأ هذا السجل؟</h3>
    <div class="sub" style="line-height:2">
        كل قيدٍ مختومٌ ببصمة <span class="mono ltr">sha256(البصمة السابقة | محتوى القيد)</span> — فتعديل صفٍّ واحدٍ مباشرةً في القاعدة يكسر كل ما بعده.
        السجل <b>لا يُعدَّل ولا يُحذف من الواجهة</b>، وتشمل السلسلة عمليات التصدير و<b>عرض الأسرار</b>.<br>
        الفرق يعرض <b>الحقول المتغيّرة وحدها</b> بأسمائها من سجل الوحدات — وقيم الأسرار وبصمات كلمات المرور تُخفى ولو ظهرت في القيد.
    </div>
</div>
@endsection
