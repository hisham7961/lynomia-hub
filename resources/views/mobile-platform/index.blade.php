@extends('layouts.app')
@section('title', 'منصّة تطبيق الهاتف')
@section('content')
{{-- مركزُ منصّة تطبيق الهاتف (Mobile Platform Center) — قنصليّةٌ إداريّةٌ واحدةٌ فوق
     قدرات الجوال القائمة. عرضٌ صرفٌ بأصنافٍ قائمة (لا CSS جديد). --}}
@component('partials.pagehead', ['icon' => '📱', 'title' => 'منصّة تطبيق الهاتف',
    'crumb' => 'الإدارة والنظام',
    'sub' => 'فهم وضبط وتشغيل ومراقبة كلِّ ما يخصّ تطبيقَ الهاتف — من مكانٍ واحد'])
    <a class="btn ghost sm" href="{{ route('mobileplatform.index') }}">🔄 تحديث</a>
@endcomponent

@include('partials.cc.tabs', [
    'tabs'   => collect($tabs)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
    'active' => $active,
])

@if ($active === 'operations')
    @include('mobile-platform.tabs.operations', ['health' => $health])
@elseif ($active === 'devices')
    @include('mobile-platform.tabs.devices')
@elseif ($active === 'push')
    @include('mobile-platform.tabs.push')
@elseif ($active === 'config')
    @include('mobile-platform.tabs.config')
@elseif ($active === 'api')
    @include('mobile-platform.tabs.api')
@elseif ($active === 'security')
    @include('mobile-platform.tabs.security')
@elseif ($active === 'field')
    @include('mobile-platform.tabs.field')
@elseif ($active === 'docs')
    @include('mobile-platform.tabs.docs')
@else
    @include('mobile-platform.tabs.overview', ['ov' => $ov, 'scorecard' => $scorecard])
@endif
@endsection
