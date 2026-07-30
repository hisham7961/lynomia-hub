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
        $q = HubNotification::where('user_id', auth()->id())->orderByDesc('created_at');
        if ($unread) $q->where('read', false);

        return view('notifications.index', [
            'items'  => $q->paginate(30)->withQueryString(),
            'unread' => $unread,
        ]);
    }

    public function mini()
    {
        $items = HubNotification::where('user_id', auth()->id())
            ->orderByDesc('created_at')->limit(12)->get();

        return view('partials.notifications_mini', ['items' => $items]);
    }

    public function readAll()
    {
        HubNotification::where('user_id', auth()->id())->where('read', false)->update(['read' => true]);

        // من الجرس (htmx) نعيد القائمة المصغرة، ومن صفحة المركز بلا JS نعود للصفحة
        return request()->headers->has('HX-Request') ? $this->mini() : back();
    }
}
