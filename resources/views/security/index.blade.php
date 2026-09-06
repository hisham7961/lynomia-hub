@extends('layouts.app')
@section('title', 'مركز الأمان')
@section('content')
@php /* (WP-4.1) قُسّمت الشاشةُ أقساماً مكافئةً بايتاً — كلُّ قسمٍ ملفُّه في security/parts فتحرّر كلُّ حزمةِ عملٍ لاحقةٍ قسمَها وحده بلا تزاحم (نمطُ ops/parts في الطور ٢). البطاقاتُ داخل .kids تُضمَّن بمسافةٍ بادئةٍ تعوّض اقتطاعَ محرّكِ العرض (ltrim) لبادئة أول سطر. */ @endphp
@include('security.parts.header')

{{-- مفاتيحُ الطوارئ المفصولة: كلٌّ يشدّ فرملةً وحدَه --}}
@include('security.parts.freeze')

{{-- وضعية الأمان: فحوصٌ حيّة لكلٍّ منها علاجٌ وموضعه --}}
@include('security.parts.posture')

@include('security.parts.kpis')

{{-- خريطةُ الانكشاف: من يطاله اختراقُ حسابٍ واحد --}}
@include('security.parts.exposure')

{{-- رادارُ الكشف الحيّ: وصولٌ مرفوض (٤٠٣) وتخمينُ روابط --}}
@include('security.parts.radar')

{{-- السجلُّ الأمنيّ الموحَّد فوق التدقيق ورادار المنع --}}
@include('security.parts.events')

<div class="kids">
    @include('security.parts.sessions')

    @include('security.parts.failed')

    @include('security.parts.idle')

    @include('security.parts.secrets')

    @include('security.parts.roles')

    @include('security.parts.exports')
</div>
@endsection
