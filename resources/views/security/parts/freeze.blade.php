{{-- مفاتيحُ الطوارئ المفصولة: كلٌّ يشدّ فرملةً وحدَه — لا زرٌّ واحدٌ خطر --}}
<div class="card" style="margin-bottom:12px">
    <div class="crow" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
        <h3 style="margin:0">🧯 مفاتيح الطوارئ المفصولة</h3>
        <div class="sub" style="margin:0">شدُّ الفرملة فوريّ؛ رفعُها يتطلّب تأكيدَ الهوية — كلُّ تبديلٍ مسجَّلٌ في التدقيق.</div>
    </div>
    <div class="crow" style="gap:10px;margin-top:10px;flex-wrap:wrap">
        @foreach ([['exports', '📤 تصدير البيانات', $freezeExports], ['tokens', '🔑 سكّ مفاتيح API', $freezeTokens]] as [$fk, $flabel, $fon])
            <form method="POST" action="{{ route('security.freeze', $fk) }}"
                  data-confirm="{{ $fon ? 'رفعُ تجميد ' . $flabel . '؟ سيُعاد تفعيلُه — قد يُطلب تأكيدُ الهوية.' : 'تجميدُ ' . $flabel . ' فوراً؟' }}">
                @csrf
                <button class="btn {{ $fon ? '' : 'ghost' }} sm" type="submit"
                        style="{{ $fon ? 'background:var(--bad);border-color:var(--bad);color:#fff' : '' }}">
                    {{ $fon ? '♻️ رفع تجميد ' . $flabel . ' (مجمَّد الآن!)' : '🧊 تجميد ' . $flabel }}
                </button>
            </form>
        @endforeach
    </div>
    @if ($freezeExports || $freezeTokens)
        <div class="flash wn" style="position:static;margin-top:10px">🧊 مُجمَّدٌ الآن:
            {{ $freezeExports ? 'التصدير' : '' }}{{ $freezeExports && $freezeTokens ? ' و' : '' }}{{ $freezeTokens ? 'سكّ الرموز' : '' }}
            — يُصَدّ بالرمز ٤٢٣ حتى يُرفع من هنا.</div>
    @endif
</div>
