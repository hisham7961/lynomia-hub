<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;

/** مركز الباقات والتسعير — بطاقاتٌ وهوامشُ ومقارنةُ سوق */
class PricingController extends Controller
{
    public function index()
    {
        abort_unless(hub_can(auth()->user(), 'plans', 'v'), 403);

        return view('pricing');
    }
}
