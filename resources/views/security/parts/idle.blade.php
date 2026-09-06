    <div class="card kid">
        <h3>😴 مستخدمون خاملون <span class="sub">(أول ١٠ — +٦٠ يوماً بلا دخول)</span></h3>
        <table class="mini">
            @forelse ($idleUsers as $u)
                <tr><td>{{ $u->name }}<div class="sub">{{ $u->email }} · {{ $u->last_login_at ? 'آخر دخول ' . \Illuminate\Support\Carbon::parse($u->last_login_at)->diffForHumans() : 'لم يدخل قط' }}</div></td>
                    <td class="acts">
                        @unless ($u->totp_enabled)<span class="bdg wn" title="بلا مصادقة ثنائية">بلا 2FA</span>@endunless
                        <form method="POST" action="{{ route('security.user.revoke', $u->id) }}" style="display:inline"
                              data-confirm="إنهاء كل جلسات «{{ $u->name }}» على كل الأجهزة؟">
                            @csrf<button class="btn ghost xs">أنهِ جلساته</button>
                        </form>
                    </td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">الجميع نشطون 💪</td></tr>
            @endforelse
        </table>
        @if ($idleUsers->count())<div class="sub" style="margin-top:6px">راجعهم من صفحة «المستخدمون» — أوقف من لم يعد يحتاج الوصول</div>@endif
    </div>
