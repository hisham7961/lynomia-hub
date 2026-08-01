@extends('layouts.app')
@section('title', $def['label'])
@section('content')
{{-- خطاف لافتة الوحدة (تنبيهات الأرشفة والتحويل) — لا أثر لوحدةٍ بلا ملف --}}
@includeIf('modules.notice.' . $module)
{{-- لوحةٌ خاصة بالوحدة فوق جدولها (اختيارية) — الجدول للتحرير وهذه للقرار --}}
@includeIf('modules.catalog.' . $module)
{{-- ترويسة هوية الوحدة: أيقونة ولون مجموعتها + العدد الحي --}}
@php $look = hub_mod_look($module); @endphp
<div class="modhero" style="--mh:{{ $look['color'] }}">
    <span class="mhico">{{ $look['icon'] }}</span>
    <div>
        <h2>{{ $def['label'] }}@if ($trash) <span class="sub">— 🗑 السلة</span>@endif</h2>
        <div class="sub">{{ number_format(method_exists($rows, 'total') ? $rows->total() : count($rows)) }} سجل</div>
    </div>
    <div class="spacer"></div>
    {{-- الإجراءات في الترويسة نفسها — نمط «رأس صفحةٍ» واحد: الهوية بدايةً والفعل نهايةً --}}
    <div class="rh-acts">
        @if (! $trash && ($def['status'] ?? null))
            <a class="btn ghost sm" href="{{ route('m.board', $module) }}">🗂 كانبان</a>
        @endif
        @if (! $trash && (hub_exporter()))
            <a class="btn ghost sm" href="{{ route('m.export', ['module' => $module] + request()->query()) }}">📤 CSV</a>
        @endif
        @if (! $trash && hub_can(auth()->user(), $module, 'a') && ! hub_scoped(auth()->user()))
            <a class="btn ghost sm" href="{{ route('m.import', $module) }}">📥 استيراد</a>
        @endif
        @if (! $trash && hub_can(auth()->user(), $module, 'a'))
            <a class="btn p sm" id="newbtn" href="{{ route('m.create', $module) }}">＋ جديد <span class="kbd">n</span></a>
        @endif
    </div>
