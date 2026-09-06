    <div class="card kid">
        <h3>🖥️ الجلسات <span class="sub">(أول ٢٥ — الأحدثُ ظهوراً، ولكلٍّ زرّ إنهاء)</span>
            <a class="btn ghost xs" href="{{ route('security.sessions') }}">مركز الجلسات ←</a>
            <a class="btn ghost xs" href="{{ route('security.devices') }}">الأجهزة ←</a></h3>
        <table class="mini">
            @forelse ($sessions as $s)
                <tr>
                    <td>
                        <b>{{ $s->uname ?? '—' }}</b>
                        @if ($s->revoked)<span class="bdg bad">مُنهاة</span>
                        @elseif ($s->live)<span class="bdg ok">نشطة الآن</span>@endif
                        @if ($s->mine)<span class="bdg">جلستك</span>@endif
                        <div class="sub" title="{{ $s->device }}">
                            {{ $s->ip ?: 'بلا عنوان' }} · دخلت {{ \Illuminate\Support\Carbon::parse($s->started_at)->diffForHumans() }}
                            @if ($s->last_seen_at) · آخر ظهور {{ \Illuminate\Support\Carbon::parse($s->last_seen_at)->diffForHumans() }}@endif
                        </div>
                    </td>
                    <td class="acts">
                        @unless ($s->revoked || $s->mine)
                            <form method="POST" action="{{ route('security.session.revoke', $s->id) }}" style="display:inline"
                                  data-confirm="إنهاء هذه الجلسة؟ يخرج الجهاز عند أول طلب، ورمز «تذكّرني» يُدوَّر فتُنهى بقية أجهزة هذا المستخدم أيضاً.">
                                @csrf<button class="btn ghost xs" style="color:var(--bad);border-color:var(--bad)">أنهِ الجلسة</button>
                            </form>
                        @endunless
                    </td>
                </tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا جلسات مسجلة</td></tr>
            @endforelse
        </table>
        <div class="sub" style="margin-top:6px">
            الإنهاء يسري عند أول طلبٍ للجهاز، ويُدوَّر معه رمز «تذكّرني» — بغير ذلك تُبعث الجلسة من كعكةٍ عمرها ٤٠٠ يوم.
        </div>
    </div>
