@extends('layouts.app')
@section('title', 'كانبان — ' . $def['label'])
@section('content')
<div class="toolbar">
    <a class="btn ghost sm" href="{{ route('m.index', $module) }}">☰ عرض القائمة</a>
    <div class="sub">اسحب أي بطاقة إلى عمود آخر لتغيير حالتها فوراً</div>
    <div class="spacer"></div>
    @if (hub_can(auth()->user(), $module, 'a'))
        <a class="btn p sm" href="{{ route('m.create', $module) }}" onclick="return Hub.modal(this.href)">＋ جديد</a>
    @endif
</div>
<div class="kanban" data-kanban data-url="{{ route('m.status', [$module, '__ID__']) }}" data-can="{{ hub_can(auth()->user(), $module, 'e') ? 1 : 0 }}">
    @foreach ($cols as $status => $items)
        <div class="kcol" data-status="{{ $status }}">
            <div class="khead"><span class="bdg {{ hub_tone($status) }}">{{ $status }}</span><span class="kcount sub">{{ count($items) }}</span></div>
            <div class="kbody">
                @foreach ($items as $row)
                    <div class="kcard" draggable="true" data-id="{{ $row->id }}">
                        <a href="{{ route('m.show', [$module, $row->id]) }}">{{ \Illuminate\Support\Str::limit($row->{$disp} ?? $row->id, 60) }}</a>
                        <div class="sub mono">{{ optional($row->created_at)->format('m-d') }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
@endsection
