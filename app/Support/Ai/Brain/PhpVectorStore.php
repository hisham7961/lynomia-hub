<?php

namespace App\Support\Ai\Brain;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **السائقُ «أ»: جدولٌ + جيبُ تمامٍ في PHP** — بلا أيِّ تغييرٍ في البنية، ويعمل على SQLite في الاختبارات.
 * يصلح حتى عشرات آلاف المقاطع؛ وما فوق `MAX_SCAN` يُعلَن جزئيّاً (`partial`) لا يُقصّ صامتاً.
 */
final class PhpVectorStore implements VectorStore
{
    public const MAX_SCAN = 50000;

    private const PAGE = 1000;

    /** حجمُ دفعة الحكم (استعلامُ نطاقٍ واحدٌ لكلِّ وحدةٍ في الدفعة) */
    private const JUDGE = 200;

    /** اسمُ الاتّصال: قاعدةُ العقل المستقلّة إن ضُبطت، وإلّا الرئيسة */
    public static function connection(): ?string
    {
        $c = config('database.brain_connection');

        return is_string($c) && $c !== '' ? $c : null;
    }

    private static function db(): \Illuminate\Database\Connection
    {
        return DB::connection(self::connection());
    }

    /**
     * **الجدولُ حاضرٌ على الاتّصال المضبوط؟** — وقاعدةٌ مستقلّةٌ لا تُبلَغ (متوقّفة · تُرقّى · اعتمادٌ خاطئ)
     * تعني «العقلُ غيرُ جاهز» لا انهياراً: فالقاعدةُ الاختياريّةُ لا تُسقط «اسأل Hub» ولا جولةَ الأتمتة.
     */
    public static function hasTable(): bool
    {
        try {
            return Schema::connection(self::connection())->hasTable('ai_embeddings');
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /** قاعدةُ SQLite مستقلّةٌ ملفُّها غائب؟ يُنشأ (فارغاً) — كي يعمل `hub:brain --setup` على تنصيبٍ جديد */
    public static function touchSqlite(?string $connection): void
    {
        if ($connection === null) return;
        $cfg = (array) config('database.connections.' . $connection, []);
        $path = (string) ($cfg['database'] ?? '');
        if (($cfg['driver'] ?? '') !== 'sqlite' || $path === '' || $path === ':memory:' || is_file($path)) return;
        @mkdir(dirname($path), 0755, true);
        @touch($path);
    }

    /**
     * **ينشئ الجدولَ إن غاب — على الاتّصال المضبوط.** مصدرُ المخطّط الواحد: تستدعيه الهجرةُ للقاعدة الرئيسة،
     * و`hub:brain --setup` للقاعدة المستقلّة. إضافيٌّ لا يمسّ جدولاً قائماً.
     */
    public static function ensureTable(?string $connection = null): bool
    {
        self::touchSqlite($connection);
        $schema = Schema::connection($connection);
        if ($schema->hasTable('ai_embeddings')) return false;
        $schema->create('ai_embeddings', function (Blueprint $t) {
            $t->id();
            $t->string('module', 40);
            $t->uuid('record_id');
            $t->string('field', 60);
            $t->unsignedSmallInteger('chunk')->default(0);
            $t->uuid('company_id')->nullable();
            $t->char('hash', 40);                        // sha1(النموذج|النصّ) — لا يُعاد تضمينُ ما لم يتغيّر
            $t->string('model', 191);
            $t->unsignedSmallInteger('dim');
            $t->binary('vector');
            $t->timestamps();
            $t->unique(['module', 'record_id', 'field', 'chunk']);
            $t->index(['module', 'company_id']);
        });

        return true;
    }

    /** معرّفاتُ السجلّات المفهرسة لوحدة — لمحو ما حُذف @return \Illuminate\Support\Collection<int, string> */
    public function recordIds(string $module)
    {
        return self::db()->table('ai_embeddings')->where('module', $module)->distinct()->orderBy('record_id')
            ->pluck('record_id')->map(fn ($x) => (string) $x);
    }

    /** مقاطعُ من فضاء نموذجٍ غيرِ هذا في هذه الوحدات؟ (تغطيةٌ ناقصةٌ تُعلَن) */
    public function hasOtherModel(array $modules, string $model): bool
    {
        return self::db()->table('ai_embeddings')->whereIn('module', $modules)->where('model', '!=', $model)->exists();
    }

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
            self::db()->table('ai_embeddings')->updateOrInsert(
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
        foreach (self::db()->table('ai_embeddings')->where('module', $module)->where('record_id', $recordId)
            ->orderBy('id')->get(['field', 'chunk', 'hash', 'company_id']) as $r) {
            $out[$r->field . '#' . $r->chunk] = ['hash' => (string) $r->hash,
                'company_id' => $r->company_id !== null ? (string) $r->company_id : null];
        }

        return $out;
    }

    public function retag(string $module, string $recordId, ?string $companyId): int
    {
        return self::db()->table('ai_embeddings')->where('module', $module)->where('record_id', $recordId)
            ->update(['company_id' => $companyId, 'updated_at' => now()]);
    }

    public function forget(string $module, string $recordId, ?array $keep = null): int
    {
        $n = 0;
        foreach (self::db()->table('ai_embeddings')->where('module', $module)->where('record_id', $recordId)
            ->orderBy('id')->get(['id', 'field', 'chunk']) as $r) {
            if ($keep !== null && in_array($r->field . '#' . $r->chunk, $keep, true)) continue;
            $n += self::db()->table('ai_embeddings')->where('id', $r->id)->delete();
        }

        return $n;
    }

    public function nearest(array $vector, array $modules, ?array $companies, string $model, int $limit, callable $accept): array
    {
        $q = self::norm($vector);
        if ($q === [] || $modules === [] || $model === '') return ['hits' => [], 'scanned' => 0, 'partial' => false];

        // ① كلُّ مرشَّحٍ يُقاس (المسحُ بالقوّة هو الكلفةُ أصلاً) — ولا قصَّ قبل الحكم
        $all = [];
        $scanned = 0;
        $partial = false;
        $lastId = 0;
        while (true) {
            $page = self::db()->table('ai_embeddings')->whereIn('module', $modules)->where('dim', count($q))->where('model', $model)
                ->when($companies !== null, fn ($w) => $w->where(fn ($x) => $x->whereIn('company_id', $companies)->orWhereNull('company_id')))
                ->where('id', '>', $lastId)->orderBy('id')->limit(self::PAGE)
                ->get(['id', 'module', 'record_id', 'field', 'vector']);
            if ($page->isEmpty()) break;
            foreach ($page as $r) {
                $lastId = (int) $r->id;
                $v = self::unpack((string) $r->vector);
                $s = 0.0;
                foreach ($q as $i => $x) $s += $x * ($v[$i] ?? 0.0);
                $all[] = ['module' => (string) $r->module, 'record_id' => (string) $r->record_id,
                    'field' => (string) $r->field, 'score' => $s];
                if (++$scanned >= self::MAX_SCAN) { $partial = true; break 2; }
            }
        }
        usort($all, fn ($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['record_id'], $b['record_id']) ?: strcmp($a['field'], $b['field']));

        // ② الحكمُ دفعاتٍ بترتيب القرب حتى يجتمع المطلوب من سجلّاتٍ مقبولة
        $hits = [];
        $records = [];
        foreach (array_chunk($all, self::JUDGE) as $batch) {
            foreach ($accept($batch) as $h) {
                $hits[] = $h;
                $records[$h['module'] . ':' . $h['record_id']] = true;
            }
            if (count($records) >= $limit) break;
        }

        return ['hits' => $hits, 'scanned' => $scanned, 'partial' => $partial];
    }

    public static function norm(array $v): array
    {
        $n = sqrt(array_sum(array_map(fn ($x) => (float) $x * (float) $x, $v)));

        return $n > 0 ? array_map(fn ($x) => (float) $x / $n, array_values($v)) : [];
    }
}
