@extends('layouts.app')
@section('title', $def['label'])
@section('content')
<div class="toolbar">
    <form class="filters" method="GET"
          hx-boost="true" hx-target="#tblzone" hx-select="#tblzone" hx-swap="outerHTML" hx-push-url="true">
        <input class="inp" type="search" name="q" value="{{ request('q') }}" placeholder="بحث حي في {{ $def['label'] }}…"
               hx-get="{{ route('m.index', $module) }}" hx-include="closest form"
               hx-trigger="input changed delay:400ms" hx-target="#tblzone" hx-select="#tblzone" hx-swap="outerHTML" hx-push-url="true">
        @if ($statusOptions)
            <select class="inp" name="status"
                    hx-get="{{ route('m.index', $module) }}" hx-include="closest form"
                    hx-trigger="change" hx-target="#tblzone" hx-select="#tblzone" hx-swap="outerHTML" hx-push-url="true">
                <option value="">كل الحالات</option>
                @foreach ($statusOptions as $o)<option value="{{ $o }}" @selected(request('status') === $o)>{{ $o }}</option>@endforeach
            </select>
        @endif
        @if ($trash)<input type="hidden" name="trash" value="1">@endif
        @foreach ((array) request('f', []) as $fk => $fv)<input type="hidden" name="f[{{ $fk }}]" value="{{ $fv }}">@endforeach
        <noscript><button class="btn sm" type="submit">بحث</button></noscript>
    </form>
    <div class="spacer"></div>
    @if (! $trash && ($def['status'] ?? null))
        <a class="btn ghost sm" href="{{ route('m.board', $module) }}">🗂 كانبان</a>
    @endif
    @if (! $trash && (hub_flag(auth()->user(), 'exp') || auth()->user()->role?->is_owner))
        <a class="btn ghost sm" href="{{ route('m.export', ['module' => $module] + request()->query()) }}">📤 CSV</a>
    @endif
    @if (! $trash && hub_can(auth()->user(), $module, 'a') && ! hub_scoped(auth()->user()))
        <a class="btn ghost sm" href="{{ route('m.import', $module) }}">📥 استيراد</a>
    @endif
    @if (! $trash && hub_can(auth()->user(), $module, 'a'))
        <a class="btn p sm" id="newbtn" href="{{ route('m.create', $module) }}" onclick="return Hub.modal(this.href)">＋ جديد <span class="kbd">n</span></a>
    @endif
</div>
<div id="tblzone" hx-boost="true" hx-target="#tblzone" hx-select="#tblzone" hx-swap="outerHTML"
     hx-push-url="true" hx-select-oob="#flash:innerHTML">
    <div class="toolbar">
        @foreach ($filters as $fl)
            <span class="chip">{{ $fl['label'] }}: {{ $fl['name'] }}<a href="{{ request()->fullUrlWithQuery(['f' => null, 'page' => null]) }}" title="إزالة">✕</a></span>
        @endforeach
        <span class="spacer"></span>
        @if (hub_can(auth()->user(), $module, 'd'))
            <a class="btn ghost sm" href="{{ route('m.index', [$module, 'trash' => $trash ? null : 1]) }}">{{ $trash ? '↩ عودة للسجلات' : '🗑 السلة' }}</a>
        @endif
    </div>
    <div class="card pad0">
        <div class="tblwrap">
        <table class="tbl">
            <thead><tr>
                @foreach ($columns as $f)
                    @php $ns = ($sortKey === $f['key'] && $sortDir === 'desc') ? 'asc' : 'desc'; @endphp
                    <th><a href="{{ request()->fullUrlWithQuery(['s' => $f['key'], 'd' => $ns, 'page' => null]) }}">{{ $f['label'] }}@if ($sortKey === $f['key']) {{ $sortDir === 'asc' ? '▲' : '▼' }}@endif</a></th>
                @endforeach
                <th class="acts">إجراءات</th>
            </tr></thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($columns as $f)
                        <td>@include('partials._display', ['f' => $f, 'row' => $row, 'labels' => $labels, 'ctx' => 'table'])</td>
                    @endforeach
                    <td class="acts">
                        <a class="btn ghost xs" href="{{ route('m.show', [$module, $row->id]) }}">عرض</a>
                        @if ($trash)
                            <form method="POST" action="{{ route('m.restore', [$module, $row->id]) }}" class="inline">@csrf<button class="btn xs" type="submit">استعادة</button></form>
                        @else
                            @if (hub_can(auth()->user(), $module, 'e'))<a class="btn ghost xs" href="{{ route('m.edit', [$module, $row->id]) }}" onclick="return Hub.modal(this.href)">تعديل</a>@endif
                            @if (hub_can(auth()->user(), $module, 'd'))
                                <form method="POST" action="{{ route('m.destroy', [$module, $row->id]) }}" class="inline" onsubmit="return confirm('نقل السجل إلى السلة؟')">@csrf @method('DELETE')<button class="btn ghost xs dn" type="submit">حذف</button></form>
                            @endif
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ count($columns) + 1 }}" class="empty">
                    <span class="big">{{ $trash ? '🗑' : '📄' }}</span>
                    {{ $trash ? 'السلة فارغة' : (request('q') ? 'لا نتائج للبحث' : 'لا سجلات بعد') }}
                    @if (! $trash && ! request('q') && hub_can(auth()->user(), $module, 'a'))<div style="margin-top:10px"><a class="btn p sm" href="{{ route('m.create', $module) }}" onclick="return Hub.modal(this.href)">أضف أول سجل</a></div>@endif
                </td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </div>
    {{ $rows->links('partials.pagination') }}
</div>
@endsection
