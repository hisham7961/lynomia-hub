    <div class="card kid">
        <h3>📤 سجل التصدير <span class="sub">(آخر ٨ — والسجلُّ الكامل في التدقيق)</span></h3>
        <table class="mini">
            @forelse ($exports as $e)
                <tr><td>{{ $e->uname ?? '—' }} — {{ hub_mod($e->module)['label'] ?? $e->module }}<div class="sub">{{ $e->name }} · {{ \Illuminate\Support\Carbon::parse($e->created_at)->diffForHumans() }}</div></td>
                    <td class="mono ltr acts">{{ $e->ip }}</td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا عمليات تصدير بعد</td></tr>
            @endforelse
        </table>
    </div>
