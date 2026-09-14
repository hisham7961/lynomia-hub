{{--
    «تذاكري» — قناةُ بلاغِ العميل (الجولة 2 · G7). كانت البوّابةُ ستَّ وجهاتٍ بلا بابِ
    دعمٍ واحد: يتوقّف نظامُ العميلِ فيبلّغ هاتفيّاً، فلا يبقى للبلاغ أثرٌ يتتبّعه هو.
    الوجهةُ تعرض بلاغاتِه بحالاتها، وزرُّ البلاغِ الجديدِ حاضرٌ في الحالتين (بلاغٌ
    أوّلُ أو عاشر) — وعدٌ يفعل لا زينة.
--}}
@extends('layouts.portal')
@section('title', 'تذاكري')
@section('content')

<div class="hero">
    <div><h2>🎫 تذاكري</h2>
        <div class="sub">بلاغاتُك وحالةُ كلٍّ منها — وردودُ الفريق عليها.</div></div>
    <a class="btn p" href="{{ route('portal.ticket.create') }}">＋ أبلغ عن مشكلة</a>
</div>

@if ($tickets->isEmpty())
    <div class="cportal-empty"><h3>لا تذاكر بعد</h3>
        <p class="sub">إن واجهت عطلاً أو لديك طلب، افتح بلاغاً ويصلك ردُّ الفريق هنا.</p>
        <p style="margin-top:10px"><a class="btn p" href="{{ route('portal.ticket.create') }}">＋ أبلغ عن مشكلة</a></p></div>
@else
    <div class="card" style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse">
            <thead><tr>
                <th style="padding:8px;text-align:start">الموضوع</th>
                <th style="padding:8px;text-align:start">المشروع</th>
                <th style="padding:8px;text-align:start">الأولوية</th>
                <th style="padding:8px;text-align:start">الحالة</th>
                <th style="padding:8px;text-align:start">تاريخ البلاغ</th>
            </tr></thead>
            <tbody>
            @foreach ($tickets as $t)
                <tr style="border-top:1px solid var(--bd,#e5e7eb)">
                    <td style="padding:8px"><a href="{{ route('portal.ticket', $t->id) }}"><b>{{ $t->subject }}</b></a></td>
                    <td style="padding:8px">{{ $projects->firstWhere('id', $t->project_id)?->name ?: '—' }}</td>
                    <td style="padding:8px">{{ $t->priority ?: '—' }}</td>
                    <td style="padding:8px"><span class="bdg {{ hub_tone((string) $t->status) }}">{{ $t->status ?: '—' }}</span></td>
                    <td style="padding:8px">{{ $t->created_at ? \Illuminate\Support\Str::of((string) $t->created_at)->substr(0, 16) : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

@endsection
