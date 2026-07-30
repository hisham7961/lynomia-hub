<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    /** افتراضيات آمنة بدل default على أعمدة JSON (غير مدعومة في MySQL) */
    protected $attributes = ['flags' => '{}', 'matrix' => '{}'];

    use HasUuid;

    protected $guarded = ['id'];
    protected $casts = ['flags' => 'array', 'matrix' => 'array', 'is_owner' => 'boolean', 'field_rules' => 'array'];

    public function users()
    {
        return $this->hasMany(\App\Models\User::class, 'role_id');
    }
}
