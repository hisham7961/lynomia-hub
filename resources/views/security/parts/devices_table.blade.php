{{-- (WP-4.4) جدولُ ثقة الأجهزة — يتوقع: $rows (مُرقَّماً)، $emailMode، $ipMasked،
     $isOwner، $t. المحذوفُ ناعماً (المُبطَل) يُعرض بوسمه، و«مريب» وسمٌ مشتقٌّ من
     ذاكرة user_ips لا من بصمةٍ جديدة. لا يُعرض cookie_hash أبداً — سرُّ هويةٍ. --}}
<div class="tblwrap">
    <table class="tbl">
        <thead><tr>
            <th scope="col">الحساب</th>
            <th scope="col">الجهاز</th>
            <th scope="col">الثقة</th>
            <th scope="col">العناوين</th>
            <th scope="col">أول ظهور</th>
            <th scope="col">آخر ظهور</th>
        </tr></thead>
        <tbody>
        @forelse ($rows as $dev)
            <tr>
                <td>
                    <b>{{ $dev->uname ?? '—' }}</b>
                    @if ($emailMode !== 'hide' && $dev->uemail)
                        <div class="sub">{{ $emailMode === 'mask' ? \App\Support\SecurityFindings::maskPII($dev->uemail) : $dev->uemail }}</div>
                    @endif
                </td>
                <td>{{ $dev->label ?: '—' }}<div class="sub">{{ $dev->platform ?: '' }}</div></td>
                <td>
                    @if ($dev->revokedState)<span class="bdg bad">مُبطَل</span>
                    @elseif ($dev->trust === 'موثوق')<span class="bdg ok">موثوق</span>
                    @else<span class="bdg wn">معلّق</span>@endif
                    @if ($dev->suspicious)<span class="bdg bad" title="معلّقٌ آخرُ عنوانه غيرُ مألوفٍ لصاحبه">مريب</span>@endif
                </td>
                <td class="sub">
                    @if ($ipMasked)
                        ‹عنوان محجوب›
                    @else
                        <bdi class="mono ltr">{{ $dev->first_ip ?: '—' }}</bdi>
                        @if ($dev->last_ip && $dev->last_ip !== $dev->first_ip)
                            ← <bdi class="mono ltr">{{ $dev->last_ip }}</bdi>
                        @endif
                    @endif
                </td>
                <td class="sub">{{ $dev->first_seen_at ? \Illuminate\Support\Carbon::parse($dev->first_seen_at)->diffForHumans() : '—' }}</td>
                <td class="sub">{{ $dev->last_seen_at ? \Illuminate\Support\Carbon::parse($dev->last_seen_at)->diffForHumans() : '—' }}</td>
            </tr>
        @empty
            @include('partials.empty', ['colspan' => 6, 'icon' => '📱',
                     'text' => $t !== '' ? 'لا أجهزة في هذه الفئة' : 'لا أجهزة مسجَّلة بعد — تُنشأ صفوفُها مع الدخول'])
        @endforelse
        </tbody>
    </table>
</div>
