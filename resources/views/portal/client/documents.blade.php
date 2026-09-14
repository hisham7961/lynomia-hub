@extends('layouts.portal')
@section('title', 'الوثائق')
@section('content')

<div class="hero"><div><h2>📄 وثائقُك المشترَكة</h2>
    <div class="sub">الوثائقُ التي شُورِكت معك — لا وثيقةً داخليّةً تظهر هنا.</div></div></div>

@if ($documents->isEmpty())
    <div class="cportal-empty"><h3>لا بيانات بعد</h3>
        <p class="sub">لا وثائقَ مُشارَكةً معك حتى الآن.</p></div>
@else
    <div class="card" style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse">
            <thead><tr>
                <th style="padding:8px;text-align:start">الوثيقة</th>
                <th style="padding:8px;text-align:start">التصنيف</th>
                <th style="padding:8px;text-align:start">الرقم</th>
                <th style="padding:8px;text-align:start">تاريخ الإصدار</th>
                <th style="padding:8px;text-align:start">الملف</th>
            </tr></thead>
            <tbody>
            @foreach ($documents as $d)
                <tr style="border-top:1px solid var(--bd,#e5e7eb)">
                    <td style="padding:8px"><a href="{{ route('portal.document', $d->id) }}"><b>{{ $d->name }}</b></a></td>
                    <td style="padding:8px">{{ $d->cat ?: '—' }}</td>
                    <td style="padding:8px">{{ $d->doc_no ?: '—' }}</td>
                    <td style="padding:8px">{{ $d->issue_date ? \Illuminate\Support\Str::of((string) $d->issue_date)->substr(0, 10) : '—' }}</td>
                    {{-- (الجولة 1 · F25) زرُّ تنزيلٍ صادق: يُرسم فقط لوثيقةٍ لها ملفٌّ فعلاً --}}
                    <td style="padding:8px">
                        @if (in_array((string) $d->id, $downloadable ?? [], true))
                            <a class="btn ghost xs" href="{{ route('portal.document.download', $d->id) }}">⬇ تنزيل</a>
                        @else
                            <span class="sub">—</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

@endsection
