<?php

namespace Tests\Support;

use App\Models\PushDelivery;
use App\Support\Push\PushProvider;
use App\Support\Push\PushSendResult;

/**
 * **مزوّدُ دفعٍ مزيّفٌ للاختبار** (spec §Push: «fake provider for tests»).
 *
 * يُحقن عبر الحاوية `app()->instance(PushProvider::class, new FakePushProvider(...))`
 * فيلتقطه `PushService::provider()`. يلتقط الحمولاتِ المُرسَلة (للتأكّد أنّها آمنة
 * · spec §Push privacy)، ويحاكي: تسليماً، أو فشلاً بصنفٍ تقنيّ، أو **استثناءً**
 * (للتأكّد أنّ الإشعارَ الداخليّ ينجو · Critic F6). لا يتّصل بشبكةٍ قط.
 */
class FakePushProvider implements PushProvider
{
    /** @var array<int,array{token:string,platform:string,payload:array}> ما أُرسِل */
    public array $sent = [];

    public function __construct(
        private string $mode = 'deliver',      // deliver | fail | throw
        private string $providerName = 'fcm',
        private ?string $failCategory = null,
    ) {
    }

    public function name(): string
    {
        return $this->providerName;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $token, string $platform, array $payload): PushSendResult
    {
        $this->sent[] = ['token' => $token, 'platform' => $platform, 'payload' => $payload];

        return match ($this->mode) {
            'throw'  => throw new \RuntimeException('provider boom'),
            'fail'   => PushSendResult::failed($this->failCategory ?? PushDelivery::ERR_PROVIDER_ERROR),
            default  => PushSendResult::delivered(),
        };
    }
}
