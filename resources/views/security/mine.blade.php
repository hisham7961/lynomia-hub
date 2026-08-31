@extends('layouts.app')
@section('title', 'أمني — جلساتي وأجهزتي')
@section('content')
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>حسابي</span><span aria-hidden="true">‹</span><b>الأمان</b></nav>
        <h2>🔐 جلساتي وأجهزتي</h2>
        <div class="sub">أين حسابُك مفتوحٌ الآن — وأبطِل ما لا تعرفه بيدك</div>
    </div>
    <a class="btn ghost sm" href="{{ route('profile.edit') }}">← الملف الشخصي</a>
</div>

<div class="card">
    <h3 class="cardtitle">📊 خطرُ جلستك الآن
        <span class="bdg {{ $risk['tone'] }}">{{ $risk['band'] }} — {{ $risk['score'] }}</span>
    </h3>
    @if ($risk['factors'])
        <div class="sub" style="margin-bottom:6px">لماذا هذه الدرجة — كلُّ عاملٍ ببنده:</div>
        <div class="crow">
            @foreach ($risk['factors'] as $fac)
                <span class="chip">+{{ $fac['points'] }} {{ $fac['label'] }}</span>
            @endforeach
        </div>
    @else
        <div class="sub">لا عوامل خطرٍ على جلستك — جهازٌ وعنوانٌ معتادان في وقتٍ معتاد.</div>
    @endif
    <div class="sub" style="margin-top:8px">الخطرُ إشارةٌ للانتباه لا حكمٌ آليّ — ولا يُحدِّد حضورَك ولا يعاقبك.</div>
</div>

<div class="card">
    <h3 class="cardtitle">🖥️ الجلسات النشطة
        <form method="POST" action="{{ route('mysec.session.others') }}" class="inline" style="float:left">
            @csrf<button class="btn ghost sm" data-confirm="إنهاء كل جلساتك الأخرى؟ جلستُك الحالية تبقى.">إنهاء الجلسات الأخرى</button>
        </form>
    </h3>
    <div class="tblwrap"><table>
        <thead><tr><th>الجهاز</th><th>العنوان</th><th>البداية</th><th>آخر نشاط</th><th>الحالة</th><th></th></tr></thead>
        <tbody>
        @forelse ($sessions as $s)
            <tr>
                <td>{{ \Illuminate\Support\Str::limit($s->device, 44) ?: '—' }}</td>
                <td class="mono">{{ $s->ip ?: '—' }}</td>
                <td class="sub">{{ $s->started_at ? \Illuminate\Support\Carbon::parse($s->started_at)->format('Y-m-d H:i') : '—' }}</td>
                <td class="sub">{{ $s->last_seen_at ? \Illuminate\Support\Carbon::parse($s->last_seen_at)->diffForHumans() : '—' }}</td>
                <td>
                    @if ($s->mine)<span class="bdg g">هذه الجلسة</span>
                    @elseif ($s->revoked)<span class="bdg">مُنهاة</span>
                    @elseif ($s->live)<span class="bdg wn">حيّة</span>
                    @else<span class="bdg">خاملة</span>@endif
                </td>
                <td>
                    @unless ($s->mine || $s->revoked)
                        <form method="POST" action="{{ route('mysec.session.revoke', $s->id) }}" class="inline">
                            @csrf<button class="btn ghost xs bad" data-confirm="إنهاء هذه الجلسة؟">إنهاء</button>
                        </form>
                    @endunless
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty">لا جلسات مسجّلة</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>

<div class="card">
    <h3 class="cardtitle">📱 أجهزتي المعروفة</h3>
    <div class="sub" style="margin-bottom:8px">جهازٌ «موثوق» يخفّف الاحتكاك مستقبلاً؛ «معلّق» جهازٌ رأيناه ولم تؤكّده بعد؛ إبطالُ جهازٍ يُنهي جلساتِه.</div>
    <div class="tblwrap"><table>
        <thead><tr><th>الجهاز</th><th>أول ظهور</th><th>آخر ظهور</th><th>آخر عنوان</th><th>الثقة</th><th></th></tr></thead>
        <tbody>
        @forelse ($devices as $d)
            <tr>
                <td>{{ $d->label ?: 'جهاز' }}</td>
                <td class="sub">{{ optional($d->first_seen_at)->format('Y-m-d') ?: '—' }}</td>
                <td class="sub">{{ $d->last_seen_at ? $d->last_seen_at->diffForHumans() : '—' }}</td>
                <td class="mono">{{ $d->last_ip ?: '—' }}</td>
                <td>
                    @if ($d->trust === 'موثوق')<span class="bdg g">موثوق</span>
                    @elseif ($d->trust === 'مبطَل')<span class="bdg bad">مبطَل</span>
                    @else<span class="bdg wn">معلّق</span>@endif
                </td>
                <td style="white-space:nowrap">
                    @if ($d->trust !== 'موثوق')
                        <form method="POST" action="{{ route('mysec.device.trust', $d->id) }}" class="inline">
                            @csrf<button class="btn ghost xs">توثيق</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('mysec.device.revoke', $d->id) }}" class="inline">
                        @csrf<button class="btn ghost xs bad" data-confirm="إبطال هذا الجهاز وإنهاء جلساتِه؟">إبطال</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty">لا أجهزة معروفة بعد — يُسجَّل جهازُك عند أول دخول بعد هذا التحديث</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
@endsection
