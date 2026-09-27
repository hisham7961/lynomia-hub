<?php

namespace App\Support\Ai\Auditor;

use App\Models\User;

/**
 * **كاشفٌ واحدٌ من كواشفِ المدقّق.**
 *
 * يقرأ بهويّةِ المدقّق (`AuditorIdentity`) عبر `hub_scope` — لا طريقَ جانبيّاً —
 * ويُعيد نتائجَ بالشكل الذي يحفظه `Auditor`:
 *
 *     ['severity' => high|medium|info, 'subject_module' => …, 'subject_id' => …,
 *      'subject_user_id' => ?, 'company_id' => ?,
 *      'evidence' => [['module' => …, 'id' => …], …],   // كلُّ سجلٍّ بُنيت عليه النتيجة
 *      'fields'   => [module => [fieldKey, …]],          // كلُّ حقلٍ قرأه وقد يظهر في الملخّص
 *      'summary'  => '…', 'suggestion' => ?, 'input' => mixed,  // input: ما تُبنى منه البصمة
 *      'identity' => [...]]                                  // هويّةُ الشرط ⇒ مفتاحٌ ثابت (افتراضُه الموضوع)
 *
 * **والشاهدُ والحقولُ ليسا توثيقاً بل حدَّ رؤية:** العرضُ يُعاد تنطيقُه عليهما للمشاهد.
 * فكاشفٌ يذكر في ملخّصه حقلاً لم يُعلنه في `fields` يُسرّبه — ولذلك يُعلَن كلُّ ما يُقرأ.
 */
interface Detector
{
    /** مفتاحٌ ثابتٌ ≤ ٤٠ حرفاً — جزءٌ من مفتاحِ الإشارة، فلا يُغيَّر بعد الإطلاق */
    public function key(): string;

    /** `rule` (حسابٌ بلا نموذج) أو `ai` (نداءٌ محكومٌ مدفوع) */
    public function source(): string;

    /** اسمٌ عربيٌّ يُعرض للمشاهد */
    public function label(): string;

    /** @return list<array> */
    public function detect(User $auditor): array;

    /**
     * **هل غطّت آخرُ جولةٍ كلَّ ما يقع تحت نظرِها؟** — `false` حين توقّف الكاشفُ قبل أن
     * يرى كلَّ موضوعاته (ميزانيّةٌ نفدت، سقفُ نداءات، بوّابةٌ غائبة): فغيابُ نتيجةٍ عندها
     * لا يعني «زال الشرط»، ولا يُحَلُّ بها شيء.
     */
    public function complete(): bool;
}
