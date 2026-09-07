@extends('layouts.app')
@section('title', 'جلساتُ الجرد')
@section('content')
<div class="hero">
    <div>
        <h2>📋 جلساتُ الجرد</h2>
        <div class="sub">
            جلسةُ جردٍ = لقطةٌ مجمَّدةٌ لأصولِ نطاقك تُقارَن بمسحٍ مُصادَق. لا تحرّكُ اللقطةُ
            بتغيّرِ الأصل بعدها؛ وكلُّ مسحٍ مختومٌ بمن مسح ومتى؛ والإغلاقُ خلفَ تصعيدِ المصادقة.
        </div>
    </div>
    @if (hub_can(auth()->user(), 'assets', 'e'))
    <form method="POST" action="{{ route('inventory.freeze') }}" style="margin-inline-start:auto">
        @csrf
        <button class="btn" type="submit">❄️ تجميدُ لقطةٍ جديدة</button>
    </form>
    @endif
</div>

@if (session('ok'))<div class="flash ok">{{ session('ok') }}</div>@endif
@if (session('err'))<div class="flash bad">{{ session('err') }}</div>@endif

<div class="card">
    <h3 class="cardtitle">🗂️ الجلسات</h3>
    @if ($sessions->isEmpty())
        <div class="sub">لا جلساتِ جردٍ بعد — ابدأ بتجميدِ لقطة.</div>
    @else
        <table class="mini">
            <thead><tr>
                <th>الجلسة</th><th>الحالة</th><th>أصنافٌ مجمَّدة</th><th>مسحات</th><th>فُتِحت</th><th></th>
            </tr></thead>
            <tbody>
            @foreach ($sessions as $s)
                <tr>
                    <td dir="ltr">{{ \Illuminate\Support\Str::limit($s->id, 8, '') }}</td>
                    <td>{{ $s->status }}</td>
                    <td>{{ $s->items_count }}</td>
                    <td>{{ $s->scans_count }}</td>
                    <td>{{ optional($s->created_at)->format('Y-m-d H:i') }}</td>
                    <td class="acts">
                        <a class="btn ghost xs" href="{{ route('inventory.show', $s->id) }}">التفصيل ←</a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
