@extends('layouts.app')
@section('title', 'بوابتي — الصندوق الموحد')
@section('content')
<div class="hero">
    <div>
        <h2>👤 {{ auth()->user()->name }}</h2>
        <div class="sub">
            @if ($emp){{ $emp->title ?? '' }}{{ $emp->dept ? ' · ' . $emp->dept : '' }}{{ $emp->hired ? ' · معيّن منذ ' . substr($emp->hired, 0, 10) : '' }}
            @else لا ملف وظيفي مرتبط بحسابك بعد — يضيفه قسم الموارد البشرية @endif
        </div>
    </div>
    <div style="display:flex;gap:8px">
        <a class="btn ghost sm" href="{{ route('notifications.index') }}">🔔 إشعاراتي</a>
        <a class="btn ghost sm" href="{{ route('profile.edit') }}">⚙️ حسابي</a>
    </div>
</div>

@include('portal._hr')
@endsection
