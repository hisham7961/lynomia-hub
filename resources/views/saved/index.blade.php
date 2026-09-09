@extends('layouts.app')
@section('title', 'المحفوظات')
@section('content')

<div class="hero">
    <div>
        <h2>🔖 المحفوظات</h2>
        <div class="sub">رسائلٌ حفظتَها لاحقاً — مرجعٌ لا نسخة، تُفتح بصلاحيتك لحظتها</div>
    </div>
    <div class="filters"><span class="sub">📌 <b>{{ count($rows) }}</b> عنصراً</span></div>
</div>

@if (empty($rows))
    <div class="card" style="text-align:center;color:var(--muted,#888);padding:32px">
        لا محفوظات بعد — احفظ رسالةً من قناةٍ أو محادثةٍ لتعود إليها هنا.
    </div>
@else
    @foreach ($rows as $row)
        <div class="card" style="display:flex;gap:12px;align-items:flex-start;margin-bottom:10px">
            <span class="ava">{{ $row['type'] === 'dm' ? '💬' : '#' }}</span>
            <div style="flex:1;min-width:0">
                <div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline">
                    <b>{{ $row['author'] ?? 'غير معروف' }}</b>
                    <span class="sub">{{ optional($row['when'])->diffForHumans() }}</span>
                </div>
                @if ($row['available'])
                    <div style="margin:4px 0">{{ $row['title'] }}</div>
                    @if ($row['link'])
                        <a class="btn sm" href="{{ $row['link'] }}">فتحُ الرسالة ↩</a>
                    @endif
                @else
                    <div class="sub" style="margin:4px 0">🔒 لم تعد متاحةً لك — أُزيلت أو زالت صلاحيتُك عليها</div>
                @endif
                @if ($row['note'])
                    <div class="sub" style="margin-top:4px">📝 {{ $row['note'] }}</div>
                @endif
            </div>
            <form method="POST" action="{{ route('saved.destroy', $row['saved']->id) }}">
                @csrf
                @method('DELETE')
                <button class="btn sm danger" type="submit" title="إزالة من المحفوظات">إزالة</button>
            </form>
        </div>
    @endforeach
@endif

@endsection
