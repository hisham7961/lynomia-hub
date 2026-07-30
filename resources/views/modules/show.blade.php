@extends('layouts.app')
@section('title', $def['label'] . ' — عرض')
@section('content')
<div class="toolbar">
    <a class="btn ghost sm" href="{{ route('m.index', $module) }}">→ رجوع</a>
    <div class="spacer"></div>
    @if ($module === 'hr')
        <a class="btn ghost sm" href="{{ route('portal.employee', $row->id) }}">🗂️ الملف الشامل</a>
    @endif
    @if ($module === 'apps')
        <a class="btn ghost sm" href="{{ route('apps.center', $row->id) }}">📱 مركز التطبيق</a>
    @endif
    @if (hub_can(auth()->user(), $module, 'a'))
        <a class="btn ghost sm" href="{{ route('m.create', [$module, 'from' => $row->id]) }}" onclick="return Hub.modal(this.href)">⎘ نسخ كسجل جديد</a>
    @endif
    @if (hub_can(auth()->user(), $module, 'e') && ! $row->trashed())
        <a class="btn p sm" href="{{ route('m.edit', [$module, $row->id]) }}" onclick="return Hub.modal(this.href)">تعديل</a>
    @endif
</div>
<span data-recent data-title="{{ $def['label'] }}: {{ \Illuminate\Support\Str::limit($row->{hub_display_col($module)} ?? $row->id, 28) }}" hidden></span>
@if ($module === 'approvals')
    @include('partials.approval_exec')
@endif
<div class="card">
    <dl class="detail">
        @foreach ($def['fields'] as $f)
            <div class="drow">
                <dt>{{ $f['label'] }}</dt>
                <dd>@include('partials._display', ['f' => $f, 'row' => $row, 'labels' => $labels, 'ctx' => 'show'])</dd>
            </div>
        @endforeach
        <div class="drow"><dt>أُنشئ</dt><dd class="mono">{{ optional($row->created_at)->format('Y-m-d H:i') }}</dd></div>
        <div class="drow"><dt>آخر تعديل</dt><dd class="mono">{{ optional($row->updated_at)->format('Y-m-d H:i') }} · نسخة {{ $row->version ?? 1 }}</dd></div>
    </dl>
</div>
@if (count($children))
    <h3 style="margin:4px 0 10px">🔗 السجلات المرتبطة</h3>
    <div class="kids">
        @foreach ($children as $ch)
            <div class="card kid">
                <h3>{{ $ch['label'] }} <span class="bdg">{{ number_format($ch['count']) }}</span></h3>
                <table class="mini">
                    @foreach ($ch['rows'] as $cr)
                        <tr><td><a href="{{ route('m.show', [$ch['module'], $cr->id]) }}">{{ \Illuminate\Support\Str::limit($cr->{$ch['display']} ?? $cr->id, 44) }}</a></td>
                        <td class="mono sub" style="width:1%;white-space:nowrap">{{ optional($cr->created_at)->format('m-d') }}</td></tr>
                    @endforeach
                </table>
                <div style="margin-top:8px"><a class="btn ghost xs" href="{{ route('m.index', [$ch['module'], 'f' => [$ch['field']['key'] => $row->id]]) }}">عرض الكل ←</a></div>
            </div>
        @endforeach
    </div>
@endif
@if ($versions->count() > 1)
    <div class="card">
        <h3>🕐 سجل الإصدارات</h3>
        @foreach ($versions as $v)
            <div class="vrow">
                <span class="n">{{ $v->version }}</span>
                <span class="mono sub">{{ optional($v->created_at)->format('Y-m-d H:i') }}</span>
                <span class="sub">{{ $verUsers[$v->changed_by] ?? '—' }}</span>
                <span class="spacer"></span>
                @if (! $loop->first && hub_can(auth()->user(), $module, 'e') && ! $row->trashed())
                    <form method="POST" action="{{ route('m.version.restore', [$module, $row->id, $v->version]) }}" class="inline" onsubmit="return confirm('استعادة النسخة {{ $v->version }}؟ الحالية تُحفظ قبلها.')">
                        @csrf<button class="btn ghost xs">استعادة</button>
                    </form>
                @endif
            </div>
        @endforeach
    </div>
@endif
@include('partials.comments', ['cModule' => $module, 'cRecordId' => $row->id, 'comments' => $comments, 'users' => $cUsers])
@endsection
