@extends('layouts.app')
@section('title', 'الملف الشامل — ' . $emp->name)
@section('content')
<div class="hero">
    <div>
        <h2>🗂️ {{ $emp->name }}</h2>
        <div class="sub">
            {{ $emp->title ?? '' }}{{ $emp->dept ? ' · ' . $emp->dept : '' }}{{ $emp->hired ? ' · معيّن منذ ' . substr($emp->hired, 0, 10) : '' }}
            @if ($emp->status) · <span class="bdg {{ hub_tone($emp->status) }}">{{ $emp->status }}</span>@endif
        </div>
    </div>
    <div style="display:flex;gap:8px">
        @if (hub_can(auth()->user(), 'hr', 'e'))
            <a class="btn ghost sm" href="{{ route('m.edit', ['hr', $emp->id]) }}">✏️ تعديل الملف</a>
        @endif
        <a class="btn ghost sm" href="{{ route('m.show', ['hr', $emp->id]) }}">📄 كل الحقول</a>
    </div>
</div>

{{-- حسابُ النظام: البابُ في المدخلين معاً — «كل الحقول» و«الملف الشامل» --}}
@include('partials.staff_account_card', ['acctRow' => $emp])

@include('portal._hr')
@endsection
