<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * **سقالةُ الروابطِ العالميّة (Universal Links / App Links)** — Mobile Readiness ·
 * الطور H · H.3.
 *
 * يخدم وثيقتَي الربط عند جذر النطاق (حيث تفرضهما Apple/Google):
 *  • `GET /.well-known/apple-app-site-association`
 *  • `GET /.well-known/assetlinks.json`
 *
 * **صادقٌ لا مُختلَق (spec §Deep links):** المعرّفاتُ الخارجيّة — معرّفُ الفريق (Apple
 * Team ID)، ومُعرّفُ الحزمة (bundle id)، واسمُ حزمةِ Android، وبصماتُ شهادةِ التوقيع
 * (SHA-256) — **NOT_CONFIGURED** حتى يُصدرها فريقُ التطبيق. حتى ذلك الحين تُخدَم
 * وثيقةٌ **صحيحةُ البنية تربط صفرَ تطبيق** (لا رابطَ عالميٌّ يعمل — لا نُختلق ربطاً)
 * مع ترويسة `X-Deep-Links-Status: NOT_CONFIGURED`. تُملأ المعرّفاتُ من
 * `config('hub.mobile.deep_links')` (أو `setting('mobile.dl_*')`) فتُخدَم الوثيقةُ
 * الحقيقيّةُ تلقائيّاً — بلا نشرِ كودٍ جديد.
 *
 * الوجهةُ التي يلتقطها التطبيق `/m/*` هي ترميزُ الرابطِ العميق القانونيّ
 * `{module,id,action}` (‏`App\Support\NotificationLink` · x-deep-link في مواصفة الجوال).
 */
class MobileWellKnownController extends Controller
{
    /** GET /.well-known/apple-app-site-association — AASA (JSON، بلا إعادةِ توجيه) */
    public function appleAppSiteAssociation(): JsonResponse
    {
        $cfg = self::config();
        $appIds = [];
        if ($cfg['apple_team_id'] !== '' && $cfg['apple_bundle_id'] !== '') {
            $appIds[] = $cfg['apple_team_id'] . '.' . $cfg['apple_bundle_id'];
        }

        $components = array_map(fn ($p) => ['/' => $p, 'comment' => 'وجهةُ الرابطِ العميق {module,id,action}'], $cfg['paths']);

        $body = [
            'applinks' => [
                // details فارغةٌ حتى تُضبط المعرّفات ⇒ لا تطبيقَ مربوط (صدقُ NOT_CONFIGURED)
                'details' => $appIds ? [[
                    'appIDs' => $appIds,
                    'components' => $components,
                ]] : [],
            ],
        ];
        if ($appIds) {
            $body['webcredentials'] = ['apps' => $appIds];   // لملءِ كلمة المرور التلقائيّ (اختياريّ)
        } else {
            // مفتاحٌ إضافيٌّ صريحُ الحالة — Apple تتجاهل المفاتيحَ غيرَ المعروفة (لا يكسر التحقّق)
            $body['x-lynomia'] = [
                'status' => 'NOT_CONFIGURED',
                'needs' => ['apple.team_id', 'apple.bundle_id'],
                'configure' => "config('hub.mobile.deep_links.apple') أو setting('mobile.dl_apple_team_id'|'mobile.dl_apple_bundle_id')",
            ];
        }

        return self::json($body, $appIds !== []);
    }

    /** GET /.well-known/assetlinks.json — Android App Links (مصفوفةُ بياناتٍ) */
    public function assetLinks(): JsonResponse
    {
        $cfg = self::config();
        $configured = $cfg['android_package'] !== '' && ! empty($cfg['android_fingerprints']);

        // مصفوفةٌ فارغةٌ حتى تُضبط المعرّفات ⇒ لا تطبيقَ مربوط (صدقُ NOT_CONFIGURED) —
        // assetlinks بنيتُها مصفوفةٌ صارمة فلا نُقحم مفتاحَ حالةٍ فيها؛ الحالةُ في الترويسة.
        $body = $configured ? [[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => $cfg['android_package'],
                'sha256_cert_fingerprints' => array_values($cfg['android_fingerprints']),
            ],
        ]] : [];

        return self::json($body, $configured);
    }

    // ══════════════════════════ مساعِداتٌ داخلية ══════════════════════════

    /**
     * حلُّ إعدادات الربط: `setting('mobile.dl_*')` فوق `config('hub.mobile.deep_links')`.
     * كلُّها **NOT_CONFIGURED افتراضاً** (فارغة) — لا معرّفَ مُختلَق.
     *
     * @return array{serve:bool,paths:array<int,string>,apple_team_id:string,apple_bundle_id:string,android_package:string,android_fingerprints:array<int,string>}
     */
    private static function config(): array
    {
        $c = (array) config('hub.mobile.deep_links', []);
        $s = fn (string $key, $default) => self::str(setting($key, $default));

        $paths = (array) data_get($c, 'paths', ['/m/*', '/app/*']);
        $paths = array_values(array_filter(array_map(fn ($p) => self::str($p, ''), $paths), fn ($p) => $p !== ''));

        $fps = (array) data_get($c, 'android.sha256_cert_fingerprints', []);
        // إعدادٌ حيٌّ اختياريّ: بصماتٌ مفصولةٌ بفاصلة في setting('mobile.dl_android_fingerprints')
        $live = self::str(setting('mobile.dl_android_fingerprints', ''), '');
        if ($live !== '') $fps = preg_split('/[,\s]+/', $live, -1, PREG_SPLIT_NO_EMPTY);
        $fps = array_values(array_filter(array_map(fn ($f) => self::str($f, ''), (array) $fps), fn ($f) => $f !== ''));

        return [
            'serve' => (bool) data_get($c, 'serve', true),
            'paths' => $paths ?: ['/m/*'],
            'apple_team_id' => $s('mobile.dl_apple_team_id', data_get($c, 'apple.team_id', '')),
            'apple_bundle_id' => $s('mobile.dl_apple_bundle_id', data_get($c, 'apple.bundle_id', '')),
            'android_package' => $s('mobile.dl_android_package', data_get($c, 'android.package_name', '')),
            'android_fingerprints' => $fps,
        ];
    }

    private static function str($v, $fallback = ''): string
    {
        $v = trim((string) ($v ?? ''));

        return $v !== '' ? $v : trim((string) $fallback);
    }

    /** ردُّ JSON للوثيقة — بلا إعادةِ توجيه، بترويسةِ حالةٍ صادقة، وتخبئةٍ قصيرة */
    private static function json(array $body, bool $configured): JsonResponse
    {
        $serve = (bool) data_get((array) config('hub.mobile.deep_links', []), 'serve', true);
        abort_unless($serve, 404);

        return response()->json($body, 200, [
            'Content-Type' => 'application/json',
            'X-Deep-Links-Status' => $configured ? 'CONFIGURED' : 'NOT_CONFIGURED',
            'Cache-Control' => 'public, max-age=300',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
