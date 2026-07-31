@extends('layouts.app')
@section('title', 'نتائج البحث')
@section('content')
<div class="toolbar">
    <form class="filters" method="GET" action="{{ route('search') }}">
        <input class="inp" type="search" name="q" value="{{ $q }}" placeholder="بحث شامل في كل الوحدات…" autofocus>
        <button class="btn sm" type="submit">بحث</button>
    </form>
</div>

@php $dests = $dests ?? []; @endphp
@if (count($dests))
    {{-- وجهات النظام المطابقة: البحث يوصلك لأي صفحة أو قسم بالاسم --}}
    <div class="card">
        <h3>🧭 صفحات وأقسام</h3>
        <div style="display:flex;flex-wrap:wrap;gap:8px">
            @foreach ($dests as $d)<a class="btn ghost sm" href="{{ $d['u'] }}">{{ $d['t'] }}</a>@endforeach
        </div>
    </div>
@endif
@if (mb_strlen($q) < 2)
    <div class="card"><div class="sub" style="padding:20px;text-align:center">اكتب حرفين على الأقل للبحث في كل الوحدات</div></div>
@elseif (! count($groups))
    @unless (count($dests))
        <div class="card"><div class="sub" style="padding:20px;text-align:center">لا نتائج لـ«{{ $q }}» في أي وحدة</div></div>
    @endunless
@else
    <div class="sub" style="margin-bottom:12px">{{ count($groups) }} وحدة فيها نتائج لـ«{{ $q }}»</div>
    <div class="kids">
        @foreach ($groups as $g)
            <div class="card kid">
                <h3>{{ $g['label'] }} <span class="bdg g">{{ number_format($g['count']) }}</span>
                    <a class="btn ghost xs" style="margin-inline-start:auto" href="{{ route('m.index', [$g['module'], 'q' => $q]) }}">داخل الوحدة ←</a></h3>
                <table class="mini">
                    @foreach ($g['rows'] as $row)
                        <tr>
                            <td><a href="{{ route('m.show', [$g['module'], $row->id]) }}">{{ \Illuminate\Support\Str::limit((string) $row->{$g['display']}, 40) }}</a></td>
                            @if ($g['status'] && $row->{$g['status']})
                                <td class="acts"><span class="bdg {{ hub_tone($row->{$g['status']}) }}">{{ $row->{$g['status']} }}</span></td>
                            @endif
                        </tr>
                    @endforeach
                </table>
            </div>
        @endforeach
    </div>
@endif
@endsection
