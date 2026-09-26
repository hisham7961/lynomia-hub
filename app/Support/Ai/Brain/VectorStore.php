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

    /** بصماتُ المقاطع المخزَّنة لسجلٍّ — لتخطّي ما لم يتغيّر @return array<string, string> "field#chunk" => hash */
    public function hashes(string $module, string $recordId): array;

    /** يمحو مقاطعَ سجلٍّ (كلَّها، أو ما عدا المفاتيحَ الحيّة "field#chunk") */
    public function forget(string $module, string $recordId, ?array $keep = null): int;

    /**
     * أقربُ المقاطع إلى المتّجه **ضمن الوحدات والشركات المعطاة فقط** — تضييقٌ لا حكم؛ الحكمُ عند المُنادي.
     *
     * @param  list<string>  $modules
     * @param  ?list<string>  $companies  `null` = بلا تضييق
     * @return array{hits: list<array{module:string, record_id:string, field:string, score:float}>, scanned:int, partial:bool}
     */
    public function nearest(array $vector, array $modules, ?array $companies, int $limit): array;
}
