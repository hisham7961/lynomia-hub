@extends('errors.layout')
@section('code', '409')
@section('icon', '🔀')
@section('title', 'تعارض في النسخة')
@section('msg'){{ $exception?->getMessage() ?: 'السجل تغيّر بعد أن قرأته — حدّث الصفحة وراجع التعديل الأحدث ثم أعد المحاولة.' }}@endsection
