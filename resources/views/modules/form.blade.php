@extends('layouts.app')
@section('title', ($row && empty($dup) ? 'تعديل' : 'إضافة') . ' — ' . $def['label'])
@section('content')
<div class="card">
    @include('modules._form', ['hx' => false])
</div>
@endsection
