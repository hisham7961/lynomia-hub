{{-- (الطور H · WP-H.2) عقدةُ الشجرة الدلاليّة — تُستدعى ذاتيّاً على شجرة BFS.
     كلُّ ما هنا مُرشَّحٌ خادميّاً سلفاً في RelationshipProjection (hub_read لكل
     عقدة، عدّادٌ مُرشَّح) — القالبُ يعرض ولا يُرشِّح ولا يُخفي بـJS. --}}
@php $n = $byKey[$key] ?? null; @endphp
@if ($n)
<li>
    <span class="gnode" data-hop="{{ $n['hop'] }}">
        @if (($edge ?? '') !== '')<span class="gvia sub">{{ $edge }} ◂</span>@endif
        <span class="gmod">{{ hub_mod($n['module'])['label'] ?? $n['module'] }}</span>
        <a class="glbl" href="{{ route('m.show', [$n['module'], $n['id']]) }}">{{ $n['label'] }}</a>
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
                @include('graph._node', ['key' => $k['key'], 'edge' => $k['edge']])
            @endforeach
        </ul>
    @endif
</li>
@endif
