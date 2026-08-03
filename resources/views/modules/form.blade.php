@extends('layouts.app')
@section('title', ($row && empty($dup) ? 'تعديل' : 'إضافة') . ' — ' . $def['label'])
@section('content')
{{-- ترويسة هوية الوحدة — النموذج يعرف بيت من هو --}}
@php $look = hub_mod_look($module); $updating = $row && empty($dup); @endphp
<div class="modhero" style="--mh:{{ $look['color'] }}">
    <span class="mhico">{{ $look['icon'] }}</span>
    <div>
        <div class="sub">{{ $def['label'] }}</div>
        <h2>{{ $updating ? '✏️ تعديل سجل' : (! empty($dup) ? '⎘ نسخ سجل' : '＋ سجل جديد') }}</h2>
    </div>
</div>
<div class="card">
    @include('modules._form', ['hx' => false])
</div>
@endsection
