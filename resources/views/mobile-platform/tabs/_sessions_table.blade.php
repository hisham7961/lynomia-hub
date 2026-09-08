{{-- جدولُ جلساتٍ آمن (لا تجزئةَ رمزٍ) — يُشارَك بين القائمة وجهاز 360. paged=true ⇒ مُصفَّح. --}}
@php $dt = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('m-d H:i') : '—'; @endphp
<table class="mini">
    <tr><th>المستخدم</th><th>المنصّة</th><th>الإصدار</th><th>آخر استعمال</th><th>IP</th><th>الحالة</th><th></th></tr>
    @forelse ($rows as $s)
        @php $st = \App\Support\MobilePlatform::sessionStatus($s); @endphp
        <tr>
            <td>{{ $s->user?->name ?? '—' }}</td>
            <td>{{ $s->platform ?: '—' }}</td>
            <td class="sub">{{ $s->app_version ?: '—' }}</td>
            <td class="sub">{{ $dt($s->last_used_at) }}</td>
            <td class="mono sub">{{ $s->last_ip ?: '—' }}</td>
            <td><span class="bdg {{ $st['tone'] }}">{{ $st['label'] }}</span></td>
            <td class="acts">
                <a class="btn ghost xs" href="{{ route('mobileplatform.index', ['tab' => 'devices', 'session' => $s->id]) }}">تفتيش</a>
                @unless ($s->revoked_at)
                    <form method="POST" action="{{ route('mobileplatform.session.revoke', $s->id) }}" style="display:inline"
                          onsubmit="return confirm('إبطالُ الجلسة؟')">@csrf<button class="btn ghost xs" type="submit">إبطال</button></form>
                @endunless
            </td>
        </tr>
    @empty
        <tr><td colspan="7" class="sub" style="text-align:center;padding:12px">لا جلساتٍ مطابقة</td></tr>
    @endforelse
</table>
@if (! empty($paged)){{ $rows->links('partials.pagination_simple') }}@endif
