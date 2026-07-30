@extends('errors.layout')
@section('code', '403')
@section('icon', '🚫')
@section('title', 'غير مسموح')
@section('msg'){{ $exception?->getMessage() ?: 'لا تملك صلاحية الوصول لهذه الصفحة — تواصل مع مالك النظام إن كنت تحتاجها.' }}@endsection
