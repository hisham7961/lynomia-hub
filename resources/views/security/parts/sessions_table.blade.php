{{-- (WP-4.4) جدولُ مركز الجلسات — يتوقع: $rows (مُرقَّماً)، $emailMode (''|mask|hide)،
     $ipMasked، $isOwner. البريدُ والعنوانُ لغير المالك مطموسان إلزاماً (critic #9)،
     والمتصفّح/النظام من Devices::describe الواحد — لا محلِّلَ UA ثانياً. --}}
<div class="tblwrap">
    <table class="tbl">
        <thead><tr>
            <th scope="col">الحساب</th>
            <th scope="col">الجهاز</th>
            <th scope="col">العنوان</th>
            <th scope="col">الحالة</th>
            <th scope="col">بدأت</th>
            <th scope="col">آخر ظهور</th>
            <th scope="col">العمر</th>
            <th scope="col">أثر الإنهاء</th>
            <th scope="col" class="acts"></th>
        </tr></thead>
        <tbody>
        @forelse ($rows as $ses)
            <tr>
                <td>
                    <b>{{ $ses->uname ?? '—' }}</b>
                    @if ($emailMode !== 'hide' && $ses->uemail)
                        <div class="sub">{{ $emailMode === 'mask' ? \App\Support\SecurityFindings::maskPII($ses->uemail) : $ses->uemail }}</div>
                    @endif
                </td>
                <td class="sub" title="{{ $ses->device }}">{{ $ses->browser ?: '—' }}</td>
                <td>
                    @if (! $ses->ip)<span class="sub">—</span>
                    @elseif ($ipMasked)<span class="sub">‹عنوان محجوب›</span>
                    @else<bdi class="mono ltr">{{ $ses->ip }}</bdi>@endif
                    @if ($ses->unusual)<span class="bdg wn" title="عنوانٌ لم يألفه صاحبُ الجلسة في ذاكرة العناوين">غير معتادة</span>@endif
                </td>
                <td>
                    @if ($ses->revoked)<span class="bdg bad">مُنهاة</span>
                    @elseif ($ses->live)<span class="bdg ok">نشطة الآن</span>
                    @else<span class="bdg g">خاملة</span>@endif
                    @if ($ses->mine)<span class="bdg">جلستك</span>@endif
                </td>
                <td class="sub">{{ $ses->started_at ? \Illuminate\Support\Carbon::parse($ses->started_at)->diffForHumans() : '—' }}</td>
                <td class="sub">{{ $ses->last_seen_at ? \Illuminate\Support\Carbon::parse($ses->last_seen_at)->diffForHumans() : '—' }}</td>
                <td class="sub">{{ $ses->age }}</td>
                <td class="sub">
                    @if (! empty($ses->revoked_at ?? null))
                        أُنهيت {{ \Illuminate\Support\Carbon::parse($ses->revoked_at)->diffForHumans() }}
                        @if (! empty($ses->revoke_reason ?? null))<div>{{ $ses->revoke_reason }}</div>@endif
                    @elseif ($ses->revoked)
                        خروجٌ طوعيّ
                    @else
                        —
                    @endif
                </td>
                <td class="acts">
                    @if ($isOwner && ! $ses->revoked && ! $ses->mine)
                        <form method="POST" action="{{ route('security.session.revoke', $ses->id) }}" style="display:inline"
                              data-confirm="إنهاء هذه الجلسة؟ يخرج الجهاز عند أول طلب، ورمز «تذكّرني» يُدوَّر.">
                            @csrf<button class="btn ghost xs" style="color:var(--bad);border-color:var(--bad)">أنهِ الجلسة</button>
                        </form>
                    @endif
                    @if ($isOwner && $ses->user_id)
                        {{-- بابُ «إنهاء الباقي» (§18): كلُّ جلسات صاحب هذا الصفّ عدا جلسة المنفّذ — بتصعيدِ هويةٍ داخل الفعل --}}
                        <form method="POST" action="{{ route('security.user.revokeothers', $ses->user_id) }}" style="display:inline"
                              data-confirm="إنهاء كل جلسات «{{ $ses->uname ?? 'هذا المستخدم' }}» عدا جلستك الحالية؟">
                            @csrf<button class="btn ghost xs">أنهِ الباقي</button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            @include('partials.empty', ['colspan' => 9, 'icon' => '🖥️',
                     'text' => ($u !== '' || $fip !== '' || $state !== '') ? 'لا جلسات بهذه التصفية في المدى المحدَّد' : 'لا جلسات في هذا المدى — جرّب مدىً أوسع'])
        @endforelse
        </tbody>
    </table>
</div>
