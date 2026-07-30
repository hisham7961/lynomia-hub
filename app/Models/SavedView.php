<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class SavedView extends Model
{
    use HasUuid;

    protected $guarded = ['id'];
    protected $casts = ['is_default' => 'boolean'];

    /** رابط تطبيق العرض: قائمة الوحدة بسلسلة الاستعلام المخزنة + مُعرّف العرض */
    public function url(): string
    {
        return route('m.index', $this->module)
            . '?' . ($this->query ? $this->query . '&' : '') . 'view=' . $this->id;
    }
}
