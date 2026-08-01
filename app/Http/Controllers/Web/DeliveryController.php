<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Delivery;

/**
 * مسار التسليم — من الطلب إلى الإصدار في شاشةٍ واحدة.
 * الوحدات الستّ (الطلبات · المزايا · التصاميم · التحديثات · النشر · الاعتماديات)
 * كانت جداولَ لا يرى بعضها بعضاً، وهي مربوطةٌ بالحقول أصلاً.
 */
class DeliveryController extends Controller
{
    public function index()
    {
        // من يرى حلقةً واحدة يرى المسار — وكل قسمٍ فيه خلف صلاحيته
        abort_unless(
            hub_can(auth()->user(), 'feats', 'v') || hub_can(auth()->user(), 'deploys', 'v')
            || hub_can(auth()->user(), 'requests', 'v') || hub_can(auth()->user(), 'designs', 'v'),
            403, 'مسار التسليم يتطلب عرض إحدى وحداته');

        return view('delivery', [
            'lead'     => Delivery::leadTime(),
            'breaks'   => Delivery::breaks(),
            'cadence'  => Delivery::cadence(),
            'releases' => Delivery::releases(),
            'designs'  => Delivery::designs(),
        ]);
    }
}
