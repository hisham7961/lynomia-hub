    <div class="card kid">
        <h3>🎭 الوضع التجريبي (Sandbox)</h3>
        <div class="sub" style="margin-bottom:8px">
            بيانات وهمية واقعية (موسومة 🎭) للتدريب وتجربة الاستيراد والمسارات والـ API بلا خوف —
            {{ setting('demo.on') ? 'مفعّل الآن، صفّره أو أنهه من الشريط العلوي.' : 'غير مفعّل.' }}
        </div>
        @if (! setting('demo.on'))
            <form method="POST" action="{{ route('demo.reset') }}" data-confirm="توليد بيانات تجريبية وهمية؟ تُمسح كلها بزر الإنهاء.">
                @csrf<button class="btn ghost xs">🎭 تفعيل الوضع التجريبي</button>
            </form>
        @endif
    </div>
