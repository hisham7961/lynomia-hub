{{-- جدول النتائج والتوصيات (WP-1.5، spec §34 المستوى ٤) — يتوقع:
     $rows[] = {sev, title, why?, fix?, url?, n?, at?, actions?}. الشدّةُ تمرّ بسلّم
     Severity الواحد (تُطبَّع أيُّ مفردةٍ قائمة)، وactions أزرارٌ جاهزةٌ من المُنادي. --}}
<div class="tblwrap">
    <table class="tbl">
        <thead><tr>
            <th scope="col">الشدّة</th>
            <th scope="col">النتيجة</th>
            <th scope="col">التوصية</th>
            <th scope="col" class="acts"></th>
        </tr></thead>
        <tbody>
        @forelse ($rows ?? [] as $ccF)
            <tr>
                <td>
                    <span class="bdg {{ \App\Support\Severity::tone($ccF['sev'] ?? null) }}">{{ \App\Support\Severity::label($ccF['sev'] ?? null) }}</span>
                    @if (($ccF['n'] ?? 0) > 1)<span class="bdg g" title="عدد مرات الرصد">×{{ $ccF['n'] }}</span>@endif
                </td>
                <td>
                    @if (! empty($ccF['url']))<a href="{{ $ccF['url'] }}"><b>{{ $ccF['title'] }}</b></a>@else<b>{{ $ccF['title'] }}</b>@endif
                    @if (! empty($ccF['why']))<div class="sub">{{ $ccF['why'] }}</div>@endif
                    @if (! empty($ccF['at']))<div class="sub">{{ $ccF['at'] instanceof \Carbon\CarbonInterface ? $ccF['at']->diffForHumans() : $ccF['at'] }}</div>@endif
                </td>
                <td>{{ $ccF['fix'] ?? '—' }}</td>
                <td class="acts">@if (! empty($ccF['actions'])){!! $ccF['actions'] !!}@endif</td>
            </tr>
        @empty
            @include('partials.empty', ['colspan' => 4, 'text' => $empty ?? 'لا نتائج — لا شيءَ يستدعي الانتباه', 'icon' => '✅'])
        @endforelse
        </tbody>
    </table>
</div>
