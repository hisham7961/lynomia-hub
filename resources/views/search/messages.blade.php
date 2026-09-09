@extends('layouts.app')
@section('title', 'بحثُ الرسائل')
@section('content')

<div class="hero">
    <div>
        <h2>🔎 بحثُ الرسائل</h2>
        <div class="sub">ابحث في نصّ الخلاصة والقنوات والمحادثات — ما تراه أنت فقط</div>
    </div>
</div>

<form method="GET" action="{{ route('search.messages') }}" class="card" style="display:flex;gap:8px">
    <label class="vh" for="msg-q">كلمةُ البحث</label>
    <input class="inp" id="msg-q" name="q" value="{{ $q }}" placeholder="اكتب كلمةً من الرسالة…" autofocus style="flex:1">
    <button class="btn" type="submit">بحث</button>
</form>

@if ($q !== '' && mb_strlen($q) < 2)
    <div class="note wn">اكتب حرفين على الأقل.</div>
@elseif ($q !== '')
    <div class="sub" style="margin:10px 2px">{{ count($results) }} نتيجة لِـ«{{ $q }}»</div>
    @forelse ($results as $row)
        <a class="card" href="{{ $row['link'] }}" style="display:flex;gap:12px;align-items:flex-start;margin-bottom:8px;text-decoration:none;color:inherit">
            <span class="ava">{{ $row['icon'] }}</span>
            <div style="flex:1;min-width:0">
                <div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline">
                    <b>{{ $row['author'] ?? 'غير معروف' }}</b>
                    <span class="sub">{{ $row['kind'] }} · {{ optional($row['when'])->diffForHumans() }}</span>
                </div>
                <div style="margin-top:3px">{{ $row['excerpt'] }}</div>
            </div>
        </a>
    @empty
        <div class="card" style="text-align:center;color:var(--muted,#888);padding:28px">
            لا رسائل تطابق «{{ $q }}» فيما تراه.
        </div>
    @endforelse
@endif

@endsection
