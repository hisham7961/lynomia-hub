@extends('layouts.app')
@section('title', 'تشخيص الوصول')
@section('content')
@component('partials.pagehead', ['icon' => '🔎', 'title' => 'تشخيص الوصول', 'crumb' => 'النظام',
    'sub' => 'ماذا يرى هذا الموظف — ولماذا؟ من محرّك الصلاحيّة نفسِه لا من قائمةٍ مخزّنة'])
    <a class="btn ghost sm" href="{{ route('roles.index') }}">🎭 الأدوار</a>
@endcomponent

{{-- منتقي المستخدم — القرار محسوبٌ حيّاً من PermissionInspector عند الاختيار --}}
<div class="card">
    <form method="GET" class="crow" style="gap:8px;flex-wrap:wrap;align-items:end">
        <div>
            <label class="sub" for="user">اختر مستخدماً</label><br>
            <select class="inp" id="user" name="user" onchange="this.form.submit()" style="min-width:280px">
                <option value="">— اختر —</option>
                @foreach ($users as $u)
                    <option value="{{ $u->id }}" @selected($selected && (string) $selected->id === (string) $u->id)>
                        {{ $u->name }} @if ($u->account_type === 'client') · عميل @endif</option>
                @endforeach
            </select>
        </div>
    </form>
</div>

@if (! $selected)
    <div class="card"><div class="sub" style="padding:14px;text-align:center">اختر مستخدماً لعرض صلاحيّته الفعّالة وتنقّله وأسبابِ المنع.</div></div>
@else
    {{-- ملخّص النطاق (§20/§21) — الفارغُ يُوصَف صراحةً --}}
    <div class="card">
        <h3 style="margin:0 0 8px">🧭 ملخّص الوصول — {{ $selected->name }}</h3>
        <div class="crow" style="gap:6px;flex-wrap:wrap">
            <span class="bdg {{ $scope['is_owner'] ? 'ok' : '' }}">{{ $scope['account_type'] }}</span>
            <span class="bdg">الدور: {{ $scope['role'] }}</span>
            @if ($scope['is_owner'])<span class="bdg ok">مالك — وصولٌ كامل</span>@endif
            <span class="bdg">الشركات: {{ $scope['companies'] }}</span>
            <span class="bdg">العملاء: {{ $scope['clients'] }}</span>
            <span class="bdg">المشاريع: {{ $scope['project_scope'] }}</span>
            <span class="bdg {{ $scope['status'] === 'نشط' ? 'ok' : 'bad' }}">الحالة: {{ $scope['status'] }}</span>
        </div>
    </div>

    {{-- فحصٌ نقطيّ: وحدة+عمليّة → سلسلة السبب (§59) --}}
    <div class="card">
        <h3 style="margin:0 0 8px">🩺 لماذا؟ — فحصُ (وحدة · عمليّة)</h3>
        <form method="GET" class="crow" style="gap:8px;flex-wrap:wrap;align-items:end">
            <input type="hidden" name="user" value="{{ $selected->id }}">
            <div><label class="sub" for="module">الوحدة</label><br>
                <select class="inp" id="module" name="module" style="min-width:220px">
                    @foreach ($modules as $mk => $md)
                        <option value="{{ $mk }}" @selected($probeModule === $mk)>{{ $md['label'] ?? $mk }} ({{ $mk }})</option>
                    @endforeach
                </select></div>
            <div><label class="sub" for="op">العمليّة</label><br>
                <select class="inp" id="op" name="op">
                    @foreach ($ops as $ok => $olabel)<option value="{{ $ok }}" @selected($probeOp === $ok)>{{ $olabel }}</option>@endforeach
                </select></div>
            <button class="btn p sm" type="submit">افحص</button>
        </form>
        @if ($probe)
            <div style="margin-top:10px">
                <span class="bdg {{ $probe['allowed'] ? 'ok' : 'bad' }}" style="font-size:14px">
                    {{ $probe['allowed'] ? '✅ مسموح' : '⛔ ممنوع' }} — {{ $probe['reason'] }}</span>
                <ol style="margin:8px 0 0;padding-inline-start:20px">
                    @foreach ($probe['chain'] as $step)
                        <li class="sub" style="margin:2px 0">
                            {{ $step['ok'] ? '✓' : '✗' }} <b>{{ $step['step'] }}</b> — {{ $step['detail'] }}
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif
    </div>

    {{-- معاينة التنقّل (§60): مرئيّ مقابل مخفيّ بسببه --}}
    <div class="card">
        <h3 style="margin:0 0 8px">👁️ التنقّل الفعّال — مرئيّ {{ count($nav['visible']) }} · مخفيّ {{ count($nav['hidden']) }}</h3>
        <div class="crow" style="gap:6px;flex-wrap:wrap;margin-bottom:10px">
            @forelse ($nav['visible'] as $v)<span class="bdg ok" title="{{ $v['key'] }}">{{ $v['label'] }}</span>@empty<span class="sub">لا وحداتٍ مرئيّة</span>@endforelse
        </div>
        <details>
            <summary class="sub" style="cursor:pointer">المخفيّة وأسبابُها ({{ count($nav['hidden']) }})</summary>
            <div class="tblwrap" style="margin-top:8px"><table class="tbl">
                <thead><tr><th>الوحدة</th><th>الحالة</th><th>السبب</th></tr></thead>
                <tbody>
                @foreach ($nav['hidden'] as $h)
                    <tr><td>{{ $h['label'] }} <span class="sub mono ltr" style="font-size:10px">{{ $h['key'] }}</span></td>
                        <td><span class="bdg">{{ $h['state'] }}</span></td>
                        <td class="sub">{{ $h['reason'] }}</td></tr>
                @endforeach
                </tbody>
            </table></div>
        </details>
    </div>

    {{-- المصفوفة الفعّالة (§22) — كلُّ وحدةٍ × v/a/e/d بقيمها الفعّالة مجموعةً --}}
    <div class="card">
        <h3 style="margin:0 0 8px">🧮 الصلاحيّة الفعّالة بالوحدات</h3>
        @foreach ($matrix as $gLabel => $g)
            <h4 style="margin:12px 0 4px">{{ $g['icon'] }} {{ $gLabel }}</h4>
            <div class="tblwrap"><table class="tbl">
                <thead><tr><th>الوحدة</th><th>عرض</th><th>إضافة</th><th>تعديل</th><th>حذف</th><th>سبب المنع</th></tr></thead>
                <tbody>
                @foreach ($g['rows'] as $row)
                    <tr>
                        <td>{{ $row['label'] }} <span class="sub mono ltr" style="font-size:10px">{{ $row['key'] }}</span></td>
                        <td>{{ $row['v'] ? '✅' : '—' }}</td>
                        <td>{{ $row['a'] ? '✅' : '—' }}</td>
                        <td>{{ $row['e'] ? '✅' : '—' }}</td>
                        <td>{{ $row['d'] ? '✅' : '—' }}</td>
                        <td class="sub">{{ $row['v'] ? '' : $row['reason'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endforeach
    </div>
@endif
@endsection
