{{-- الحالةُ الفارغة (§22) — لا خيطَ مختار: ترحيبٌ يشرح المنتَجَ ويقود للفعل، لا فراغٌ صامت.
     كلُّ فعلٍ يفتح مساراً حقيقيّاً داخلَ المركز (لا وهمَ ميزةٍ) — فيفهم المبتدئُ الإمكاناتِ فوراً. --}}
<div class="card" style="text-align:center;padding:44px 24px">
    <div style="font-size:48px;line-height:1">💬</div>
    <h2 style="margin:12px 0 4px">مركزُ التواصلِ الموحّد</h2>
    <div class="sub" style="max-width:460px;margin:0 auto;line-height:1.9">
        القنواتُ والغرفُ والمجموعاتُ والرسائلُ المباشرةُ في مكانٍ واحد — اختر محادثةً من
        القائمةِ لتقرأها هنا، أو ابدأ واحدةً جديدة من الأزرار أدناه.
    </div>
    <div class="cx-emact">
        <a class="btn sm" href="{{ route('collab.center', ['new' => 'dm']) }}">✉️ ابدأ رسالة</a>
        <a class="btn sm" href="{{ route('collab.center', ['new' => 'group']) }}">👥 أنشئ مجموعة</a>
        <a class="btn sm" href="{{ route('collab.center', ['new' => 'channel']) }}"># أنشئ قناة</a>
        <a class="btn ghost sm" href="{{ route('conversations.directory') }}">🧭 اكتشف القنوات</a>
        <a class="btn ghost sm" href="{{ route('collab.attention') }}">🔔 عرض الإشارات</a>
        <a class="btn ghost sm" href="{{ route('saved.index') }}">🔖 عرض المحفوظات</a>
        <a class="btn ghost sm" href="{{ route('search.messages') }}">🔎 بحثُ الرسائل</a>
    </div>
</div>
<style>
.cx-emact { display:flex; flex-wrap:wrap; justify-content:center; gap:8px; margin-top:18px }
</style>
