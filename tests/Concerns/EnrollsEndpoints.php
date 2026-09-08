<?php

namespace Tests\Concerns;

use App\Models\Asset;
use App\Models\Company;
use Illuminate\Support\Str;

/**
 * مساعِدُ اختبارٍ للتصحيح §2: التسجيلُ صار مربوطاً بأصلٍ مملوكٍ للشركة، فكلُّ سكٍّ
 * يحتاج أصلاً مؤهّلاً. هذا يبني أصلاً افتراضيّاً مؤهّلاً (مملوكٌ للشركة، حالةٌ متاح).
 */
trait EnrollsEndpoints
{
    /** أصلٌ مملوكٌ للشركة مؤهّلٌ لتسجيلِ نقطةٍ طرفيّة — holder اختياريٌّ يصير حاملَ الجهاز */
    protected function eligibleAsset(Company $c, ?string $holderId = null, array $over = []): Asset
    {
        return Asset::create(array_merge([
            'name' => 'جهاز-' . Str::random(5),
            'company_id' => $c->id,
            'holder_id' => $holderId,
            'owner_scope' => 'لينوميا',   // مملوكٌ للشركة (مؤهّل)
            'status' => 'متاح',           // حالةٌ مؤهّلة
        ], $over));
    }
}
