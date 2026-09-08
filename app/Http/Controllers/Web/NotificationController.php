<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\HubNotification;

/** جرس التنبيهات الداخلية */
class NotificationController extends Controller
{
    /** مركز الإشعارات — الصفحة الكاملة */
    public function index()
    {
        $unread = request()->boolean('unread');
        // فاصل id: الكُتّاب الدفعيون (قواعد التنبيه، الموجز) يُنشئون عشرات الصفوف
        // في الثانية نفسها — ترقيمُ صفحاتٍ عليها وحدها يكرّر صفوفاً ويُسقط أخرى
        $q = HubNotification::where('user_id', auth()->id())
            ->orderByDesc('created_at')->orderByDesc('id');
        if ($unread) $q->where('read', false);

        return view('notifications.index', [
            'items'  => $q->paginate(30)->withQueryString(),
            'unread' => $unread,
        ]);
    }

    /**
     * عدُّ غير المقروء — نقطةٌ خفيفة تستفتيها الواجهة دوريّاً فتُحدِّث الشارة
     * وعنوانَ التبويب وتُطلق وميضَ الإطار، بلا إعادة تحميل الصفحة.
     */
    public function count()
    {
        return response()->json([
            'unread' => (int) HubNotification::where('user_id', auth()->id())->where('read', false)->count(),
            'flashMin' => max(1, (int) setting('notify.flash_min', 10)),
        ]);
    }

    public function mini()
    {
        $items = HubNotification::where('user_id', auth()->id())
            ->orderByDesc('created_at')->orderByDesc('id')->limit(12)->get();

        return view('partials.notifications_mini', ['items' => $items]);
    }

    /**
     * فتحُ إشعارٍ يُقرّئه وينقل لوجهته — لم يكن للإشعار المفرد مسارُ قراءةٍ
     * أصلاً: من فتح إشعاراته واحداً واحداً تبقى شارته غير صفرية حتى يضغط
     * «تحديد الكل» الذي يمسح حتى ما لم يُعرض عليه قط.
     */
    public function go(string $id)
    {
        $n = HubNotification::where('user_id', auth()->id())->findOrFail($id);
        if (! $n->read) $n->forceFill(['read' => true])->save();

        // الخريطةُ مُستخرَجةٌ إلى محلِّلٍ واحدٍ يشترك فيه الويبُ والجوال (E.2) — سلوكُ
        // الويبِ **غيرُ متغيّر**: `webUrl` تُنتج الرابطَ نفسَه الذي كان هنا حرفيّاً.
        return redirect(\App\Support\NotificationLink::webUrl($n));
    }

    public function readAll()
    {
        HubNotification::where('user_id', auth()->id())->where('read', false)->update(['read' => true]);

        // من الجرس (htmx) نعيد القائمة المصغرة، ومن صفحة المركز بلا JS نعود للصفحة
        return request()->headers->has('HX-Request') ? $this->mini() : back();
    }
}
