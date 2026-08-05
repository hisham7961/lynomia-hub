@extends('layouts.app')
@section('title', $def['label'] . ' — عرض')
@section('content')
<span data-recent data-title="{{ $def['label'] }}: {{ \Illuminate\Support\Str::limit($row->{hub_display_col($module)} ?? $row->id, 28) }}" hidden></span>

{{-- ═ ترويسة السجل الموحدة: مسارٌ ← هوية ← حالة ← إجراءات — تشريحُ صفحةِ سجلٍّ مؤسسي ═ --}}
@php
    $look = hub_mod_look($module);
    $rDisp = (string) ($row->{hub_display_col($module)} ?? $row->id);
    $rStatus = ($def['status'] ?? null) ? ($row->{$def['status']} ?? null) : null;
@endphp
<header class="rechead" style="--mh:{{ $look['color'] }}">
    <nav class="crumbs" aria-label="مسار التنقل">
        <a href="{{ route('m.index', $module) }}">{{ $def['label'] }}</a>
        <span aria-hidden="true">‹</span>
        <b>{{ \Illuminate\Support\Str::limit($rDisp, 44) }}</b>
    </nav>
    <div class="rh-row">
        <span class="mhico" aria-hidden="true">{{ $look['icon'] }}</span>
        <div class="rh-id">
            <h2>{{ \Illuminate\Support\Str::limit($rDisp, 70) }}</h2>
            <div class="rh-meta">
                @if ($row->trashed())<span class="bdg bad">🗑 في السلة</span>
                @elseif ($rStatus)<span class="bdg {{ hub_tone($rStatus) }}">{{ $rStatus }}</span>@endif
                <span class="sub">نسخة {{ $row->version ?? 1 }}</span>
                <span class="sub">حُدّث {{ optional($row->updated_at)->diffForHumans() }}</span>
            </div>
        </div>
        <div class="spacer"></div>
        <div class="rh-acts">
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
    </div>
</header>

{{-- ═ جسم السجل: عمودان — التدفق الرئيسي، وسِكّة جانبية لاصقة للسياق ═ --}}
<div class="rec">
    <div class="rec-main">
        @if ($module === 'approvals')
            @include('partials.approval_exec')
        @endif
        @if ($module === 'quotes')
            @include('partials.quote_actions')
        @endif
        @if ($module === 'purchases')
            @include('partials.purchase_actions')
        @endif
        @if ($module === 'fin')
            @include('partials.fin_actions')
        @endif
        @if ($module === 'stockmv')
            @include('partials.stockmv_actions')
        @endif
        @if ($module === 'tickets')
            @include('partials.ticket_sla')
        @endif
        @if ($module === 'contracts' && hub_can(auth()->user(), 'contracts', 'v'))
            @include('partials.esign_card')
        @endif
        {{-- حسابُ النظام يُفتح من ملفّ صاحبه لا من شاشةٍ أخرى يجب أن تُعرَف --}}
        @if ($module === 'hr')
            @include('partials.staff_account_card')
        @endif
        {{-- الإقرار الموثَّق — يظهر للوحدات المسجَّلة في config/hub_acks.php وحدها --}}
        @include('partials.acks')
        {{-- v2.123: خطاف مساحة عمل مخصصة للوحدة — لا أثر على وحدة بلا ملف مخصص --}}
        @includeIf('modules.custom.' . $module)

        <div class="card" style="--mh:{{ $look['color'] }}">
            <h3 class="cardtitle">📋 البيانات</h3>
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
            </dl>
        </div>

        {{-- ملف الكيان: الوثائق المتعارف عليها — ما يوجد وما ينقص وما ينتهي --}}
        @include('partials.dossier')
        @include('partials.record_list', ['children' => $children, 'ownerId' => $row->id])
        @include('partials.timeline', ['timeline' => $timeline])
        @include('partials.comments', ['cModule' => $module, 'cRecordId' => $row->id, 'comments' => $comments, 'users' => $cUsers])
    </div>

    <aside class="rec-rail">
        @if (in_array($module, ['projects', 'companies', 'clients'], true))
            @include('partials.odoo_card')
        @endif
        {{-- مبيعات قنوات البيع (ترنديول/أمازون/نون/المتجر) من أودو — للمشاريع --}}
        @if ($module === 'projects')
            @include('partials.odoo_channels_card')
        @endif
        @if (in_array($module, ['clients', 'companies', 'hr', 'suppliers', 'recruit', 'projects',
                                'approvals', 'decisions', 'policies', 'policyacks', 'assets'], true) && hub_can(auth()->user(), 'contracts', 'v'))
            @include('partials.esign_linked')
        @endif
        @include('partials.attachments', ['aModule' => $module, 'aRecordId' => $row->id])
        @if ($versions->count() > 1)
            <div class="card">
                <h3 class="cardtitle">🕐 سجل الإصدارات</h3>
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
        {{-- بطاقة الأثر: من أين جاء السجل ومتى — كانت غارقة وسط الحقول --}}
        <div class="card meta">
            <div class="mrow"><span>أُنشئ</span><b class="mono">{{ optional($row->created_at)->format('Y-m-d H:i') }}</b></div>
            <div class="mrow"><span>آخر تعديل</span><b class="mono">{{ optional($row->updated_at)->format('Y-m-d H:i') }}</b></div>
            <div class="mrow"><span>النسخة</span><b class="mono">{{ $row->version ?? 1 }}</b></div>
        </div>
    </aside>
</div>
@endsection
