@extends('errors.layout')
@section('code', '401')
@section('icon', '🔑')
@section('title', 'غير مصرّح')
@section('msg'){{ $exception?->getMessage() ?: 'هذا الطلب يحتاج مصادقةً صحيحة — تحقّق من المفتاح أو التوقيع.' }}@endsection
