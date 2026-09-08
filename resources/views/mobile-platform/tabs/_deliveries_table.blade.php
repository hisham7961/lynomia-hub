{{-- جدولُ محاولاتِ تسليمِ دفعٍ — لا أسرار (هويّاتٌ وحالةٌ وصنفُ خطأٍ فقط) --}}
@php $dt = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('m-d H:i') : '—'; @endphp
<table class="mini">
    <tr><th>الحالة</th><th>المزوّد</th><th>المحاولات</th><th>صنفُ الخطأ</th><th>الوقت</th></tr>
    @forelse ($rows as $d)
        <tr>
            <td>{{ $d->status }}</td>
            <td>{{ $d->provider ?: '—' }}</td>
            <td>{{ $d->attempts }}</td>
            <td class="sub">{{ $d->error_category ?: '—' }}</td>
            <td class="sub">{{ $dt($d->attempted_at ?: $d->queued_at) }}</td>
        </tr>
    @empty
        <tr><td colspan="5" class="sub" style="text-align:center;padding:10px">لا تسليماتٍ مُسجّلة</td></tr>
    @endforelse
</table>
