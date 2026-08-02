@extends('layouts.app')
@section('title', 'خوادم أودو')
@section('content')
<div class="hero">
    <div>
        <h2>🧩 خوادم أودو</h2>
        <div class="sub">
            الاتصالات المتعددة بأودو: الافتراضي من الإعدادات، وخوادمُ إضافية لمشاريعَ لها أودو خاص —
            كلُّها قراءةٌ فقط، لا كتابة محاسبية إطلاقاً.
        </div>
    </div>
    <div class="spacer"></div>
    <a class="btn ghost sm" href="{{ route('integrations.index') }}">↪ مركز التكاملات</a>
</div>

{{-- ═ جدول الاتصالات ═ --}}
<div class="card pad0">
    <div class="tblwrap">
        <table class="tbl">
            <thead><tr>
                <th>الاسم</th><th>الخادم</th><th>القاعدة</th><th>الحالة</th>
                <th>آخر اختبار ناجح</th><th>المشاريع</th><th></th>
            </tr></thead>
            <tbody>
                @php $dflt = \App\Support\Odoo::for(null); @endphp
                <tr>
                    <td><b>الافتراضي</b> <span class="sub">— من الإعدادات</span></td>
                    <td class="mono ltr">{{ $dflt->host() ?: '—' }}</td>
                    <td class="mono ltr">{{ $dflt->database() ?: '—' }}</td>
                    <td>@if ($defaultReady)<span class="bdg ok">مكتمل</span>@else<span class="bdg wn">ناقص</span>@endif</td>
                    <td class="sub">يُختبر من شاشة الإعدادات</td>
                    <td class="sub">كل ما لم يختر خادماً</td>
                    <td class="acts"><a class="btn ghost xs" href="{{ route('settings.edit') }}">⚙️ الإعدادات</a></td>
                </tr>
                @forelse ($rows as $c)
                    <tr>
                        <td><b>{{ $c->name }}</b>@if ($c->notes)<div class="sub">{{ \Illuminate\Support\Str::limit($c->notes, 60) }}</div>@endif</td>
                        <td class="mono ltr">{{ $c->url }}</td>
                        <td class="mono ltr">{{ $c->db }}</td>
                        <td>
                            @if (! $c->active)<span class="bdg bad">معطّل</span>
                            @elseif ($c->last_ok_at)<span class="bdg ok">يعمل · أودو {{ $c->last_version }}</span>
                            @else<span class="bdg wn">لم يُختبر</span>@endif
                        </td>
                        <td class="sub">{{ $c->last_ok_at?->diffForHumans() ?? '—' }}</td>
                        <td>{{ $uses[$c->id] ?? 0 }}</td>
                        <td class="acts">
                            <form method="POST" action="{{ route('integrations.odoo.test', $c->id) }}" class="inline">@csrf
                                <button class="btn ghost xs">🔌 اختبار</button></form>
                            <form method="POST" action="{{ route('integrations.odoo.toggle', $c->id) }}" class="inline">@csrf
                                <button class="btn ghost xs">{{ $c->active ? '⏸ تعطيل' : '▶️ تفعيل' }}</button></form>
                            <details class="inline">
                                <summary class="sub pointer">✏️ تعديل</summary>
                                <form method="POST" action="{{ route('integrations.odoo.update', $c->id) }}" class="frm" style="margin-top:8px">
                                    @csrf @method('PUT')
                                    <label>الاسم<input class="inp" name="name" value="{{ $c->name }}" required maxlength="120"></label>
                                    <label>الرابط<input class="inp ltr" name="url" value="{{ $c->url }}" required maxlength="300" dir="ltr"></label>
                                    <label>القاعدة<input class="inp ltr" name="db" value="{{ $c->db }}" required maxlength="120" dir="ltr"></label>
                                    <label>مستخدم القراءة<input class="inp ltr" name="username" value="{{ $c->username }}" required maxlength="200" dir="ltr"></label>
                                    <label>مفتاح API <span class="sub">(اتركه فارغاً للإبقاء على المخزون)</span>
                                        <input class="inp ltr" type="password" name="key" value="" maxlength="500" dir="ltr" placeholder="••••"></label>
                                    <label>ملاحظات<input class="inp" name="notes" value="{{ $c->notes }}" maxlength="2000"></label>
                                    <button class="btn">حفظ</button>
                                </form>
                            </details>
                            <form method="POST" action="{{ route('integrations.odoo.destroy', $c->id) }}" class="inline"
                                  data-confirm="حذف اتصال «{{ $c->name }}»؟ المشاريع المرتبطة به سترى «اتصال محذوف» حتى تختار غيره.">
                                @csrf @method('DELETE')
                                <button class="btn ghost xs danger">🗑</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty">لا خوادم إضافية — كل المشاريع على الاتصال الافتراضي. أضف خادماً أدناه إن كان لمشروعٍ أودو خاص.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="kids">
    {{-- ═ إضافة اتصال ═ --}}
    <div class="card kid">
        <h3>➕ خادم أودو جديد</h3>
        <form method="POST" action="{{ route('integrations.odoo.store') }}" class="frm">
            @csrf
            <label>الاسم<input class="inp" name="name" value="{{ old('name') }}" required maxlength="120" placeholder="أودو متجر كذا"></label>
            <label>رابط الخادم<input class="inp ltr" name="url" value="{{ old('url') }}" required maxlength="300" dir="ltr" placeholder="https://mycompany.odoo.com"></label>
            <label>اسم القاعدة<input class="inp ltr" name="db" value="{{ old('db') }}" required maxlength="120" dir="ltr" placeholder="mycompany"></label>
            <label>بريد مستخدم القراءة<input class="inp ltr" name="username" value="{{ old('username') }}" required maxlength="200" dir="ltr" placeholder="readonly@mycompany.com"></label>
            <label>مفتاح API<input class="inp ltr" type="password" name="key" required maxlength="500" dir="ltr"></label>
            <label>ملاحظات<input class="inp" name="notes" value="{{ old('notes') }}" maxlength="2000" placeholder="أودو مشروع كذا — نسخة 17"></label>
            @error('url')<div class="sub" style="color:var(--bad)">{{ $message }}</div>@enderror
            @error('conn')<div class="sub" style="color:var(--bad)">{{ $message }}</div>@enderror
            <button class="btn">إضافة الاتصال</button>
        </form>
    </div>

    {{-- ═ المشاريع المرتبطة — خريطة المزيج التشغيلية ═ --}}
    <div class="card kid">
        <h3>🗺️ أيُّ مشروعٍ على أيّ خادم؟</h3>
        @if (! $linked->count())
            <div class="sub">لا مشروع اختار خادماً بعد — الكلُّ على الافتراضي. الاختيار من شاشة «تخصيص أودو» في كل مشروع.</div>
        @else
            <table class="mini">
                @foreach ($linked as $lp)
                    <tr>
                        <td><a href="{{ route('m.show', ['projects', $lp->id]) }}">{{ $lp->name }}</a>
                            <div class="sub">{{ $lp->connName }} · {{ $lp->chCount }} قناة</div></td>
                        <td class="acts"><a class="btn ghost xs" href="{{ route('odoo.project', $lp->id) }}">⚙️ تخصيص</a></td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>
