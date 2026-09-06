{{-- (WP-4.3) جدولُ فئةٍ من فئات مراجعة الامتيازات — يتوقع: $rows (مُرقَّماً)، $cat،
     $findings (نتائجُ twofa_priv الحيّة بمفتاح المستخدم)، $emailMode، $isOwner.
     الإقرارُ عبر سكّة security_findings الواحدة (ق٤)؛ والإيقافُ من ملف المستخدم
     القائم (لا آليّةَ تعطيلٍ جديدة) — ولا شيءَ يُسحب تلقائياً. --}}
<div class="tblwrap">
    <table class="tbl">
        <thead><tr>
            <th scope="col">الحساب</th>
            <th scope="col">الدور والنطاق</th>
            <th scope="col">الشارات</th>
            <th scope="col">آخر دخول</th>
            <th scope="col" class="acts"></th>
        </tr></thead>
        <tbody>
        @forelse ($rows as $pv)
            <tr>
                <td>
                    <b>{{ $pv['name'] }}</b>
                    @if ($emailMode !== 'hide')
                        <div class="sub">{{ $emailMode === 'mask' ? \App\Support\SecurityFindings::maskPII($pv['email']) : $pv['email'] }}</div>
                    @endif
                </td>
                <td>
                    {{ $pv['role'] }}
                    <div class="sub">{{ ['all' => 'كل الشركات', 'company' => 'شركةٌ واحدة', 'proj' => 'مشروعٌ واحد', 'client' => 'عميلٌ واحد'][$pv['scope']] ?? $pv['scope'] }}{{ $pv['wide'] ? ' · بلا حصرِ شركات' : '' }}</div>
                </td>
                <td>
                    <span class="bdg {{ $pv['tone'] }}" title="درجةُ خطر الهويّة">{{ $pv['score'] }} — {{ $pv['band'] }}</span>
                    @if ($pv['is_owner'])<span class="bdg bad">مالك</span>@endif
                    @foreach ($pv['risky_flags'] as $pvF)<span class="bdg wn">{{ $pvF }}</span>@endforeach
                    @unless ($pv['twofa'])<span class="bdg wn">بلا 2FA</span>@endunless
                    @if ($cat === 'unused' && ! empty($pv['unused_mods']))
                        <div class="sub" style="margin-top:4px">لم يستعمل ({{ count($pv['unused_mods']) }}):
                            {{ implode('، ', array_slice($pv['unused_mods'], 0, 6)) }}{{ count($pv['unused_mods']) > 6 ? ' +' . (count($pv['unused_mods']) - 6) : '' }}</div>
                    @endif
                    @if ($pvFind = $findings[$pv['id']] ?? null)
                        <span class="bdg {{ ['open' => 'bad', 'acknowledged' => 'wn', 'resolved' => 'ok', 'ignored' => 'g'][$pvFind->status] ?? 'g' }}"
                              title="نتيجةٌ أمنية على سكّة الإقرار الواحدة">{{ \App\Support\SecurityFindings::STATUSES[$pvFind->status] ?? $pvFind->status }}</span>
                    @endif
                </td>
                <td class="sub">
                    {{ $pv['last_login_at'] ? $pv['last_login_at']->diffForHumans() : 'لم يدخل قط' }}
                    @if ($pv['idle_tier'])<span class="bdg g">+{{ $pv['idle_tier'] }}</span>@endif
                </td>
                <td class="acts">
                    @if ($isOwner)
                        <a class="btn ghost xs" href="{{ route('users.edit', $pv['id']) }}" title="الفتحُ والإيقافُ — من ملف المستخدم القائم">👤 الملف</a>
                        @if ($pv['role_id'])<a class="btn ghost xs" href="{{ route('roles.edit', $pv['role_id']) }}">🎭 الدور</a>@endif
                        @if ($pv['live'])
                            <form method="POST" action="{{ route('security.user.revoke', $pv['id']) }}" style="display:inline"
                                  data-confirm="إنهاء كل جلسات «{{ $pv['name'] }}»؟">
                                @csrf<button class="btn ghost xs">🔌 أنهِ جلساته</button>
                            </form>
                        @endif
                        @if ($pvFind && $pvFind->status === 'open')
                            <form method="POST" action="{{ route('security.finding.ack', $pvFind->id) }}" style="display:inline">
                                @csrf<button class="btn ghost xs" title="إقرارٌ مسجَّل في التدقيق — يبقى مرصوداً ولا يُسحب امتياز">👁️ إقرار</button>
                            </form>
                        @endif
                        @if ($pvFind)
                            <a class="btn ghost xs" href="{{ route('security.finding', $pvFind->id) }}">🔎 النتيجة</a>
                        @endif
                    @endif
                </td>
            </tr>
        @empty
            @include('partials.empty', ['colspan' => 5, 'icon' => '✅',
                     'text' => 'لا أحدَ في هذه الفئة ضمن نطاقك — لا شيءَ يستدعي المراجعة'])
        @endforelse
        </tbody>
    </table>
</div>
