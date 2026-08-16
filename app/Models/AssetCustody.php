<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * قيدُ حيازةٍ على أصل: تسليمٌ أو استرداد، بتاريخه ومن نفّذه.
 *
 * `assets.holder_id` يقول من يحمل **الآن** وحسب؛ فإذا انتقلت العهدة ضاع من
 * حملها قبله ومتى سلّم — وهو بالضبط ما يُسأل عنه حين يُفقد جهازٌ أو يُترك.
 * هذا الجدول الأثر: صفٌّ لكل حركة، لا يُعاد كتابتُه.
 */
class AssetCustody extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $table = 'asset_custody';

    protected $guarded = ['id'];

    // التواريخُ الثلاثة مُكاستة معاً: كان `due`/`returned_at` نصّين بلا كاست،
    // فورقةُ التصريح تنادي toDateString() على نصٍّ وتسقط بخمسمئة عند أول تصريح.
    protected $casts = [
        'at' => 'date',
        'due' => 'date',
        'returned_at' => 'date',
        'meta' => 'array',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
