<?php

namespace App\Support\Ai\Brain;

use Illuminate\Support\Facades\DB;

/**
 * **السائقُ «أ»: جدولٌ + جيبُ تمامٍ في PHP** — بلا أيِّ تغييرٍ في البنية، ويعمل على SQLite في الاختبارات.
 * يصلح حتى عشرات آلاف المقاطع؛ وما فوق `MAX_SCAN` يُعلَن جزئيّاً (`partial`) لا يُقصّ صامتاً.
 */
final class PhpVectorStore implements VectorStore
{
    public const MAX_SCAN = 50000;

    private const PAGE = 1000;

    public static function pack(array $v): string
    {
        return pack('g*', ...array_map('floatval', $v));
    }

    /** @return list<float> */
    public static function unpack(string $bin): array
    {
        return array_values(unpack('g*', $bin) ?: []);
    }

    public function upsert(array $rows): void
    {
        $now = now();
        foreach ($rows as $r) {
            DB::table('ai_embeddings')->updateOrInsert(
                ['module' => $r['module'], 'record_id' => $r['record_id'], 'field' => $r['field'], 'chunk' => (int) $r['chunk']],
                ['company_id' => $r['company_id'], 'hash' => $r['hash'], 'model' => $r['model'],
                 // يُطبَّع عند الكتابة — فالبحثُ ضربٌ نقطيٌّ واحد
                 'dim' => count($r['vector']), 'vector' => self::pack(self::norm($r['vector'])), 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }

    public function hashes(string $module, string $recordId): array
    {
        $out = [];
        foreach (DB::table('ai_embeddings')->where('module', $module)->where('record_id', $recordId)
            ->orderBy('id')->get(['field', 'chunk', 'hash']) as $r) {
            $out[$r->field . '#' . $r->chunk] = (string) $r->hash;
        }

        return $out;
    }

    public function forget(string $module, string $recordId, ?array $keep = null): int
    {
        $n = 0;
        foreach (DB::table('ai_embeddings')->where('module', $module)->where('record_id', $recordId)
            ->orderBy('id')->get(['id', 'field', 'chunk']) as $r) {
            if ($keep !== null && in_array($r->field . '#' . $r->chunk, $keep, true)) continue;
            $n += DB::table('ai_embeddings')->where('id', $r->id)->delete();
        }

        return $n;
    }

    public function nearest(array $vector, array $modules, ?array $companies, int $limit): array
    {
        $q = self::norm($vector);
        if ($q === [] || $modules === []) return ['hits' => [], 'scanned' => 0, 'partial' => false];

        $heap = [];
        $scanned = 0;
        $partial = false;
        $lastId = 0;
        while (true) {
            $page = DB::table('ai_embeddings')->whereIn('module', $modules)->where('dim', count($q))
                ->when($companies !== null, fn ($w) => $w->where(fn ($x) => $x->whereIn('company_id', $companies)->orWhereNull('company_id')))
                ->where('id', '>', $lastId)->orderBy('id')->limit(self::PAGE)
                ->get(['id', 'module', 'record_id', 'field', 'vector']);
            if ($page->isEmpty()) break;
            foreach ($page as $r) {
                $lastId = (int) $r->id;
                $v = self::unpack((string) $r->vector);
                $s = 0.0;
                foreach ($q as $i => $x) $s += $x * ($v[$i] ?? 0.0);
                $heap[] = ['module' => (string) $r->module, 'record_id' => (string) $r->record_id,
                    'field' => (string) $r->field, 'score' => $s];
                if (++$scanned >= self::MAX_SCAN) { $partial = true; break 2; }
            }
            if (count($heap) > $limit * 4) {
                usort($heap, fn ($a, $b) => $b['score'] <=> $a['score']);
                $heap = array_slice($heap, 0, $limit);
            }
        }
        usort($heap, fn ($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['record_id'], $b['record_id']));

        return ['hits' => array_slice($heap, 0, $limit), 'scanned' => $scanned, 'partial' => $partial];
    }

    /** متّجهٌ بطولِ الوحدة — فالضربُ النقطيُّ جيبُ تمام (المخزَّنُ مُطبَّعٌ عند الكتابة) @return list<float> */
    public static function norm(array $v): array
    {
        $n = sqrt(array_sum(array_map(fn ($x) => (float) $x * (float) $x, $v)));

        return $n > 0 ? array_map(fn ($x) => (float) $x / $n, array_values($v)) : [];
    }
}
