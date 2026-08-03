@extends('layouts.app')
@section('title', 'استيراد — ' . $def['label'])
@section('content')
@include('partials.pagehead', ['icon' => '📥', 'title' => 'استيراد إلى ' . $def['label'],
    'crumb' => $def['label'], 'crumbUrl' => route('m.index', $module),
    'back' => route('m.index', $module), 'backLabel' => $def['label']])
<div class="card" style="max-width:560px">
    <p class="sub">ارفع ملف CSV أو Excel (xlsx) — أول سطر عناوين الأعمدة، وستطابقها بحقول الوحدة في الخطوة التالية.
        الحقول المرجعية (كالشركة) اكتب فيها <b>الاسم كما هو في النظام</b> وسيُحل تلقائياً.</p>
    <form method="POST" action="{{ route('m.import.map', $module) }}" enctype="multipart/form-data" style="margin-top:12px">
        @csrf
        <input class="inp" type="file" name="file" accept=".csv,.txt,.xlsx" required>
        @error('file')<div class="ferr">{{ $message }}</div>@enderror
        <div class="formfoot">
            <button class="btn p" type="submit">التالي: مطابقة الأعمدة ←</button>
            <a class="btn ghost" href="{{ route('m.index', $module) }}">إلغاء</a>
        </div>
    </form>
</div>
@endsection
