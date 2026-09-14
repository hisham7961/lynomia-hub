{{-- (الجولة 1 · F18) لافتةُ «المتأخرة فعلاً»: حالةُ «متأخرة» لا يكتبها أحدٌ آلياً،
     ففلترُ الحالة كان يخفي المستنداتِ المتجاوزةَ استحقاقَها. العدُّ هنا من الحقيقة
     (due فات ولم يُسدَّد وليست ميتة) بنفس تنطيق القائمة، والرابطُ عرضٌ جاهزٌ
     يلتقطها بالاستحقاق (?overdue=1) — نفسُ الشرط الذي يطبّقه buildQuery. --}}
@php
    $finNotOverdue = array_merge((array) config('hub.fin.dead', []), ['مدفوعة']);
    $finOverdueN = request()->boolean('overdue') || request()->boolean('trash') ? 0
        : hub_client_scope(hub_company_scope(hub_scope(\App\Models\FinDocument::query(), 'fin'), 'fin'), 'fin')
            ->whereNotNull('due')->whereDate('due', '<', now()->toDateString())
            ->where(fn ($w) => $w->whereNull('state')->orWhereNotIn('state', $finNotOverdue))
            ->whereRaw('COALESCE(paid, 0) < COALESCE(total, 0)')
            ->count();
@endphp
@if ($finOverdueN > 0)
    <div class="card" style="border-inline-start:4px solid var(--bad, #c0392b);display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <span>⏰</span>
        <div style="flex:1;min-width:200px">
            <b>{{ $finOverdueN }}</b> مستنداً تجاوز تاريخَ استحقاقه ولم يُسدَّد — وحالتُه المكتوبة لا تقول «متأخرة»،
            <span class="sub">ففلترُ الحالة وحدَه لا يلتقطه.</span>
        </div>
        <a class="btn ghost sm" href="{{ route('m.index', ['fin', 'overdue' => 1]) }}">⏰ اعرض المتأخرة فعلاً</a>
    </div>
@endif
