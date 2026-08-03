<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** لقطة إصدار قالب توقيع — تُلتقط قبل كل تعديل جوهري فلا يضيع نصٌ قديم */
class SignTemplateVersion extends Model
{
    use HasUuid;

    public const UPDATED_AT = null;

    protected $table = 'sign_template_versions';
    protected $guarded = ['id'];
    protected $casts = ['created_at' => 'datetime'];
}
