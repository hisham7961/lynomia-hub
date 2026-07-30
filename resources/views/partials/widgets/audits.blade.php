{{-- ودجة: آخر النشاطات --}}
<div class="card kid" style="grid-column:span 1">
    <h3>🕘 آخر النشاطات</h3>
    <table class="mini">
        @forelse ($data ?? [] as $a)
            <tr>
                <td style="width:1%;white-space:nowrap"><span class="bdg {{ $a->action === 'حذف' ? 'bad' : ($a->action === 'إضافة' ? 'ok' : 'g') }}">{{ $a->action }}</span></td>
                <td>{{ hub_mod($a->module)['label'] ?? $a->module }}: {{ \Illuminate\Support\Str::limit($a->name, 30) }}</td>
                <td class="mono sub" style="width:1%;white-space:nowrap">{{ \Illuminate\Support\Carbon::parse($a->created_at)->format('m-d H:i') }}</td>
            </tr>
        @empty
            <tr><td class="empty">لا نشاط بعد — ابدأ بإضافة أول سجل</td></tr>
        @endforelse
    </table>
</div>
