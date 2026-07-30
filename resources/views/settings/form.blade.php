@extends('layouts.app')
@section('title', 'إعدادات النظام')
@section('content')
<div class="card" style="max-width:560px">
    <form method="POST" action="{{ route('settings.update') }}">
        @csrf
        <div class="fg">
            @foreach ($keys as $k => $label)
                <div class="fld fw"><label>{{ $label }}</label>
                    <input class="inp" name="{{ str_replace('.', '_', $k) }}" value="{{ old(str_replace('.', '_', $k), $values[$k]) }}"></div>
            @endforeach
        </div>
        <div class="formfoot"><button class="btn p">حفظ</button></div>
    </form>
    <div class="sub" style="margin-top:12px">لأي إعداد آخر من الطرفية: <span class="mono ltr">php artisan hub:set key value</span></div>
</div>
@endsection
