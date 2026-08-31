<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * معرّفٌ على سجل — سكّة (module, record_id) متعددة الأشكال كما المرفقات.
 *
 * صفٌّ لكل معرّف (GTIN، سيريال، MPN، كود Lynomia، اسمٌ بديل بعد دمج…) بدل
 * عمودٍ لكل نوع: نوعٌ جديد غداً (RFID/NFC) صفٌّ لا هجرة. `norm` هو المفتاح
 * الحقيقي — القيمةُ مُطبَّعةً للبحث والتفرّد — و`value` كما أُدخلت للعرض.
 */
class RecordIdentifier extends Model
{
    use HasUuid;

    protected $table = 'record_identifiers';
    protected $guarded = ['id'];
    protected $casts = ['meta' => 'array', 'is_primary' => 'boolean', 'verified' => 'boolean'];
}
