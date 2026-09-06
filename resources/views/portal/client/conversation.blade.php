@extends('layouts.portal')
@section('title', 'محادثة')
@section('content')

<div class="hero">
    <div><h2>💬 {{ $conv->title ?: 'محادثة' }}</h2>
        <div class="sub">{{ $conv->kind === 'dm' ? 'رسالة مباشرة' : 'قناة' }}</div></div>
    <a class="btn ghost sm" href="{{ route('portal.conversations') }}">← كل المحادثات</a>
</div>

<div class="card">
    @forelse ($messages as $m)
        <div style="padding:9px 0;border-top:1px solid var(--bd,#e5e7eb)">
            <div class="sub"><b>{{ $m->user?->name ?? 'مستخدم' }}</b>
                · {{ $m->created_at ? \Illuminate\Support\Str::of((string) $m->created_at)->substr(0, 16) : '' }}</div>
            <div>{{ $m->body }}</div>
        </div>
    @empty
        <p class="sub">لا رسائلَ بعد.</p>
    @endforelse
</div>

@endsection
