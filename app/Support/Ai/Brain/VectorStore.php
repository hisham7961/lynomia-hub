<?php

namespace App\Support\Ai\Brain;

/**
 * **مخزنُ المتّجهات — واجهةٌ واحدةٌ بسائقين** (§٦.١): الميزةُ لا تعرف أين تُخزَّن المتّجهات.
 * السائقُ الحاليّ `PhpVectorStore` (جدولٌ + جيبُ تمامٍ في PHP)؛ و`VECTOR` في MariaDB 11.8 سائقٌ لاحق.
 */
interface VectorStore
{
    /** @param list<array{module:string, record_id:string, field:string, chunk:int, company_id:?string, hash:string, model:string, vector:list<float>}> $rows */
    public function upsert(array $rows): void;

    /** بصماتُ المقاطع المخزَّنة وشركتُها — لتخطّي ما لم يتغيّر @return array<string, array{hash:string, company_id:?string}> "field#chunk" => … */
    public function hashes(string $module, string $recordId): array;

    /** يُحدّث شركةَ مقاطع سجلٍّ نُقل — بلا إعادة تضمين (النصُّ لم يتغيّر) */
    public function retag(string $module, string $recordId, ?string $companyId): int;

    /** يمحو مقاطعَ سجلٍّ (كلَّها، أو ما عدا المفاتيحَ الحيّة "field#chunk") */
    public function forget(string $module, string $recordId, ?array $keep = null): int;

    /**
     * أقربُ **السجلّات** إلى المتّجه — ضمن الوحدات والشركات المعطاة، **ومن فضاء النموذج نفسِه** (متّجهاتُ نموذجين
     * لا تُقارَن ولو تساوى البُعد). والحكمُ **أثناء المسح لا بعد القصّ**: المرشَّحون يُعرَضون على `$accept` دفعاتٍ
     * بترتيب القرب حتى يجتمع `$limit` سجلّاً مقبولاً أو ينفد المخزن — فما لا يراه القارئُ لا يزاحم ما يراه.
     *
     * @param  list<string>  $modules
     * @param  ?list<string>  $companies  `null` = بلا تضييق
     * @param  callable(list<array{module:string, record_id:string, field:string, score:float}>): list<array{module:string, record_id:string, field:string, score:float}>  $accept
     * @return array{hits: list<array{module:string, record_id:string, field:string, score:float}>, scanned:int, partial:bool}
     */
    public function nearest(array $vector, array $modules, ?array $companies, string $model, int $limit, callable $accept): array;
}
