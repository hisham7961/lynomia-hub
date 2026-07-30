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
    @if (in_array($module, ['requests', 'feats', 'tasks', 'changes', 'code', 'deploys', 'incidents'], true))
        <a class="btn ghost sm" href="{{ route('trace', [$module, $row->id]) }}">🧵 خيط التتبع</a>
    @endif
    @if ($module === 'clients')
        <a class="btn ghost sm" href="{{ route('journey', $row->id) }}">🧭 رحلة العميل</a>
    @endif
    @if (hub_can(auth()->user(), $module, 'a'))
        <a class="btn ghost sm" href="{{ route('m.create', [$module, 'from' => $row->id]) }}">⎘ نسخ كسجل جديد</a>
    @endif
    @if (hub_can(auth()->user(), $module, 'e') && ! $row->trashed())
        <a class="btn p sm" href="{{ route('m.edit', [$module, $row->id]) }}">تعديل</a>
    @endif
</div>
<span data-recent data-title="{{ $def['label'] }}: {{ \Illuminate\Support\Str::limit($row->{hub_display_col($module)} ?? $row->id, 28) }}" hidden></span>

{{-- ترويسة السجل: هوية الوحدة + الاسم كبيراً + شارة الحالة — تعرف ماذا تفتح من نظرة --}}
@php
    $look = hub_mod_look($module);
    $rDisp = (string) ($row->{hub_display_col($module)} ?? $row->id);
    $rStatus = ($def['status'] ?? null) ? ($row->{$def['status']} ?? null) : null;
@endphp
<div class="modhero" style="--mh:{{ $look['color'] }}">
    <span class="mhico">{{ $look['icon'] }}</span>
    <div style="min-width:0">
        <div class="sub">{{ $def['label'] }}</div>
        <h2 style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ \Illuminate\Support\Str::limit($rDisp, 70) }}</h2>
    </div>
    <div class="spacer"></div>
    @if ($row->trashed())<span class="bdg bad">🗑 في السلة</span>
    @elseif ($rStatus)<span class="bdg {{ hub_tone($rStatus) }}" style="font-size:12.5px;padding:5px 13px">{{ $rStatus }}</span>@endif
</div>
@if ($module === 'approvals')
    @include('partials.approval_exec')
@endif
@if ($module === 'quotes')
    @include('partials.quote_actions')
@endif
@if ($module === 'purchases')
    @include('partials.purchase_actions')
@endif
@if (in_array($module, ['projects', 'companies', 'clients'], true))
    @include('partials.odoo_card')
@endif
@if ($module === 'tickets')
    @include('partials.ticket_sla')
@endif
<div class="card">
    <dl class="detail">
        @foreach ($def['fields'] as $f)
            @continue(hub_field_mode(auth()->user(), $module, $f['key']) === 'hide')
            <div class="drow">
                <dt>{{ $f['label'] }}</dt>
                <dd>@include('partials._display', ['f' => $f, 'row' => $row, 'labels' => $labels, 'ctx' => 'show'])</dd>
            </div>
        @endforeach
        @foreach (hub_custom_fields($module) as $cf)
            @php $cv = data_get($row->custom, $cf['key']); @endphp
            <div class="drow">
                <dt>{{ $cf['label'] }} <span class="sub">· مخصص</span></dt>
                <dd>
                    @if ($cv === null || $cv === '')—
                    @elseif (($cf['type'] ?? '') === 'bool'){{ $cv ? '✓ نعم' : 'لا' }}
                    @elseif (($cf['type'] ?? '') === 'ref'){{ hub_ref_labels($cf['ref'], [$cv])[$cv] ?? $cv }}
                    @else{{ $cv }}
                    @endif
                </dd>
            </div>
        @endforeach
        <div class="drow"><dt>أُنشئ</dt><dd class="mono">{{ optional($row->created_at)->format('Y-m-d H:i') }}</dd></div>
        <div class="drow"><dt>آخر تعديل</dt><dd class="mono">{{ optional($row->updated_at)->format('Y-m-d H:i') }} · نسخة {{ $row->version ?? 1 }}</dd></div>
    </dl>
</div>
@include('partials.record_list', ['children' => $children, 'ownerId' => $row->id])
@include('partials.timeline', ['timeline' => $timeline])
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
                    <form method="POST" action="{{ route('m.version.restore', [$module, $row->id, $v->version]) }}" class="inline" data-confirm="استعادة النسخة {{ $v->version }}؟ الحالية تُحفظ قبلها.">
                        @csrf<button class="btn ghost xs">استعادة</button>
                    </form>
                @endif
            </div>
        @endforeach
    </div>
@endif
@include('partials.attachments', ['aModule' => $module, 'aRecordId' => $row->id])
@include('partials.comments', ['cModule' => $module, 'cRecordId' => $row->id, 'comments' => $comments, 'users' => $cUsers])
@endsection
