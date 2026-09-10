{{-- (الطور H · WP-H.2) عقدةُ الشجرة الدلاليّة — تُستدعى ذاتيّاً على شجرة BFS.
     كلُّ ما هنا مُرشَّحٌ خادميّاً سلفاً في RelationshipProjection (hub_read لكل
     عقدة، عدّادٌ مُرشَّح) — القالبُ يعرض ولا يُرشِّح ولا يُخفي بـJS. --}}
@php $n = $byKey[$key] ?? null; @endphp
@if ($n)
<li>
    @php
        // (§55/§56) «افتح 360»: العقدةُ تفتح سطحَها 360 القانونيّ — الموظفُ ملفَّه
        // الشامل (portal.employee)، وسائرُ الوحدات m.show (وهو نفسُه 360 للمحطة/الأصل/المشروع).
        $n360 = $n['module'] === 'hr'
            ? route('portal.employee', $n['id'])
            : route('m.show', [$n['module'], $n['id']]);
    @endphp
    <span class="gnode" data-hop="{{ $n['hop'] }}">
        @if (($edge ?? '') !== '')<span class="gvia sub">{{ $edge }} ◂</span>@endif
        @if (($kind ?? 'direct') === 'derived')<span class="bdg wn gcnt" title="علاقةٌ مشتقّةٌ لا مباشرة">مشتقّة</span>@endif
        @if (($active ?? null) === false)<span class="bdg wn gcnt" title="علاقةٌ منتهية (وضعُ التاريخ)">تاريخيّة</span>@endif
        <span class="gmod">{{ hub_mod($n['module'])['label'] ?? $n['module'] }}</span>
        <a class="glbl" href="{{ $n360 }}">{{ $n['label'] }}</a>
        <a class="gjump" href="{{ route('graph.explore', ['m' => $n['module'], 'id' => $n['id'], 'hops' => $hops]) }}"
           title="اجعل هذه العقدةَ جذرَ الإسقاط">⌖</a>
        {{-- العدّاداتُ المُرشَّحة: كم ابناً **يراه هذا القارئ** لكل وحدةِ ابن — لا عدَّ خام --}}
        @foreach (($n['counts'] ?? []) as $cm => $cnt)
            <span class="bdg gcnt">{{ hub_mod($cm)['label'] ?? $cm }}: {{ $cnt }}</span>
        @endforeach
    </span>
    @if (! empty($kids[$n['key']]))
        <ul>
            @foreach ($kids[$n['key']] as $k)
                @include('graph._node', ['key' => $k['key'], 'edge' => $k['edge'],
                    'kind' => $k['kind'] ?? 'direct', 'active' => $k['active'] ?? null])
            @endforeach
        </ul>
    @endif
</li>
@endif
