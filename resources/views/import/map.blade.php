@extends('layouts.app')
@section('title', 'مطابقة الأعمدة — ' . $def['label'])
@section('content')
<form method="POST" action="{{ route('m.import.run', $module) }}">
    @csrf
    <div class="card">
        <h3 style="margin-bottom:6px">🔗 مطابقة أعمدة الملف بحقول {{ $def['label'] }} <span class="bdg g">{{ number_format($total) }} صف</span></h3>
        <p class="sub">طوبقت الأعمدة المتشابهة تلقائياً — راجعها وعدّل ما يلزم. عمود بلا حقل = يُتجاهل.</p>
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>عمود الملف</th><th>عينة من بياناته</th><th>يُستورد إلى حقل</th></tr></thead>
            <tbody>
                @foreach ($headers as $i => $h)
                    <tr>
                        <td><b>{{ $h !== '' ? $h : 'عمود ' . ($i + 1) }}</b></td>
                        <td class="sub">{{ \Illuminate\Support\Str::limit(collect($sample)->pluck($i)->filter()->implode(' · '), 50) ?: '—' }}</td>
                        <td>
                            <select class="inp" name="map[{{ $i }}]">
                                <option value="">— تجاهل —</option>
                                @foreach ($def['fields'] as $f)
                                    @continue(in_array($f['type'], ['file', 'img', 'sec'], true))
                                    <option value="{{ $f['key'] }}" @selected(($auto[$i] ?? '') === $f['key'])>{{ $f['label'] }}{{ ! empty($f['required']) ? ' *' : '' }}{{ $f['type'] === 'ref' ? ' (بالاسم)' : '' }}</option>
                                @endforeach
                            </select>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
        <div class="formfoot">
            <button class="btn p" type="submit">🚀 استيراد {{ number_format($total) }} صف الآن</button>
            <a class="btn ghost" href="{{ route('m.import', $module) }}">→ ملف آخر</a>
        </div>
    </div>
</form>
@endsection
