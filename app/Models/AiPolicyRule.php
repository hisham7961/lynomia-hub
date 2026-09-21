<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * **صفُّ سياسةِ حوكمة** (المرحلة ٤ · P4-W1).
 *
 * **و`Auditable` هنا لازمٌ لا زينة:** كلُّ صفٍّ قرارُ إنسانٍ يوسّع أو يضيّق ما
 * يستطيع النظامُ إنفاقَه وبلوغَه. وسياسةٌ تُعدَّل بلا أثرٍ **حفرةٌ في
 * الحوكمة**: يُرفَع سقفٌ ليلاً ويُعاد صباحاً ولا يبقى منه خبر.
 */
class AiPolicyRule extends Model
{
    use HasUuid, Auditable, SoftDeletes;

    protected $table = 'ai_policies';
    public const MODULE = 'ai_policies';
    public const DISPLAY = 'label';

    protected $guarded = ['id'];

    protected $casts = [
        'allow_generation'      => 'boolean',
        'allow_tools'           => 'boolean',
        'enabled'               => 'boolean',
        'priority'              => 'integer',
        'max_output_tokens'     => 'integer',
        'max_calls_per_request' => 'integer',
    ];
}
