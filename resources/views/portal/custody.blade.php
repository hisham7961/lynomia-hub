@extends('layouts.app')
@section('title', 'عهدتي')
@section('content')

<div class="hero">
    <div>
        <h2>🧰 عهدتي</h2>
        <div class="sub">كلُّ ما هو مسجَّلٌ بحوزتك الآن، وتاريخُ حركات عهدتك — تسلُّماً واسترداداً.</div>
    </div>
    <a class="btn ghost sm" href="{{ route('portal.me') }}">← بوابتي</a>
</div>

<div class="card">
    <h3>بحوزتي الآن <span class="bdg {{ $assets->count() ? 'ok' : 'g' }}">{{ $assets->count() }}</span></h3>
    <table class="tbl">
        <tr><th>الأصل</th><th>النوع</th><th>الكود / الوسم</th><th>الرقم التسلسلي</th><th>الموقع</th><th>الحالة</th></tr>
        @forelse ($assets as $a)
            <tr>
                <td><b>{{ $a->name }}</b></td>
                <td class="sub">{{ $a->type ?: '—' }}</td>
                <td class="sub">{{ $a->code ?: ($a->tag ?: '—') }}</td>
                <td class="sub">{{ $a->serial ?: '—' }}</td>
                <td class="sub">{{ $a->station_id ? ($stationNames[$a->station_id] ?? '—') : '—' }}</td>
                <td>@if ($a->status)<span class="bdg {{ hub_tone($a->status) }}">{{ $a->status }}</span>@endif</td>
            </tr>
        @empty
            <tr><td colspan="6" class="sub" style="padding:18px;text-align:center">
                لا عهدة مسجَّلة باسمك الآن — إن سلّمك أحدٌ جهازاً فاطلب تسجيلَه ليكون العهدُ موثَّقاً.
            </td></tr>
        @endforelse
    </table>
</div>

@if ($moves->count())
<div class="card" style="margin-top:12px">
    <h3>سجلُّ حركات عهدتي</h3>
    <table class="mini">
        @foreach ($moves as $mv)
            <tr>
                <td>{{ $mv->action }} — <b>{{ $moveNames[$mv->asset_id] ?? 'أصل' }}</b>
                    @if ($mv->note)<div class="sub">{{ $mv->note }}</div>@endif</td>
                <td class="sub" style="white-space:nowrap">{{ \Illuminate\Support\Str::of((string) $mv->at)->limit(10, '') }}</td>
            </tr>
        @endforeach
    </table>
</div>
@endif

@endsection
