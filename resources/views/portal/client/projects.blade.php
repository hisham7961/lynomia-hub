@extends('layouts.portal')
@section('title', 'المشاريع')
@section('content')

<div class="hero"><div><h2>📁 مشاريعك</h2>
    <div class="sub">مشاريعُك لدينا — للاطّلاع (قراءةٌ فقط).</div></div></div>

@if ($projects->isEmpty())
    <div class="cportal-empty"><h3>لا بيانات بعد</h3>
        <p class="sub">لا مشاريعَ مُشارَكةً معك حتى الآن.</p></div>
@else
    <div class="card" style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse">
            <thead><tr>
                <th style="padding:8px;text-align:start">المشروع</th>
                <th style="padding:8px;text-align:start">الحالة</th>
                <th style="padding:8px;text-align:start">التقدّم</th>
                <th style="padding:8px;text-align:start">الإطلاق المتوقّع</th>
            </tr></thead>
            <tbody>
            @foreach ($projects as $p)
                <tr style="border-top:1px solid var(--bd,#e5e7eb)">
                    <td style="padding:8px"><a href="{{ route('portal.project', $p->id) }}"><b>{{ $p->name }}</b></a></td>
                    <td style="padding:8px"><span class="bdg">{{ $p->status ?: '—' }}</span></td>
                    <td style="padding:8px">{{ $p->progress !== null ? rtrim(rtrim(number_format((float) $p->progress, 0), '0'), '.') . '٪' : '—' }}</td>
                    <td style="padding:8px">{{ $p->launch_exp ? \Illuminate\Support\Str::of((string) $p->launch_exp)->substr(0, 10) : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

@endsection
