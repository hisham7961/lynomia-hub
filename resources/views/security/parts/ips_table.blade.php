{{-- (WP-4.4) جدولُ ذكاء العناوين — يتوقع: $rows (مُرقَّماً)، $ipMasked، $isOwner.
     العنوانُ لغير المالك مطموسٌ (critic #9) وبلا رابطِ تفصيلٍ يحمل العنوانَ نفسَه.
     الفرزُ من رؤوس cc/th وفاصلُ التعادل العنوانُ الفريد — ترتيبٌ حتميّ. --}}
<div class="tblwrap">
    <table class="tbl">
        <thead><tr>
            <th scope="col">العنوان</th>
            <th scope="col">الوسم</th>
            <th scope="col">أول ظهور (تقريبيّ)</th>
            @include('partials.cc.th', ['col' => 'last', 'label' => 'آخر ظهور', 'default' => 'last'])
            @include('partials.cc.th', ['col' => 'success', 'label' => 'دخول ناجح', 'default' => 'last'])
            @include('partials.cc.th', ['col' => 'fails', 'label' => 'دخول فاشل', 'default' => 'last'])
            <th scope="col">أهداف الفشل</th>
            <th scope="col">مستخدمون</th>
            @include('partials.cc.th', ['col' => 'denials', 'label' => 'مرفوض', 'default' => 'last'])
        </tr></thead>
        <tbody>
        @forelse ($rows as $ipr)
            <tr>
                <td>
                    @if ($ipMasked)
                        <span class="sub">‹عنوان محجوب›</span>
                    @else
                        <a href="{{ route('security.ip', $ipr->ip) }}"><bdi class="mono ltr">{{ $ipr->ip }}</bdi></a>
                    @endif
                </td>
                <td><span class="bdg {{ $ipr->label['tone'] }}">{{ $ipr->label['label'] }}</span></td>
                <td class="sub">{{ $ipr->first_seen ? \Illuminate\Support\Carbon::parse($ipr->first_seen)->diffForHumans() : '—' }}</td>
                <td class="sub">{{ $ipr->last_seen ? \Illuminate\Support\Carbon::parse($ipr->last_seen)->diffForHumans() : '—' }}</td>
                <td>{{ (int) $ipr->success ?: '—' }}</td>
                <td>{{ (int) $ipr->fails ?: '—' }}</td>
                <td>{{ (int) $ipr->fail_targets ?: '—' }}</td>
                <td>{{ (int) $ipr->users ?: '—' }}</td>
                <td>{{ (int) $ipr->denials ?: '—' }}</td>
            </tr>
        @empty
            @include('partials.empty', ['colspan' => 9, 'icon' => '🌐',
                     'text' => 'لا أثرَ لعناوينَ في هذا المدى — جرّب مدىً أوسع'])
        @endforelse
        </tbody>
    </table>
</div>
