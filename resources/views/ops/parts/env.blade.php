    <div class="card kid" id="ops-env">
        <h3>🛠️ بيئة التشغيل</h3>
        {{-- (WP-2.7 · critic #14) .tblwrap لكل جداول أقسام التشغيل --}}
        <div class="tblwrap"><table class="mini">
            <tr><td>البيئة</td><td class="acts"><span class="bdg {{ $env['env'] === 'production' ? 'ok' : 'wn' }}">{{ $env['env'] }}</span></td></tr>
            <tr><td>وضع التصحيح Debug</td><td><span class="bdg {{ $env['debug'] ? 'bad' : 'ok' }}">{{ $env['debug'] ? '⚠️ مفعّل — أطفئه في الإنتاج' : 'متوقف ✓' }}</span></td></tr>
            <tr><td>الكاش · الجلسات · الطوابير</td><td class="mono ltr">{{ $env['cache'] }} · {{ $env['session'] }} · {{ $env['queue'] }}</td></tr>
            <tr><td>مسرّع OPcache</td><td><span class="bdg {{ $env['opcache'] === 'مفعّل' ? 'ok' : 'wn' }}">{{ $env['opcache'] }}</span></td></tr>
        </table></div>
        <form method="POST" action="{{ route('ops.maintenance') }}" style="margin-top:10px"
              data-confirm="{{ $env['maint'] ? 'إنهاء وضع الصيانة وإعادة النظام للجميع؟' : 'تفعيل وضع الصيانة؟ يقفل النظام على غير المالكين برسالة مهذبة.' }}">
            @csrf<button class="btn ghost xs" @if(!$env['maint'])style="color:var(--bad)"@endif>
                {{ $env['maint'] ? '🔓 إنهاء وضع الصيانة (مفعّل الآن!)' : '🔧 تفعيل وضع الصيانة' }}</button>
        </form>
    </div>
