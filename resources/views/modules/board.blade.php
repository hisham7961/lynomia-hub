@extends('layouts.app')
@section('title', 'كانبان — ' . $def['label'])
@section('content')
@php
    $canAdd = hub_can(auth()->user(), $module, 'a');
    $today = now()->toDateString();
    // مفتاح حقل الحالة (قد يخالف اسم العمود) — به تُبنى رابط التعبئة المسبقة لزر ＋
    $statusKey = collect($def['fields'] ?? [])->firstWhere('col', $statusCol)['key'] ?? $statusCol;
@endphp
<div class="toolbar">
    <a class="btn ghost sm" href="{{ route('m.index', $module) }}">☰ عرض القائمة</a>
    <div class="sub">اسحب أي بطاقة إلى عمود آخر لتغيير حالتها فوراً</div>
    <div class="spacer"></div>
    @if ($canAdd)
        <a class="btn p sm" href="{{ route('m.create', $module) }}">＋ جديد</a>
    @endif
</div>
<div class="kanban" data-kanban data-url="{{ route('m.status', [$module, '__ID__']) }}" data-can="{{ hub_can(auth()->user(), $module, 'e') ? 1 : 0 }}">
    @foreach ($cols as $status => $items)
        <div class="kcol" data-status="{{ $status }}">
            <div class="khead">
                <span class="bdg {{ hub_tone($status) }}">{{ $status }}</span>
                <span class="kcount sub">{{ count($items) }}</span>
                @if ($canAdd)
                    <a class="kadd" href="{{ route('m.create', $module) }}?{{ http_build_query([$statusKey => $status]) }}"
                       title="إضافة في «{{ $status }}»" aria-label="إضافة سجل في حالة {{ $status }}">＋</a>
                @endif
            </div>
            <div class="kbody">
                @forelse ($items as $row)
                    @php
                        $due = $dueF ? $row->{$dueF['col']} : null;
                        $due = $due ? substr((string) $due, 0, 10) : null;
                        $overdue = $due && $due < $today && ! hub_is_closed($row->{$statusCol} ?? null);
                        $prio = $prioF ? $row->{$prioF['col']} : null;
                        $who = $assigneeF ? ($assigneeNames[$row->{$assigneeF['col']}] ?? null) : null;
                        $ref = $refF ? ($refNames[$row->{$refF['col']}] ?? null) : null;
                    @endphp
                    <div class="kcard" draggable="true" data-id="{{ $row->id }}">
                        <a href="{{ route('m.show', [$module, $row->id]) }}">{{ \Illuminate\Support\Str::limit($row->{$disp} ?? $row->id, 60) }}</a>
                        @if ($ref)<div class="kref sub">{{ \Illuminate\Support\Str::limit($ref, 34) }}</div>@endif
                        @if ($prio || $due || $who)
                            <div class="kmeta">
                                @if ($prio)<span class="bdg {{ hub_tone($prio) }}">{{ $prio }}</span>@endif
                                @if ($due)<span class="kdue mono {{ $overdue ? 'late' : '' }}" title="{{ $overdue ? 'متأخرة عن الاستحقاق: ' . $due : 'الاستحقاق: ' . $due }}">{{ $overdue ? '⚠' : '📅' }} {{ $due }}</span>@endif
                                @if ($who)<span class="kwho" title="المسؤول: {{ $who }}"><span class="kav">{{ mb_substr($who, 0, 1) }}</span>{{ \Illuminate\Support\Str::limit($who, 16) }}</span>@endif
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="kempty sub">لا بطاقات — اسحب بطاقةً هنا{{ $canAdd ? ' أو أضف بزر ＋' : '' }}</div>
                @endforelse
            </div>
        </div>
    @endforeach
</div>
@endsection
