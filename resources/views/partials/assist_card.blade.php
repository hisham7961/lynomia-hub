{{-- المساعدُ التنفيذيّ — «مسودةٌ ثمّ تأكيد» (App\Support\Ai\Assist\DraftAssistant). لا يظهر ما لم يكن
     للمستخدم رايةُ المساعد والبوّابةُ جاهزة ونوعٌ يملك هدفَه؛ والنقرُ يعرض مسودةً ولا يكتب شيئاً. --}}
@php $assistKinds = \App\Support\Ai\Assist\DraftAssistant::kindsFor(auth()->user(), $aModule); @endphp
@if ($assistKinds)
    <div class="card" data-assist-card>
        <h3 class="cardtitle">✨ المساعد</h3>
        <div class="sub">يقترح مسودةً من هذا السجلّ بما تراه أنت — <b>ولا يحفظ شيئاً</b>: تراجعها وتحفظها بنفسك.</div>
        @foreach ($assistKinds as $k => $d)
            <form method="POST" action="{{ route('assist.draft') }}" class="inline">@csrf
                <input type="hidden" name="kind" value="{{ $k }}">
                <input type="hidden" name="module" value="{{ $aModule }}">
                <input type="hidden" name="id" value="{{ $aRecordId }}">
                <button class="btn sm">{{ $d['icon'] }} {{ $d['label'] }}</button>
            </form>
        @endforeach
    </div>
@endif
