<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class DmMessage extends Model
{
    use HasUuid;

    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['read_at' => 'datetime', 'created_at' => 'datetime', 'deleted_at' => 'datetime', 'edited_at' => 'datetime'];

    /**
     * الرسائل الحيّة — والمحذوفةُ تبقى صفّاً يُقرأ منه «حُذفت رسالة».
     * حذفٌ من الشاشة لا من التاريخ: المحادثةُ المبتورةُ بلا تفسيرٍ أسوأُ من أثرٍ يقول ماذا جرى.
     */
    public function scopeAlive($q)
    {
        // العمودُ حديث: نشرٌ سبق هجرتَه لا يجوز أن يُسقط ما يقرأ الرسائل —
        // تنقص ميزةُ «إخفاء المسحوب» ولا يُطفأ شيء
        return hub_has_col('dm_messages', 'deleted_at') ? $q->whereNull('deleted_at') : $q;
    }

    /**
     * (WP-A.5 · SF-4/SF-5) نطاقُ الشركة على الرسائل — على السكّة نفسِها
     * (`hub_company_ids`) لا محرّكَ عزلٍ ثانٍ: المستخدمُ المقيَّدُ بشركاتٍ يرى
     * رسائلَ شركاته والرسائلَ غيرَ الموسومة (`company_id` فارغ: محادثةُ الإدارة
     * غيرِ المقيَّدة، أو محادثةٌ قديمةٌ قبل الهجرة)؛ غيرُ المقيَّد يرى الكلَّ كما كان.
     * والعمودُ حديث: قبل الهجرة لا يُسقط شيئاً — نظيرُ `scopeAlive` تماماً.
     */
    public function scopeInCompanyScope($q, $user = null)
    {
        if (! hub_has_col('dm_messages', 'company_id')) return $q;    // ما قبل الهجرة
        if (($cids = hub_company_ids($user)) === null) return $q;      // غيرُ مقيَّد يرى الكل

        return $q->where(fn ($w) => $w->whereIn('company_id', $cids)->orWhereNull('company_id'));
    }

    /** مفتاح المحادثة: معرّفا الطرفين مرتبَين — الثنائي نفسه دائماً نفس الخيط */
    public static function threadKey(string $a, string $b): string
    {
        return implode('-', collect([$a, $b])->sort()->values()->all());
    }

    /**
     * (WP-C.4 · §6/§7) هويّةُ حاويةِ المحادثة المشتقّةُ حتميّاً من `thread_key`.
     *
     * uuid5 (اسميّةٌ لا عشوائيّة): المفتاحُ نفسُه يُنتج الهويّةَ نفسَها دائماً —
     * فـ`thread_key` و`conversation_id` يحلّان إلى **حاويةٍ واحدة** لا هويّتَين.
     * وبما أنها المفتاحُ الأساس (PK) لجدول `conversations`، تفرض الوحدانيّةَ حتى
     * تحت التسابق: إرسالان أوّلان متزامنان يحسبان الهويّةَ عينَها فيفوز أحدُهما
     * والآخرُ يعيد استعمالَها؛ ولا مسارَ يخلق حاويتَين للثنائيّ الواحد. تُستدعى من
     * الكاتب الحيّ (`DmController`) ومن هجرةِ التعبئة معاً فتُقفَل الحتميّةُ بينهما.
     */
    public static function conversationIdForThread(string $threadKey): string
    {
        return \Ramsey\Uuid\Uuid::uuid5(
            \Ramsey\Uuid\Uuid::NAMESPACE_URL, 'lynomia:dm:' . $threadKey
        )->toString();
    }
}
