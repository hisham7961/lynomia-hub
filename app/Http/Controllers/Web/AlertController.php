<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** رادار «ينتهي قريباً»: كل تواريخ الانتهاء والتجديد والاستحقاق عبر الوحدات كلها */
class AlertController extends Controller
{
    public function index(Request $r)
    {
        $items = collect(hub_expiry($r->boolean('fresh')))
            ->filter(fn ($i) => hub_can(auth()->user(), $i['module'], 'v'))
            ->values();

        $late = $items->filter(fn ($i) => $i['days'] < 0);
        $week = $items->filter(fn ($i) => $i['days'] >= 0 && $i['days'] <= 7);
        $month = $items->filter(fn ($i) => $i['days'] > 7);

        return view('alerts.index', compact('late', 'week', 'month'));
    }
}
