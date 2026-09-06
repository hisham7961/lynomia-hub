    <div class="card kid">
        <h3>🗝️ أسرار تحتاج تدويراً <span class="sub">(أول ١٠ — الأقدمُ تدويراً)</span>
            <a class="btn ghost xs" href="{{ route('security.secrets') }}">صحّة الأسرار كاملةً ←</a></h3>
        <table class="mini">
            @forelse ($staleSecrets as $s)
                <tr><td><a href="{{ route('m.show', ['vault', $s->id]) }}">{{ \Illuminate\Support\Str::limit($s->title, 30) }}</a>
                    <div class="sub">{{ $s->type }} · {{ $s->rotated_at ? 'آخر تدوير ' . \Illuminate\Support\Carbon::parse($s->rotated_at)->diffForHumans() : 'لم يُدوَّر منذ إنشائه ' . \Illuminate\Support\Carbon::parse($s->created_at)->diffForHumans() }}</div></td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">كل الأسرار محدّثة 🎉</td></tr>
            @endforelse
        </table>
    </div>
