@extends('layouts.app')
@section('title', 'محادثة — ' . $other->name)
@section('content')
<div class="toolbar">
    <a class="btn ghost sm" href="{{ route('dm.inbox') }}">→ الرسائل</a>
    <span class="ava sm">{{ mb_substr($other->name, 0, 1) }}</span>
    <b>{{ $other->name }}</b>
    <span class="sub">{{ $other->job_title ?: '' }}</span>
</div>

<div class="card" style="display:flex;flex-direction:column;gap:8px;min-height:300px">
    @forelse ($msgs as $m)
        @php $mine = $m->from_id === auth()->id(); @endphp
        <div style="max-width:78%;{{ $mine ? 'align-self:flex-start' : 'align-self:flex-end' }}">
            <div style="padding:9px 13px;border-radius:14px;white-space:pre-wrap;word-break:break-word;
                        {{ $mine ? 'background:var(--p);color:#fff' : 'background:var(--pss)' }}">{{ $m->body }}</div>
            @if ($m->att)
                <a class="sub" href="{{ route('file.show', $m->att) }}" target="_blank" rel="noopener">📎 مرفق</a>
            @endif
            <div class="sub" style="font-size:11px;margin-top:2px;{{ $mine ? '' : 'text-align:end' }}">
                {{ $m->created_at?->format('m-d H:i') }}
                @if ($mine){{ $m->read_at ? ' · ✓✓ قُرئت' : ' · ✓' }}@endif
            </div>
        </div>
    @empty
        <div class="empty"><span class="big">✉️</span>ابدأ المحادثة — رسالتك تصل فوراً مع إشعار</div>
    @endforelse
    <span id="bottom"></span>
</div>

<form method="POST" action="{{ route('dm.send', $other->id) }}" enctype="multipart/form-data"
      class="card" style="display:flex;gap:8px;align-items:flex-end;margin-top:10px;position:sticky;bottom:10px">
    @csrf
    <label class="vh" for="dm-body">نص الرسالة</label>
    <textarea class="inp" id="dm-body" name="body" rows="2" required maxlength="4000"
              placeholder="اكتب رسالة…" style="flex:1"></textarea>
    <label class="btn ghost sm" style="cursor:pointer">📎<input type="file" name="att" class="vh"></label>
    <button class="btn p sm" type="submit">إرسال ↵</button>
</form>
@error('body')<div class="err">{{ $message }}</div>@enderror
@endsection
