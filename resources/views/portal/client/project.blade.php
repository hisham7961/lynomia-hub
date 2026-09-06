@extends('layouts.portal')
@section('title', 'مشروع')
@section('content')

<div class="hero">
    <div>
        <h2>📁 {{ $project->name }}</h2>
        <div class="sub">
            @if ($clientName){{ $clientName }}@endif
            @if ($engagementName) · {{ $engagementName }}@endif
        </div>
    </div>
    <a class="btn ghost sm" href="{{ route('portal.projects') }}">← كل المشاريع</a>
</div>

<div class="card">
    <div class="grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
        <div><div class="sub">الحالة</div><b>{{ $project->status ?: '—' }}</b></div>
        <div><div class="sub">الأولوية</div><b>{{ $project->priority ?: '—' }}</b></div>
        <div><div class="sub">التقدّم</div><b>{{ $project->progress !== null ? rtrim(rtrim(number_format((float) $project->progress, 0), '0'), '.') . '٪' : '—' }}</b></div>
        <div><div class="sub">البداية</div><b>{{ $project->start_date ? \Illuminate\Support\Str::of((string) $project->start_date)->substr(0, 10) : '—' }}</b></div>
        <div><div class="sub">الإطلاق المتوقّع</div><b>{{ $project->launch_exp ? \Illuminate\Support\Str::of((string) $project->launch_exp)->substr(0, 10) : '—' }}</b></div>
        <div><div class="sub">الإطلاق الفعليّ</div><b>{{ $project->launch_act ? \Illuminate\Support\Str::of((string) $project->launch_act)->substr(0, 10) : '—' }}</b></div>
    </div>
    @if ($project->description)
        <div style="margin-top:14px"><div class="sub">الوصف</div><p>{{ $project->description }}</p></div>
    @endif
</div>

@endsection