</div>
<div class="toolbar">
    <form class="filters" method="GET"
          hx-boost="true" hx-target="#tblzone" hx-select="#tblzone" hx-swap="outerHTML" hx-push-url="true">
        <input class="inp" type="search" name="q" value="{{ hub_str(request('q')) }}" placeholder="بحث حي في {{ $def['label'] }}…"
               hx-get="{{ route('m.index', $module) }}" hx-include="closest form"
               hx-trigger="input changed delay:400ms" hx-target="#tblzone" hx-select="#tblzone" hx-swap="outerHTML" hx-push-url="true">
        @if ($statusOptions)
            <select class="inp" name="status"
                    hx-get="{{ route('m.index', $module) }}" hx-include="closest form"
                    hx-trigger="change" hx-target="#tblzone" hx-select="#tblzone" hx-swap="outerHTML" hx-push-url="true">
                <option value="">كل الحالات</option>
                @foreach ($statusOptions as $o)<option value="{{ $o }}" @selected(hub_str(request('status')) === $o)>{{ $o }}</option>@endforeach
            </select>
        @endif
        @if ($trash)<input type="hidden" name="trash" value="1">@endif
        {{-- المفتاح والقيمة نصّان أو لا يُبثّان: `?f[x][]=y` كان يُلقى في htmlspecialchars فتسقط الشاشة --}}
        @foreach ((array) request('f', []) as $fk => $fv)@if (is_string($fk) && is_string($fv))<input type="hidden" name="f[{{ $fk }}]" value="{{ $fv }}">@endif @endforeach
        @php
            // باني الفلاتر المتقدم: الحقول القابلة للترشيح فقط (لا أسرار/ملفات/مراجع) وغير المخفية عن المستخدم
            $advFields = collect($def['fields'])
                ->filter(fn ($f) => isset(\App\Http\Controllers\Web\ModuleController::FL_OPS[$f['type'] ?? 'text'])
                    && hub_field_mode(auth()->user(), $module, $f['key']) !== 'hide')
                ->map(fn ($f) => ['k' => $f['key'], 'l' => $f['label'], 't' => $f['type'], 'o' => array_values($f['options'] ?? [])])
                ->values();
            $nfl = count((array) request('fl', []));
        @endphp
        @if ($advFields->isNotEmpty() && ! $trash)
            <details class="ddwrap advfl" @if ($nfl) open @endif>
                <summary class="btn ghost sm ddsum">⚙ فلاتر@if ($nfl) <span class="bdg">{{ $nfl }}</span>@endif ▾</summary>
                <div class="card ddpanel advbox" data-advfl
                     data-fields='@json($advFields)'
                     data-ops='@json(\App\Http\Controllers\Web\ModuleController::FL_OPS)'
                     data-lbl='@json(\App\Http\Controllers\Web\ModuleController::FL_LABELS)'
                     data-init='@json(array_values((array) request('fl', [])))'>
                    <div class="advrows"></div>
                    <div class="advacts">
                        <button class="btn ghost xs" type="button" data-advadd>＋ شرط</button>
                        <span class="spacer"></span>
                        <button class="btn xs" type="submit">تطبيق</button>
                    </div>
                </div>
            </details>
        @endif
        <noscript><button class="btn sm" type="submit">بحث</button></noscript>
    </form>
    {{-- العروض والأعمدة خارج منطقة التبديل الحي (#tblzone): نماذج عادية غير مُعزَّزة
         كي لا يبتلع htmx تأكيد الحذف أو رد الحد ٤٢٢، ولا يمسحها البحث الحي --}}
    <details class="ddwrap">
        <summary class="btn ghost sm ddsum">📑 {{ $activeView?->name ?? 'العروض' }} ▾</summary>
        <div class="card ddpanel">
            @forelse ($views as $v)
                <div style="display:flex;gap:6px;align-items:center;padding:4px 0">
                    <a href="{{ $v->url() }}" style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $v->is_default ? '⭐ ' : '' }}{{ $v->name }}</a>
                    <form method="POST" action="{{ route('views.default', $v->id) }}" class="inline">@csrf
                        <button class="btn ghost xs" title="{{ $v->is_default ? 'إلغاء الافتراضي' : 'اجعله الافتراضي — يُفتح تلقائياً' }}">{{ $v->is_default ? '★' : '☆' }}</button></form>
                    <form method="POST" action="{{ route('views.destroy', $v->id) }}" class="inline" data-confirm="حذف هذا العرض؟">
                        @csrf @method('DELETE')<button class="btn ghost xs" aria-label="حذف العرض {{ $v->name }}">✕</button></form>
                </div>
            @empty
                <div class="sub" style="padding:4px 0">لا عروض محفوظة — رشّح وابحث وافرز ثم احفظ النتيجة باسم</div>
            @endforelse
            {{-- حقل الاستعلام يُملأ من العنوان لحظة الإرسال ليواكب البحث الحي المدفوع للعنوان --}}
            <form method="POST" action="{{ route('views.store') }}" onsubmit="this.query.value=location.search.replace(/^\?/,'')"
                  style="display:flex;gap:6px;align-items:center;margin-top:8px;border-top:1px solid var(--brd);padding-top:8px;flex-wrap:wrap">
                @csrf
                <input type="hidden" name="module" value="{{ $module }}">
                <input type="hidden" name="query" value="{{ request()->getQueryString() }}">
                <label class="vh" for="sv-name">اسم العرض</label>
                <input class="inp" id="sv-name" name="name" maxlength="60" required placeholder="اسم العرض الحالي…" style="flex:1;min-width:130px">
                <label class="chk sub"><input type="checkbox" name="default" value="1"> افتراضي</label>
                <button class="btn sm">💾 حفظ</button>
            </form>
        </div>
    </details>
    <details class="ddwrap">
        <summary class="btn ghost sm ddsum">⚙️ الأعمدة ▾</summary>
        <div class="card ddpanel" style="max-height:320px;overflow:auto">
            @php $curCols = collect($columns)->pluck('key')->all(); @endphp
            <form method="POST" action="{{ route('prefs.cols') }}">
                @csrf
                <input type="hidden" name="module" value="{{ $module }}">
                @foreach ($allFields as $af)
                    <label class="chk" style="display:flex;padding:2px 0"><input type="checkbox" name="cols[]" value="{{ $af['key'] }}" @checked(in_array($af['key'], $curCols, true))> {{ $af['label'] }}</label>
                @endforeach
                <div style="display:flex;gap:6px;margin-top:8px">
                    <button class="btn sm">حفظ أعمدتي</button>
                    <button class="btn ghost sm" name="reset" value="1">↺ الافتراضي</button>
                </div>
            </form>
        </div>
    </details>
    <div class="spacer"></div>
