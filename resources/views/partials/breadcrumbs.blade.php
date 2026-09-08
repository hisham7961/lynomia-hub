{{-- فتاتُ المعمارية (IA · الطور 5) — مسارٌ دلاليٌّ واحدٌ مشترك: مجال ← قسم ← وجهة ←
     سجلّ، من مُحلِّلٍ واحد `InformationArchitecture::breadcrumbs()` لا من كلِّ Blade.
     عرضٌ صرفٌ بصنفِ `.crumbs` القائم (لا CSS جديد)، محايدٌ للصلاحية (لا يحمل إلا
     تسمياتِ الموقع لا بياناتٍ)، ولا يُعرَض للزائر أو حين لا مسارَ ذا دلالة. --}}
@auth
    @php
        $iaTrail = \App\Support\InformationArchitecture::make()->breadcrumbs(request(), auth()->user());
        // طيُّ التكرارِ المتتالي (قسمٌ = وجهة مثلاً) كي لا يتكرّر الاسمُ مرّتين
        $iaCrumbs = [];
        $prev = null;
        foreach ($iaTrail as $c) {
            $lbl = trim((string) ($c['label'] ?? ''));
            if ($lbl === '' || $lbl === $prev) continue;
            $iaCrumbs[] = $c; $prev = $lbl;
        }
    @endphp
    @if (count($iaCrumbs) >= 2)
        <nav class="crumbs iacrumb" aria-label="مسار المعمارية" style="margin-bottom:10px">
            @foreach ($iaCrumbs as $i => $c)
                @if ($i > 0)<span aria-hidden="true">‹</span>@endif
                @if (! empty($c['url']))<a href="{{ $c['url'] }}">{{ $c['label'] }}</a>@else<span>{{ $c['label'] }}</span>@endif
            @endforeach
        </nav>
    @endif
@endauth
