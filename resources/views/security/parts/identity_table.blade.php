{{-- (WP-4.3) جدولُ خطر الهويّة — يتوقع: $rows (مُرقَّماً)، $emailMode (''|mask|hide)،
     $isOwner. البريدُ لغير المالك مطموسٌ إلزاماً (critic #9)، والعناوينُ الشبكية
     لا تُعرض أصلاً — أعدادٌ لا قيم. الأفعالُ (فتحٌ/إنهاءُ جلسات/إيقافٌ من الملف)
     للمالك وحدَه ولا شيءَ منها تلقائيّ. --}}
<div class="tblwrap">
    <table class="tbl">
        <thead><tr>
            @include('partials.cc.th', ['col' => 'name', 'label' => 'الحساب', 'default' => 'score'])
            @include('partials.cc.th', ['col' => 'score', 'label' => 'الدرجة', 'default' => 'score'])
            <th scope="col">الامتياز</th>
            @include('partials.cc.th', ['col' => 'login', 'label' => 'آخر دخول', 'default' => 'score'])
            @include('partials.cc.th', ['col' => 'failed', 'label' => 'فشل (٣٠ يوماً)', 'default' => 'score'])
            <th scope="col">العوامل</th>
            <th scope="col" class="acts"></th>
        </tr></thead>
        <tbody>
        @forelse ($rows as $ir)
            <tr>
                <td>
                    <b>{{ $ir['name'] }}</b>
                    @if ($emailMode !== 'hide')
                        <div class="sub">{{ $emailMode === 'mask' ? \App\Support\SecurityFindings::maskPII($ir['email']) : $ir['email'] }}</div>
                    @endif
                    <div class="sub">{{ $ir['role'] }}</div>
                </td>
                <td>
                    <span class="bdg {{ $ir['tone'] }}">{{ $ir['score'] }} — {{ $ir['band'] }}</span>
                </td>
                <td>
                    @if ($ir['is_owner'])<span class="bdg bad">مالك</span>@endif
                    @foreach ($ir['risky_flags'] as $irF)<span class="bdg wn">{{ $irF }}</span>@endforeach
                    @unless ($ir['twofa'])<span class="bdg wn" title="بلا مصادقة بخطوتين">بلا 2FA</span>@endunless
                    @if ($ir['passkeys'])<span class="bdg ok" title="مفاتيح مرور WebAuthn">🔑 {{ $ir['passkeys'] }}</span>@endif
                </td>
                <td class="sub">
                    {{ $ir['last_login_at'] ? $ir['last_login_at']->diffForHumans() : 'لم يدخل قط' }}
                    @if ($ir['idle_tier'])<span class="bdg g">+{{ $ir['idle_tier'] }}</span>@endif
                </td>
                <td>{{ $ir['failed30'] ?: '—' }}</td>
                <td>
                    @if ($ir['factors'])
                        <details>
                            <summary class="sub" style="cursor:pointer">{{ count($ir['factors']) }} عاملاً</summary>
                            <ul class="sub" style="margin:6px 0 0;padding-inline-start:16px">
                                @foreach ($ir['factors'] as $irX)
                                    <li>{{ $irX['label'] }} <bdi class="mono ltr">({{ $irX['points'] > 0 ? '+' : '' }}{{ $irX['points'] }})</bdi></li>
                                @endforeach
                            </ul>
                        </details>
                    @else
                        <span class="sub">لا عوامل — حسابٌ هادئ</span>
                    @endif
                </td>
                <td class="acts">
                    @if ($isOwner)
                        <a class="btn ghost xs" href="{{ route('users.edit', $ir['id']) }}" title="الفتحُ والإيقافُ وتفعيل 2FA — كلُّها من ملف المستخدم">👤 الملف</a>
                        @if ($ir['live'])
                            <form method="POST" action="{{ route('security.user.revoke', $ir['id']) }}" style="display:inline"
                                  data-confirm="إنهاء كل جلسات «{{ $ir['name'] }}»؟">
                                @csrf<button class="btn ghost xs">🔌 أنهِ جلساته ({{ $ir['live'] }})</button>
                            </form>
                        @endif
                    @endif
                </td>
            </tr>
        @empty
            @include('partials.empty', ['colspan' => 7, 'icon' => '🪪',
                     'text' => 'لا حساباتَ في نطاقك — الخريطةُ تُحسب من المستخدمين النشطين'])
        @endforelse
        </tbody>
    </table>
</div>
