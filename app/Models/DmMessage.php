<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class DmMessage extends Model
{
    use HasUuid;

    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['read_at' => 'datetime', 'created_at' => 'datetime'];

    /** مفتاح المحادثة: معرّفا الطرفين مرتبَين — الثنائي نفسه دائماً نفس الخيط */
    public static function threadKey(string $a, string $b): string
    {
        return implode('-', collect([$a, $b])->sort()->values()->all());
    }
}
