@extends('layouts.app')
@section('title', 'تعديل مسار — ' . $flow->name)
@section('content')
<div class="hero">
    <div>
        <h2>✏️ تعديل المسار</h2>
        <div class="sub">
            {{ $def['label'] }} · شُغّل <b>{{ $flow->runs }}</b> مرة
            @if ($flow->last_run_at)· آخر تشغيل {{ $flow->last_run_at->diffForHumans() }}@endif
            — التعديل يحفظ العدّاد والتاريخ.
        </div>
    </div>
    <a class="btn ghost sm" href="{{ route('flows.index', ['m' => $module]) }}">← كل المسارات</a>
</div>

<div class="card" style="max-width:760px">
    @include('flows._form')
</div>

<div class="card" style="max-width:760px">
    <h3 class="cardtitle">🧪 قبل أن تعتمد التعديل</h3>
    <div class="sub" style="margin-bottom:8px">
        جرّب المسار على سجل حقيقي وشاهد ما سيحدث — بلا تنفيذ ولا إشعار ولا مهمة.
    </div>
    <a class="btn ghost sm" href="{{ route('flows.sandbox', $flow->id) }}">🧪 افتح التجربة الجافة</a>
</div>
@endsection
