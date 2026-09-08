<?php

namespace App\Support\Push;

/**
 * **مزوّدُ FCM** — نداءُ Firebase Cloud Messaging الحقيقيّ، **محجوبٌ خلف وجودِ
 * الاعتمادات** (Mobile Readiness · الطور E · spec §Push).
 *
 * لا يُبنى إلا حين يقول `PushService::provider()` إنّ السائقَ `fcm` والاعتماداتِ
 * حاضرة؛ وإلّا فالمزوّدُ `NullPushProvider`. الاعتماداتُ الحقيقيّة (حسابُ الخدمة/
 * `project_id`/رمزُ الوصول) **إعدادٌ خارجيٌّ** يُوثَّق ولا يُختلَق — فبلا رمزِ وصولٍ
 * صالحٍ يقول `send` الحقيقةَ (`not_configured`) لا نجاحاً مُزيَّفاً.
 *
 * **الشبكةُ محجوبة:** نداءُ HTTP لا يقع إلا حين `isConfigured()`؛ ويُغلَّف كي لا
 * يطلق استثناءً (الفشلُ المتوقَّع ⇒ `failed(...)` بصنفٍ تقنيّ، لا تسريبَ رسالةِ
 * مزوّدٍ خام). التسليمُ delivered عند 2xx فقط — لا افتراضَ نجاح.
 *
 * **لا سرَّ في الحمولة (spec §Push privacy):** يُرسِل ما بناه `PushService` فقط —
 * عنوانٌ عامٌّ + تصنيفٌ + رابطٌ عميق + عددُ غير المقروء.
 */
class FcmPushProvider implements PushProvider
{
    /**
     * @param array $creds ['project_id'=>?, 'access_token'=>?] — إعدادٌ خارجيٌّ (لا يُختلَق)
     */
    public function __construct(private array $creds = [])
    {
    }

    public function name(): string
    {
        return 'fcm';
    }

    /** مُهيّأٌ = مشروعٌ + رمزُ وصولٍ حاضران (لا نُرسِل بلا اعتمادٍ صالح) */
    public function isConfigured(): bool
    {
        return trim((string) ($this->creds['project_id'] ?? '')) !== ''
            && trim((string) ($this->creds['access_token'] ?? '')) !== '';
    }

    public function send(string $token, string $platform, array $payload): PushSendResult
    {
        // بلا اعتمادٍ صالح: الحقيقةُ لا التزييف (spec §Push) — الشبكةُ محجوبة
        if (! $this->isConfigured()) {
            return PushSendResult::notConfigured();
        }

        try {
            $project = trim((string) $this->creds['project_id']);
            $res = \Illuminate\Support\Facades\Http::withToken((string) $this->creds['access_token'])
                ->timeout(8)
                ->post("https://fcm.googleapis.com/v1/projects/{$project}/messages:send", [
                    'message' => [
                        'token' => $token,
                        // إشعارٌ عامٌّ آمن — بناه PushService بلا نصٍّ حسّاس
                        'notification' => [
                            'title' => (string) ($payload['title'] ?? ''),
                            'body'  => (string) ($payload['body'] ?? ''),
                        ],
                        // البياناتُ نصوصٌ لدى FCM — الرابطُ العميقُ والعدّاد لا أكثر
                        'data' => array_map('strval', (array) ($payload['data'] ?? [])),
                    ],
                ]);

            if ($res->successful()) {
                return PushSendResult::delivered();
            }

            // تصنيفٌ تقنيٌّ من رمز الحالة — لا رسالةَ مزوّدٍ خام في السجلّ
            return PushSendResult::failed(match (true) {
                $res->status() === 404 || $res->status() === 410 => \App\Models\PushDelivery::ERR_UNREGISTERED,
                $res->status() === 400                           => \App\Models\PushDelivery::ERR_INVALID_TOKEN,
                $res->status() === 429                           => \App\Models\PushDelivery::ERR_RATE_LIMITED,
                default                                          => \App\Models\PushDelivery::ERR_PROVIDER_ERROR,
            });
        } catch (\Throwable $e) {
            // عطلٌ غيرُ متوقَّع (شبكة/مهلة) — صنفٌ تقنيٌّ لا نصُّ الاستثناء
            return PushSendResult::failed(\App\Models\PushDelivery::ERR_PROVIDER_ERROR);
        }
    }
}
