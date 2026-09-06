{{-- رادارُ الكشف الحيّ: من طرق باباً لا يملك مفتاحه — وصولٌ مرفوض (٤٠٣) وتخمينُ روابط --}}
<div class="card" style="margin-bottom:12px">
    <div class="crow" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
        <h3 style="margin:0">📡 رادار الكشف الحيّ <span class="sub">(٧ أيام)</span>
            <a class="btn ghost xs" href="{{ route('security.ips') }}">ذكاء العناوين ←</a></h3>
        <div class="crow" style="gap:6px">
            <span class="bdg {{ $radar['total'] ? 'bad' : 'ok' }}">{{ $radar['total'] }} محاولة مرفوضة</span>
            <span class="bdg wn">{{ $radar['ips'] }} عنوان</span>
            <span class="bdg {{ $radar['anon'] ? 'wn' : 'ok' }}">{{ $radar['anon'] }} من غير مستخدم</span>
        </div>
    </div>
    <div class="sub" style="margin:4px 0 10px">
        كل طرقةٍ على بابٍ بلا مفتاح: وصولٌ مرفوض (٤٠٣) خارج الصلاحية، أو تخمينُ رابط توقيعٍ من غير مستخدم.
        مراقبةٌ حيّة — لا كلمةَ مرورٍ خاطئة (تلك في «المحاولات الفاشلة») بل محاولةُ فتحِ ما لا يُملَك.
    </div>

    @if ($threats->count())
        <div style="margin-bottom:10px;padding:8px 10px;border-radius:9px;border:1px solid var(--ln);
                    background:color-mix(in srgb, var(--bad) 9%, transparent)">
            <b style="font-size:13px">⛔ عناوين تكرّر الطرق</b>
            <div class="sub" style="margin-top:4px">عنوانٌ يطرق مراراً وأهدافُه تتعدّد تقصٍّ لا خطأ — راجعه، وفكّر في حصر الوصول بالشبكة من ملفات المستخدمين.</div>
            @foreach ($threats as $t)
                <div class="sub mono ltr" style="font-size:12px">
                    {{ $t->ip }} — {{ $t->hits }} محاولة على {{ $t->targets }} مسار{{ (int) $t->anon ? ' · ' . $t->anon . ' من غير مستخدم' : '' }}
                </div>
            @endforeach
        </div>
    @endif

    <table class="mini">
        @forelse ($denials as $d)
            <tr>
                <td>
                    <span class="bdg {{ $d->kind === 'تخمين رابط' ? 'wn' : 'bad' }}">{{ $d->kind }}</span>
                    <b>{{ $d->uname ?? 'زائرٌ غير مستخدم' }}</b>
                    <div class="sub mono ltr" title="{{ $d->path }}">
                        {{ $d->method }} {{ \Illuminate\Support\Str::limit($d->path, 44) }} · {{ \Illuminate\Support\Carbon::parse($d->created_at)->diffForHumans() }}
                    </div>
                </td>
                <td class="mono ltr acts">{{ $d->ip ?: '—' }}</td>
            </tr>
        @empty
            <tr><td class="sub" style="padding:12px;text-align:center">لا محاولات وصولٍ مرفوضة — الرادار صافٍ 🟢</td></tr>
        @endforelse
    </table>
</div>
