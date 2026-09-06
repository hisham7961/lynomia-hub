@extends('layouts.app')
@section('title', 'مركز التشغيل')
@section('content')
@php $fmt = fn ($b) => \App\Support\SysMonitor::bytes($b === null ? null : (int) $b); @endphp
@php /* (WP-2.1) قُسّمت الشاشةُ أقساماً — كلُّ قسمٍ ملفُّه في ops/parts فتحرّر كلُّ حزمةِ عملٍ لاحقةٍ قسمَها وحده بلا تزاحم. البطاقاتُ داخل .kids تُضمَّن بمسافةٍ بادئةٍ تعوّض اقتطاعَ محرّكِ العرض (ltrim) لبادئة أول سطر. */ @endphp
@include('ops.parts.header')

{{-- نموذجُ الصحّة الواحد: الحالةُ لكل مكوّنٍ حرج — هي نفسُها التي يقرؤها /healthz --}}
@include('ops.parts.health')

{{-- المؤشرات الحيّة: نِسبٌ لها معنى — «الحمل ٢٫٤» بلا عدد أنوية لا يقول شيئاً --}}
@include('ops.parts.system')

{{-- نبض ٢٤ ساعة: الشكل الزمني يفرّق بين حملٍ معتاد وقفزةٍ مفاجئة --}}
@include('ops.parts.pulse')

{{-- (WP-2.4) أداء المسارات والانحدار: مجمَّعةً في القاعدة، وhist لصفوف الصفحة وحدها --}}
@include('ops.parts.routes')

{{-- (WP-2.5) أهداف مستوى الخدمة وميزانية الخطأ — مطفأة كلياً ما لم تُضبط مفاتيح slo.* --}}
@include('ops.parts.slo')

{{-- من يستهلك: الرقم بلا فاعلٍ لا يُعالَج --}}
@include('ops.parts.consumers')

{{-- (WP-2.6) الاعتماديات من الإعداد الفعليّ + تاريخ التوافر مجمَّعاً في القاعدة --}}
@include('ops.parts.dependencies')

{{-- (WP-2.7) ارتباطُ الإصدار: النشراتُ مقابل أخطاء ما قبلها وما بعدها بحدٍّ أدنى للعيّنة --}}
@include('ops.parts.releases')

<h3 class="secttl">🛠️ التشغيل والصيانة</h3>

<div class="kids">
    @include('ops.parts.outbox')

    @include('ops.parts.scheduler')

    @include('ops.parts.backups')

    @include('ops.parts.errors')

    @include('ops.parts.db')

    @include('ops.parts.cache')

    @include('ops.parts.starters')

    {{-- فاحصان كانا يُرشَد إليهما بسطرِ طرفيةٍ لا يملكها صاحبُ استضافةٍ مشتركة،
         فلا يُشغَّلان أبداً: سلسلةٌ مختومةٌ لا يفحصها شيء، وانحرافُ مخطّطٍ لا يُكشف --}}
    @include('ops.parts.integrity')

    @include('ops.parts.env')

    @include('ops.parts.logtail')

    @include('ops.parts.demo')
</div>
@endsection
