@extends('layouts.app')
@section('title', 'تجربة مسار — ' . $flow->name)
@section('content')
<div class="toolbar">
    <a class="btn ghost sm" href="{{ route('flows.index', ['m' => $flow->module]) }}">→ المسارات</a>
</div>
<div class="hero">
    <div>
        <h2>🧪 تجربة المسار: {{ $flow->name }}</h2>
        <div class="sub">
            جرّبه على سجل حقيقي وشاهد ما <b>سيحدث</b> قبل أن تثق به —
            <b>لا إشعار يُرسل ولا مهمة تُنشأ ولا حقل يُكتب</b>. تجربةٌ جافة بالكامل.
        </div>
    </div>
    <span class="bdg {{ $flow->enabled ? 'ok' : 'g' }}">{{ $flow->enabled ? 'مفعّل' : 'معطّل' }}</span>
</div>

<div class="card">
    <form method="GET" action="{{ route('flows.sandbox', $flow->id) }}" class="filters">
        <label class="lbl" for="rid">اختر سجلاً من «{{ $def['label'] }}» (أحدث ٢٠)</label>
        <select class="inp" id="rid" name="rid" onchange="this.form.submit()" style="min-width:280px">
            <option value="">— اختر سجلاً للتجربة —</option>
            @foreach ($recent as $row)
                <option value="{{ $row->id }}" @selected($record && $record->id === $row->id)>
                    {{ \Illuminate\Support\Str::limit($row->{$display} ?? $row->id, 50) }}
                </option>
            @endforeach
        </select>
        <noscript><button class="btn sm">تجربة</button></noscript>
    </form>
    @if ($recent->isEmpty())
        <div class="sub" style="margin-top:8px">لا سجلات في هذه الوحدة بعد — أضف سجلاً ثم جرّب المسار عليه.</div>
    @endif
</div>

@if ($result)
    <div class="card" style="margin-top:12px">
        <h3>النتيجة على «{{ $result['record'] }}»</h3>

        {{-- هل سيعمل المسار؟ --}}
        <div class="flash {{ $result['ok'] ? 'ok' : 'wn' }}" style="margin:8px 0">
            @if ($result['ok'])
                ✅ <b>نعم، سيعمل هذا المسار على هذا السجل</b> — الإجراءات أدناه ستُنفَّذ فعلياً عند الحدث الحقيقي.
            @else
                ⏸️ <b>لن يعمل على هذا السجل</b> — لم تتحقق كل الشروط. راجع الأسباب أدناه.
            @endif
        </div>

        {{-- تفصيل المطابقة --}}
        <div class="tblwrap"><table class="tbl">
            <tbody>
                @if ($result['statusWhy'])
                    <tr>
                        <td class="acts">{{ $result['statusMatch'] ? '✅' : '❌' }}</td>
                        <td>مطابقة الحالة</td>
                        <td class="sub">{{ $result['statusWhy'] }}</td>
                    </tr>
                @endif
                @if ($result['condWhy'])
                    <tr>
                        <td>{{ $result['condPass'] ? '✅' : '❌' }}</td>
                        <td>الشرط</td>
                        <td class="sub">{{ $result['condWhy'] }}</td>
                    </tr>
                @else
                    <tr><td>✅</td><td>الشرط</td><td class="sub">بلا شرط — يعمل على كل سجل يطابق الحدث</td></tr>
                @endif
            </tbody>
        </table></div>
    </div>

    <div class="card" style="margin-top:12px">
        <h3>{{ $result['ok'] ? '⚡ ما سيحدث' : '⚡ ما كان سيحدث لو تحقق الشرط' }}</h3>
        <div class="sub" style="margin-bottom:8px">القوالب محلولة بقيم هذا السجل الفعلية:</div>
        @forelse ($result['actions'] as $a)
            <div style="display:flex;gap:10px;padding:9px 0;border-bottom:1px solid var(--brd)">
                <span aria-hidden="true">{{ $a['icon'] }}</span>
                <div style="min-width:0;flex:1">
                    <b>{{ $a['label'] }}</b>
                    <div class="sub" style="white-space:pre-wrap;word-break:break-word">{{ $a['detail'] }}</div>
                </div>
            </div>
        @empty
            <div class="sub">لا إجراءات مُعرّفة على هذا المسار.</div>
        @endforelse
        @unless ($result['ok'])
            <div class="sub" style="margin-top:10px">⚠️ لن تُنفَّذ هذه الإجراءات على السجل الحالي لأن الشرط لم يتحقق — جرّب سجلاً آخر.</div>
        @endunless
    </div>
@endif
@endsection
