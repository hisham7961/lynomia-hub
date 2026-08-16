@extends('layouts.app')
@section('title', 'عهد الصنف — ' . $cat['name'])
@section('content')
@php $look = hub_mod_look('assets'); @endphp
<header class="rechead" style="--mh:{{ $look['color'] }}">
    <nav class="crumbs" aria-label="مسار التنقل">
        <a href="{{ route('custody.catalog') }}">كتالوج العهد</a>
        <span aria-hidden="true">‹</span>
        <b>{{ $cat['name'] }}</b>
    </nav>
    <div class="rh-row">
        <span class="mhico" aria-hidden="true">{{ $cat['icon'] }}</span>
        <div class="rh-id">
            <h2>{{ $cat['name'] }}</h2>
            <div class="rh-meta">
                <span class="bdg mono">الكود الأساسي: {{ $code }}</span>
                <span class="sub">{{ number_format($rows->total()) }} عهدة في هذا الصنف</span>
            </div>
        </div>
        <div class="spacer"></div>
        <div class="rh-acts">
            @if (hub_can(auth()->user(), 'assets', 'a'))
                <a class="btn p sm" href="{{ route('m.create', ['assets', 'type' => $cat['name']]) }}">＋ عهدة في هذا الصنف</a>
            @endif
        </div>
    </div>
</header>

<div class="card">
    <form method="GET" class="fg" style="margin-bottom:4px">
        <div class="fld">
            <label for="cq">بحث</label>
            <input class="inp" id="cq" type="search" name="q" value="{{ $q }}" placeholder="الكود · الاسم · السيريال · الموقع">
        </div>
        <div class="fld">
            <label for="cst">الحالة</label>
            <select class="inp" id="cst" name="status">
                <option value="">كل الحالات</option>
                @foreach ($statuses as $s)
                    <option value="{{ $s }}" @selected($status === $s)>{{ $s }}</option>
                @endforeach
            </select>
        </div>
        <div class="fld">
            <label for="chd">الحائز</label>
            <select class="inp" id="chd" name="holder">
                <option value="">الجميع</option>
                <option value="-" @selected($holder === '-')>بلا حائز (في المخزن)</option>
                @foreach ($holders as $hid => $hname)
                    <option value="{{ $hid }}" @selected($holder === (string) $hid)>{{ $hname }}</option>
                @endforeach
            </select>
        </div>
        <div class="fld" style="justify-content:flex-end">
            <label>&nbsp;</label>
            <div style="display:flex;gap:8px">
                <button class="btn p">تصفية</button>
                <a class="btn ghost" href="{{ route('custody.category', $code) }}">مسح</a>
            </div>
        </div>
    </form>
</div>

<div class="card pad0">
    <table class="tbl">
        <thead>
            <tr>
                <th style="width:170px">كود العهدة</th>
                <th>الأصل</th>
                <th style="width:170px">الحائز</th>
                <th style="width:120px">الحالة</th>
                <th style="width:150px">السيريال</th>
                <th class="acts"></th>
            </tr>
        </thead>
        <tbody>
        @forelse ($rows as $a)
            <tr>
                <td><a href="{{ route('m.show', ['assets', $a->id]) }}"><b class="mono">{{ $a->code }}</b></a></td>
                <td>
                    <a href="{{ route('m.show', ['assets', $a->id]) }}">{{ $a->name }}</a>
                    @if ($a->loc)<div class="sub">📍 {{ $a->loc }}</div>@endif
                </td>
                <td>
                    @if ($a->holder_id)
                        {{ $holders[$a->holder_id] ?? 'حسابٌ محذوف' }}
                    @else
                        <span class="sub">— في المخزن</span>
                    @endif
                </td>
                <td>@if ($a->status)<span class="bdg {{ hub_tone($a->status) }}">{{ $a->status }}</span>@endif</td>
                <td class="mono sub" dir="ltr">{{ $a->serial ?: '—' }}</td>
                <td class="acts">
                    <a class="btn ghost xs" href="{{ route('custody.label', $a->id) }}" target="_blank" rel="noopener">🏷️ ملصق</a>
                    <a class="btn ghost xs" href="{{ route('custody.spec', $a->id) }}" target="_blank" rel="noopener">📄 مواصفات</a>
                </td>
            </tr>
        @empty
            @include('partials.empty', ['colspan' => 6, 'icon' => $cat['icon'],
                'text' => 'لا عهدة في هذا الصنف بهذه المعايير.'])
        @endforelse
        </tbody>
    </table>
</div>
{{ $rows->links('partials.pagination') }}
@endsection
