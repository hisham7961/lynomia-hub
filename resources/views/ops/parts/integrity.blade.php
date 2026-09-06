    <div class="card kid">
        <b>🔎 فاحصا السلامة</b>
        <div class="sub" style="margin-bottom:8px">
            <b>سلسلة التدقيق</b> تُثبت أن لا أحدَ عبث بالسجل — وتُفحص أسبوعياً آلياً،
            وهنا تُفحص فوراً. و<b>انحراف المخطّط</b> يكشف عموداً ينتظره الكودُ ولا وجودَ له
            في قاعدتك — علّةُ «Unknown column» قبل أن تُطفئ شاشة.
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
            <form method="POST" action="{{ route('ops.verifyaudit') }}">
                @csrf<button class="btn ghost xs">🔗 افحص سلسلة التدقيق</button>
            </form>
            <form method="POST" action="{{ route('ops.schemacheck') }}">
                @csrf<button class="btn ghost xs">🧬 افحص المخطّط</button>
            </form>
        </div>
    </div>
