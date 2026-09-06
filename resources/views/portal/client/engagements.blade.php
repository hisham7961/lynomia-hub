@extends('layouts.portal')
@section('title', 'الارتباطات')
@section('content')

<div class="hero"><div><h2>🤝 ارتباطاتك</h2>
    <div class="sub">العلاقاتُ المنظَّمة بيننا — خدماتُها وحالتُها وموعدُ تجديدها.</div></div></div>

@if ($engagements->isEmpty())
    <div class="cportal-empty"><h3>لا بيانات بعد</h3>
        <p class="sub">لا ارتباطاتٍ مُشارَكةً معك حتى الآن.</p></div>
@else
    <div class="card" style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse">
            <thead><tr style="text-align:start">
                <th style="padding:8px;text-align:start">الارتباط</th>
                <th style="padding:8px;text-align:start">النوع</th>
                <th style="padding:8px;text-align:start">الحالة</th>
                <th style="padding:8px;text-align:start">التجديد</th>
            </tr></thead>
            <tbody>
            @foreach ($engagements as $e)
                <tr style="border-top:1px solid var(--bd,#e5e7eb)">
                    <td style="padding:8px"><b>{{ $e->name }}</b>
                        @if ($e->client_note)<div class="sub">{{ \Illuminate\Support\Str::limit($e->client_note, 90) }}</div>@endif
                    </td>
                    <td style="padding:8px">{{ $e->type ?: '—' }}</td>
                    <td style="padding:8px"><span class="bdg">{{ $e->status ?: '—' }}</span></td>
                    <td style="padding:8px">{{ $e->renewal ? \Illuminate\Support\Str::of((string) $e->renewal)->substr(0, 10) : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

@endsection
