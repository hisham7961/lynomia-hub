<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\HubNotification;

/** جرس التنبيهات الداخلية */
class NotificationController extends Controller
{
    public function mini()
    {
        $items = HubNotification::where('user_id', auth()->id())
            ->orderByDesc('created_at')->limit(12)->get();

        return view('partials.notifications_mini', ['items' => $items]);
    }

    public function readAll()
    {
        HubNotification::where('user_id', auth()->id())->where('read', false)->update(['read' => true]);

        return $this->mini();
    }
}
