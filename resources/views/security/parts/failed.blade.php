    <div class="card kid">
        <h3>🚫 آخر المحاولات الفاشلة</h3>
        <table class="mini">
            @forelse ($failed as $f)
                <tr><td class="mono ltr" style="font-size:12px">{{ \Illuminate\Support\Str::limit($f->name, 28) }}<div class="sub" title="{{ $f->device }}">{{ \Illuminate\Support\Carbon::parse($f->created_at)->diffForHumans() }}</div></td>
                    <td class="mono ltr acts">{{ $f->ip }}</td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا محاولات فاشلة 🎉</td></tr>
            @endforelse
        </table>
        @if ($knocking->count())
            <div style="margin-top:8px;padding:8px 10px;border-radius:9px;border:1px solid var(--ln);
                        background:color-mix(in srgb, var(--bad) 9%, transparent)">
                <b style="font-size:13px">⛔ عناوين تطرق أكثر من حساب</b>
                <div class="sub" style="margin-top:4px">تخمينٌ لا نسيان — والقفل مربوطٌ بالبريد لا بالعنوان، فقد يكون إقفالاً متعمَّداً لحسابات الفريق.</div>
                @foreach ($knocking as $k)
                    <div class="sub mono ltr" style="font-size:12px">{{ $k->ip }} — {{ $k->hits }} محاولة على {{ $k->targets }} حساباً</div>
                @endforeach
            </div>
        @endif
    </div>
