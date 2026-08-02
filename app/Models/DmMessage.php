<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class DmMessage extends Model
{
    use HasUuid;

    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['read_at' => 'datetime', 'created_at' => 'datetime', 'deleted_at' => 'datetime'];

    /**
     * الرسائل الحيّة — والمحذوفةُ تبقى صفّاً يُقرأ منه «حُذفت رسالة».
     * حذفٌ من الشاشة لا من التاريخ: المحادثةُ المبتورةُ بلا تفسيرٍ أسوأُ من أثرٍ يقول ماذا جرى.
     */
    public function scopeAlive($q)
    {
        return $q->whereNull('deleted_at');
    }

    /** مفتاح المحادثة: معرّفا الطرفين مرتبَين — الثنائي نفسه دائماً نفس الخيط */
    public static function threadKey(string $a, string $b): string
    {
        return implode('-', collect([$a, $b])->sort()->values()->all());
    }
}
