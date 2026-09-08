<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\MobileOpenApi;
use Illuminate\Http\JsonResponse;

/**
 * **مواصفةُ الجوال الحيّة** — Mobile Readiness · الطور H · H.2.
 *
 * `GET /api/mobile/v1/openapi.json` — وثيقةُ OpenAPI 3.1 **مولَّدةٌ من المسارات
 * الحيّة** (`MobileOpenApi::spec()`) لا مكتوبةٌ باليد، فلا تتقادم. **عامّةٌ** (لا رمزَ
 * وصول): كي يقرأها التطبيقُ وأدواتُ التوليدِ قبل الدخول. وثيقةٌ **منفصلةٌ تماماً** عن
 * `/api/v1/openapi.json` — لا تمسّه ولا `docs/openapi.json`.
 */
class MobileDocsController extends Controller
{
    /** المواصفةُ الحيّة — JSON بترميزٍ عربيٍّ سليمٍ (نظيرُ V1Controller::openapi) */
    public function openapi(): JsonResponse
    {
        return response()->json(MobileOpenApi::spec(), 200, [
            'X-API-Version' => \App\Support\Api::VERSION,
            'Cache-Control' => 'public, max-age=300',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