</div>

{{-- ═ الدليل التفصيلي: طريقة الربط خطوةً خطوة ═ --}}
<div class="card">
    <h3>📖 طريقة الربط التفصيلية</h3>

    <details style="margin-top:8px">
        <summary class="sub pointer"><b>١) أنشئ مستخدمَ قراءةٍ مخصصاً في أودو — ولماذا لا حسابَ المدير</b></summary>
        <div class="sub" style="line-height:2;margin-top:6px">
            في أودو: الإعدادات ← المستخدمون ← جديد. أعطه صلاحيات <b>قراءةٍ</b> على المبيعات والمحاسبة وجهات الاتصال فقط.
            صلاحياتُ هذا الحساب هي <b>سقفُ ما يراه الهَب</b> — وحسابُ المدير يمنح النظامَ أكثر مما يحتاج بكثير،
            ومفتاحُه مخزَّنٌ هنا، فتسريبُ القاعدة يسرّب صلاحياتِه كلَّها. عند الاشتباه أبطِل المفتاح <b>من أودو نفسه</b> لا من هنا.
        </div>
    </details>

    <details style="margin-top:8px">
        <summary class="sub pointer"><b>٢) ولّد مفتاح API من إعدادات أمان الحساب</b></summary>
        <div class="sub" style="line-height:2;margin-top:6px">
            ادخل بحساب مستخدم القراءة ← صورة الحساب ← تفضيلاتي ← أمان الحساب ←
            <span class="mono ltr">API Keys</span> ← مفتاح جديد. يُعرض <b>مرةً واحدة</b> — انسخه فوراً.
            هذا المفتاح هو ما يوضع في حقل «مفتاح API» هنا، لا كلمةُ مرور الحساب.
        </div>
    </details>

    <details style="margin-top:8px">
        <summary class="sub pointer"><b>٣) الرابط واسم القاعدة</b></summary>
        <div class="sub" style="line-height:2;margin-top:6px">
            الرابط جذرُ خادمك بلا مسارات: <span class="mono ltr">https://mycompany.odoo.com</span> (سحابة أودو)
            أو عنوانُ خادمك الذاتي. اسمُ القاعدة على Odoo.sh والسحابة هو غالباً اسمُ النطاق الفرعي،
            وعلى الخادم الذاتي تجده في شاشة اختيار القاعدة أو من مديرك.
            إن كان أودو خلف وكيلٍ (proxy) فتأكد أن مسار <span class="mono ltr">/jsonrpc</span> مسموحٌ فيه.
        </div>
    </details>

    <details style="margin-top:8px">
        <summary class="sub pointer"><b>٤) أضف الاتصال واختبره</b></summary>
        <div class="sub" style="line-height:2;margin-top:6px">
            بعد الإضافة اضغط <b>🔌 اختبار</b>: النجاح يسجّل إصدار الخادم وتاريخَ آخر نجاح، والفشل يقول سببه بلسانٍ عربي.
            لا تربط مشروعاً بخادمٍ لم يخضرّ اختبارُه.
        </div>
    </details>

    <details style="margin-top:8px">
        <summary class="sub pointer"><b>٥) اقرن شريكَ كل مشروع</b></summary>
        <div class="sub" style="line-height:2;margin-top:6px">
            من صفحة المشروع (أو الشركة أو العميل) ← بطاقة «🟣 أودو» ← ابحث باسم الشريك في أودو واربط.
            بعدها تمتلئ البطاقة بمبيعاته وفواتيره وغيرِ المحصَّل — بكاش ١٠ دقائق.
        </div>
    </details>

    <details style="margin-top:8px">
        <summary class="sub pointer"><b>٦) خصّص قنوات البيع لكل مشروع — وكيف تعرف مميِّزَك الصحيح</b></summary>
        <div class="sub" style="line-height:2;margin-top:6px">
            من صفحة المشروع ← بطاقة «🛍 مبيعات القنوات» ← <b>تخصيص القنوات</b>. القاعدة:
            افتح أوامر بيعٍ من منصتين مختلفتين في أودو وانظر <b>ما الحقلُ الذي يفرّقهما</b>:<br>
            · إن اختلف <b>فريق البيع</b> (وهو ما تفعله موصلات المنصات غالباً) فالمميِّز «فريق بيع».<br>
            · إن كانت فواتيرُ كل منصةٍ في <b>دفترٍ</b> مستقل فالمميِّز «دفتر» — ويُحصي الفواتير المرحّلة لا الأوامر.<br>
            · إن كنت <b>توسم</b> أوامرَ كل منصةٍ بوسمها فالمميِّز «وسم».<br>
            · ومع Odoo Websites لكل متجرٍ <b>موقعُه</b> فالمميِّز «موقع».<br>
            الشاشةُ تعرض خياراتِ كل مميِّزٍ حيّةً من خادمك — جرّب وبدّل، فالقنواتُ تُحذف وتُعاد بلا أثر.
        </div>
    </details>

    <details style="margin-top:8px">
        <summary class="sub pointer"><b>٧) استكشاف الأخطاء</b></summary>
        <div class="tblwrap" style="margin-top:6px">
            <table class="tbl">
                <thead><tr><th>العرَض</th><th>السبب الغالب</th><th>العلاج</th></tr></thead>
                <tbody>
                    <tr><td class="sub">تعذر الوصول (404/301)</td><td class="sub">الرابط ليس الجذر، أو الوكيل يحجب /jsonrpc</td><td class="sub">اكتب الجذر بلا مسار، واسمح بـ/jsonrpc في الوكيل</td></tr>
                    <tr><td class="sub">فشل الدخول</td><td class="sub">اسم قاعدةٍ خاطئ، أو كلمةُ مرورٍ مكانَ مفتاح API</td><td class="sub">تحقق من القاعدة، وولّد مفتاح API لا كلمة المرور</td></tr>
                    <tr><td class="sub">Access Denied على موديل</td><td class="sub">مستخدم القراءة بلا صلاحيةٍ عليه</td><td class="sub">أعطه قراءةَ المبيعات/المحاسبة في أودو</td></tr>
                    <tr><td class="sub">قناة «تعذّر» والبقية تعمل</td><td class="sub">موديل المميِّز غائب (قاعدة بلا تطبيق مبيعات/مواقع)</td><td class="sub">بدّل المميِّز لما هو مثبّت في تلك القاعدة</td></tr>
                    <tr><td class="sub">الاتصال الافتراضي فرغ فجأة</td><td class="sub">تبديل APP_KEY أفشل فكَّ تشفير المفتاح صامتاً</td><td class="sub">أعد إدخال مفتاح API في الإعدادات</td></tr>
                </tbody>
            </table>
        </div>
    </details>
</div>
@endsection
