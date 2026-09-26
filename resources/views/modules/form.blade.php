@extends('layouts.app')
@section('title', ($row && empty($dup) ? 'تعديل' : 'إضافة') . ' — ' . $def['label'])
@section('content')
{{-- ترويسة هوية الوحدة — النموذج يعرف بيت من هو --}}
@php $look = hub_mod_look($module); $updating = $row && empty($dup); @endphp
<div class="lx-head" style="--mh:{{ $look['color'] }}">
    <span class="lx-ico">{{ $look['icon'] }}</span>
    <div>
        <div class="sub">{{ $def['label'] }}</div>
        <h2>{{ $updating ? '✏️ تعديل سجل' : (! empty($dup) ? '⎘ نسخ سجل' : '＋ سجل جديد') }}</h2>
    </div>
</div>
@if (! empty($suggest) && $updating)
    @php $sfields = collect($def['fields'] ?? [])->keyBy('key'); @endphp
    <div class="card" data-edit-suggest style="border-inline-start:4px solid var(--acc, #2a7ae2)">
        <b>✨ قيمٌ مقترحة عُبّئت في النموذج — راجعها قبل الحفظ، ولم يُحفَظ شيءٌ بعد:</b>
        <ul class="sub">
            @foreach ($suggest as $k => $v)
                <li>{{ $sfields[$k]['label'] ?? $k }}: {{ (string) ($row->{$sfields[$k]['col'] ?? $k} ?? '') !== '' ? $row->{$sfields[$k]['col'] ?? $k} : '—' }} ← <b>{{ $v }}</b></li>
            @endforeach
        </ul>
    </div>
@endif
<div class="card lx-form" style="--mh:{{ $look['color'] }}">
    @include('modules._form', ['hx' => false])
</div>
@endsection
