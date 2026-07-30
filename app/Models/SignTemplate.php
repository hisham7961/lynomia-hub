<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** قالب عقد للتوقيع الإلكتروني — نصٌّ بمتغيرات {var} تُملأ عند الإنشاء */
class SignTemplate extends Model
{
    use HasUuid;

    protected $table = 'sign_templates';
    protected $guarded = ['id'];

    /** المتغيرات المكتشفة في النص — تُبنى منها حقول نموذج الإنشاء تلقائياً */
    public function vars(): array
    {
        preg_match_all('/\{([^{}\s]{1,40})\}/u', $this->body, $m);

        return array_values(array_unique($m[1]));
    }
}