</div>
<div id="tblzone" hx-boost="true" hx-target="#tblzone" hx-select="#tblzone" hx-swap="outerHTML"
     hx-push-url="true" hx-select-oob="#flash:innerHTML">
    <div class="toolbar">
        @foreach ($filters as $fl)
            {{-- إزالة الفلتر تُسقط معه علامة العرض: العرض المطبَّق لم يعد قائماً --}}
            <span class="chip">{{ $fl['label'] }} {{ $fl['op'] ?? ':' }} {{ $fl['name'] }}<a href="{{ $fl['rmurl'] ?? request()->fullUrlWithQuery(['f' => null, 'page' => null, 'view' => null]) }}" title="إزالة">✕</a></span>
        @endforeach
        <span class="spacer"></span>
        @if (hub_can(auth()->user(), $module, 'd'))
            <a class="btn ghost sm" href="{{ route('m.index', [$module, 'trash' => $trash ? null : 1]) }}">{{ $trash ? '↩ عودة للسجلات' : '🗑 السلة' }}</a>
        @endif
    </div>
    @php
        // الإجراءات الجماعية: تظهر أعمدة التحديد لمن يملك فعلاً جماعياً واحداً على الأقل
        // الحرّاس هنا نسخةٌ من حرّاس الخادم: زرٌّ يُعرض ثم يُردّ بـ٤٠٣ عيبُ واجهةٍ لا أمان
        $bulkStatus = ! $trash && ! empty($def['status']) && hub_can(auth()->user(), $module, 'e')
            && hub_field_mode(auth()->user(), $module, (string) (hub_status_field($module)['key'] ?? 'status')) === ''
            && ! hub_needs_approval(auth()->user(), $module, 'e');
        $bulkDelete = ! $trash && hub_can(auth()->user(), $module, 'd')
            && ! hub_needs_approval(auth()->user(), $module, 'd');
        $bulkExport = ! $trash && hub_exporter();
        $canBulk = $bulkStatus || $bulkDelete || $bulkExport;
        $statusOpts = $bulkStatus ? (hub_status_field($module)['options'] ?? []) : [];
        $bulkStatus = $bulkStatus && count($statusOpts) > 0;
    @endphp
    <div class="card pad0">
        <div class="tblwrap">
        <table class="tbl">
            <thead><tr>
                @if ($canBulk)<th class="bsel"><input type="checkbox" id="ballsel" aria-label="تحديد الكل"></th>@endif
                @foreach ($columns as $f)
                    @php $ns = ($sortKey === $f['key'] && $sortDir === 'desc') ? 'asc' : 'desc'; @endphp
                    <th><a href="{{ request()->fullUrlWithQuery(['s' => $f['key'], 'd' => $ns, 'page' => null]) }}">{{ $f['label'] }}@if ($sortKey === $f['key']) {{ $sortDir === 'asc' ? '▲' : '▼' }}@endif</a></th>
                @endforeach
                <th class="acts">إجراءات</th>
            </tr></thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    @if ($canBulk)<td class="bsel"><input type="checkbox" class="brow" value="{{ $row->id }}" aria-label="تحديد السجل"></td>@endif
                    @foreach ($columns as $f)
                        <td>@include('partials._display', ['f' => $f, 'row' => $row, 'labels' => $labels, 'ctx' => 'table'])</td>
                    @endforeach
                    <td class="acts">
                        <a class="btn ghost xs" hx-boost="false" href="{{ route('m.show', [$module, $row->id]) }}">عرض</a>
                        @if ($trash)
                            <form method="POST" action="{{ route('m.restore', [$module, $row->id]) }}" class="inline">@csrf<button class="btn xs" type="submit">استعادة</button></form>
                        @else
                            @if (hub_can(auth()->user(), $module, 'e'))<a class="btn ghost xs" hx-boost="false" href="{{ route('m.edit', [$module, $row->id]) }}">تعديل</a>@endif
                            @if (hub_can(auth()->user(), $module, 'd'))
                                <form method="POST" action="{{ route('m.destroy', [$module, $row->id]) }}" class="inline" data-confirm="نقل السجل إلى السلة؟">@csrf @method('DELETE')<button class="btn ghost xs dn" type="submit">حذف</button></form>
                            @endif
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ count($columns) + 1 + ($canBulk ? 1 : 0) }}" class="empty">
                    <span class="big">{{ $trash ? '🗑' : '📄' }}</span>
                    {{ $trash ? 'السلة فارغة' : (hub_str(request('q')) !== '' ? 'لا نتائج للبحث' : 'لا سجلات بعد') }}
                    @if (! $trash && hub_str(request('q')) === '' && hub_can(auth()->user(), $module, 'a'))<div style="margin-top:10px"><a class="btn p sm" hx-boost="false" href="{{ route('m.create', $module) }}">أضف أول سجل</a></div>@endif
                </td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </div>
    {{ $rows->links('partials.pagination') }}
    @if ($canBulk)
        {{-- شريط الإجراءات الجماعية: يطفو حين يُحدد سجل — الحذف بضغطتي تأكيد --}}
        <form id="bulkbar" method="POST" action="{{ route('m.bulk', $module) }}" hidden aria-live="polite">
            @csrf
            <b><span id="bulkn">0</span> محدد</b>
            @if ($bulkStatus)
                <label class="bulkgrp">
                    <select name="status" class="inp" aria-label="الحالة الجديدة">
                        @foreach ($statusOpts as $o)<option value="{{ $o }}">{{ $o }}</option>@endforeach
                    </select>
                    <button class="btn sm" type="submit" name="do" value="status">تغيير الحالة</button>
                </label>
            @endif
            @if ($bulkExport)
                <button class="btn ghost sm" type="submit" name="do" value="export">⬇ تصدير المحدد</button>
            @endif
            @if ($bulkDelete)
                <button class="btn ghost sm dn" type="submit" name="do" value="delete"
                        data-confirm="نقل السجلات المحددة إلى السلة؟">🗑 حذف</button>
            @endif
            <button class="btn ghost sm" type="button" id="bulkclear">إلغاء</button>
        </form>
    @endif
</div>
@endsection
