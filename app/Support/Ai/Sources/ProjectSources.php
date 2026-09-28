<?php

namespace App\Support\Ai\Sources;

use App\Models\Attachment;
use App\Support\Platform\SchemaCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * **مصادرُ فهم المشروع** — ملفّاتُه وموقعُه كما يقرؤها الذكاء (docs/ai-hub/47 §العمود ج — المرحلة ٤).
 *
 * **الملفّات:** مرفقاتُ سجلّ المشروع نفسِه، ومرفقاتُ وثائق وحدة «الملفات والمستندات» المربوطة بالمشروع —
 * **ما عدا الوثيقةَ «السرّية»** (لا تدخل فهماً يقرؤه من لا يملك `docsec`)، والمصابَ بفحص الفيروسات، وما في
 * حقلٍ حسّاس (`hub_field_sec`). يُستخرج نصُّ الجديد والمتغيّرُ بصمتُه فقط.
 *
 * **الموقع:** `projects.url` (الحيّ وحدَه — لا `staging`، قرارُ المالك) يُقرأ حين تمضي `ai.sources_site_days`
 * على آخر لقطة، وتُحفظ لقطةٌ جديدة إن تغيّرت بصمتُه (مع رايةِ «تغيّر»)، وتُبقى آخرُ خمسِ لقطاتٍ لكلِّ مشروع.
 *
 * لا نداءَ ذكاءٍ هنا: قراءةٌ واستخراجٌ وحفظ. والمستهلكُ «ملفُّ فهم المشروع» (المرحلة ٥).
 */
final class ProjectSources
{
    public const KEEP_SNAPSHOTS = 5;

    /** أقصى ملفّاتٍ تُستخرج في الجولة، ومواقعُ تُقرأ */
    public const FILES_PER_RUN = 60;
    public const SITES_PER_RUN = 20;

    /** نصُّ الملفّات الذي يُمرَّر للفهم لكلِّ مشروع */
    public const FILES_CHARS = 24000;

    public static function ready(): bool
    {
        return SchemaCache::hasTable('attachment_texts') && SchemaCache::hasTable('site_snapshots');
    }

    public static function filesOn(): bool
    {
        return (string) setting('ai.sources_files', '1') === '1';
    }

    public static function siteOn(): bool
    {
        return (string) setting('ai.sources_site', '1') === '1';
    }

    public static function siteDays(): int
    {
        return max(1, min(60, (int) setting('ai.sources_site_days', 7)));
    }

    /**
     * مرفقاتُ المشروع التي يجوز للذكاء قراءتُها.
     *
     * @return Collection<int, Attachment>
     */
    public static function attachments(string $projectId): Collection
    {
        $docs = SchemaCache::hasTable('documents')
            ? DB::table('documents')->whereNull('deleted_at')->where('project_id', $projectId)
                ->where(fn ($w) => $w->whereNull('secrecy')->orWhere('secrecy', '!=', 'سري'))->pluck('id')->all()
            : [];

        $rows = Attachment::query()->whereNull('deleted_at')
            ->where(function ($w) use ($projectId, $docs) {
                $w->where(fn ($x) => $x->where('module', 'projects')->where('record_id', $projectId));
                if ($docs !== []) $w->orWhere(fn ($x) => $x->where('module', 'files')->whereIn('record_id', $docs));
            })
            ->where(fn ($w) => $w->whereNull('av_status')->orWhere('av_status', '!=', 'infected'))
            ->orderBy('created_at')->orderBy('id')->limit(200)->get();

        $out = [];
        foreach ($rows as $a) {
            if (! $a instanceof Attachment) continue;
            if ($a->field && hub_field_sensitive((string) $a->module, (string) $a->field)) continue;
            $out[] = $a;
        }

        return collect($out);
    }

    /**
     * جولةٌ: ملفّاتٌ جديدةٌ أو متغيّرة، ومواقعُ فات أوانُ قراءتها.
     *
     * @return array{files: int, sites: int, changed: int, errors: int}
     */
    public static function run(?string $projectId = null, bool $dry = false, bool $files = true, bool $sites = true): array
    {
        $out = ['files' => 0, 'sites' => 0, 'changed' => 0, 'errors' => 0];
        if (! self::ready()) return $out;

        $projects = DB::table('projects')->whereNull('deleted_at')
            ->when($projectId, fn ($q) => $q->where('id', $projectId))
            ->when(! $projectId, fn ($q) => $q->whereNotIn('status', ['مكتمل', 'ملغى']))
            ->orderBy('id')->limit(1000)->get(['id', 'url']);

        if ($files && self::filesOn()) {
            foreach ($projects as $p) {
                foreach (self::attachments((string) $p->id) as $a) {
                    if ($out['files'] >= self::FILES_PER_RUN) break 2;
                    $prev = DB::table('attachment_texts')->where('attachment_id', $a->id)->orderBy('id')->first(['id', 'checksum', 'status']);
                    $sum = (string) ($a->checksum ?: ($a->size . ':' . $a->updated_at));
                    if ($prev && (string) $prev->checksum === $sum && $prev->status !== 'no_reader') continue;
                    $out['files']++;
                    if ($dry) continue;

                    $r = DocumentText::extract($a);
                    if (in_array($r['status'], ['failed'], true)) $out['errors']++;
                    $row = ['checksum' => $sum, 'kind' => $r['kind'], 'status' => $r['status'], 'text' => $r['text'] !== '' ? $r['text'] : null,
                        'chars' => mb_strlen($r['text']), 'pages' => $r['pages'], 'error' => $r['error'], 'extracted_at' => now(), 'updated_at' => now()];
                    $prev
                        ? DB::table('attachment_texts')->where('id', $prev->id)->update($row)
                        : DB::table('attachment_texts')->insert($row + ['id' => (string) Str::uuid(), 'attachment_id' => $a->id, 'created_at' => now()]);
                }
            }
        }

        if ($sites && self::siteOn()) {
            foreach ($projects as $p) {
                if ($out['sites'] >= self::SITES_PER_RUN) break;
                $url = trim((string) $p->url);
                if ($url === '') continue;
                $last = self::latestSnapshot((string) $p->id);
                if (! $projectId && $last && $last->fetched_at && now()->diffInDays($last->fetched_at, true) < self::siteDays()) continue;
                $out['sites']++;
                if ($dry) continue;

                $res = SiteReader::read($url);
                if ($res['status'] !== 'ok') $out['errors']++;
                $hash = $res['text'] !== '' ? hash('sha256', $res['text']) : null;
                $changed = $last !== null && $hash !== null && $last->hash !== null && $last->hash !== $hash;
                if ($changed) $out['changed']++;

                DB::table('site_snapshots')->insert([
                    'id' => (string) Str::uuid(), 'project_id' => (string) $p->id, 'url' => mb_substr($url, 0, 500),
                    'status' => $res['status'], 'http_status' => $res['http_status'],
                    'pages' => json_encode($res['pages'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'meta' => json_encode($res['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'text' => $res['text'] !== '' ? $res['text'] : null, 'hash' => $hash, 'changed' => $changed,
                    'error' => $res['error'], 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                self::prune((string) $p->id);
            }
        }

        return $out;
    }

    public static function latestSnapshot(string $projectId): ?object
    {
        if (! self::ready()) return null;

        return DB::table('site_snapshots')->where('project_id', $projectId)
            ->orderByDesc('fetched_at')->orderByDesc('id')->first();
    }

    /**
     * ما يعرفه النظامُ عن مصادر المشروع — للعرض وللفهم (المرحلة ٥). `files` قائمةٌ بلا نصٍّ للعرض،
     * و`files_text` نصٌّ مجمَّعٌ مقصوصٌ للفهم.
     *
     * @return array{files: list<array>, files_text: string, site: ?array}
     */
    public static function summary(string $projectId): array
    {
        $atts = self::attachments($projectId);
        $texts = $atts->isEmpty() || ! self::ready() ? collect()
            : DB::table('attachment_texts')->whereIn('attachment_id', $atts->pluck('id')->all())->get()->keyBy('attachment_id');

        $files = [];
        $blob = '';
        foreach ($atts as $a) {
            $t = $texts[$a->id] ?? null;
            $files[] = ['name' => (string) ($a->original_name ?: basename((string) $a->path)), 'status' => $t->status ?? 'pending',
                'chars' => (int) ($t->chars ?? 0), 'module' => (string) $a->module, 'record_id' => (string) $a->record_id];
            if ($t && $t->status === 'ok' && mb_strlen($blob) < self::FILES_CHARS) {
                $blob .= "\n\n## " . ($a->original_name ?: 'ملف') . "\n" . mb_substr((string) $t->text, 0, self::FILES_CHARS - mb_strlen($blob));
            }
        }

        $s = self::latestSnapshot($projectId);

        return ['files' => $files, 'files_text' => trim($blob), 'site' => $s === null ? null : [
            'url' => (string) $s->url, 'status' => (string) $s->status, 'fetched_at' => (string) $s->fetched_at,
            'changed' => (bool) $s->changed, 'error' => $s->error,
            'pages' => (array) json_decode((string) $s->pages, true), 'meta' => (array) json_decode((string) $s->meta, true),
            'text' => (string) $s->text,
        ]];
    }

    private static function prune(string $projectId): void
    {
        $keep = DB::table('site_snapshots')->where('project_id', $projectId)
            ->orderByDesc('fetched_at')->orderByDesc('id')->limit(self::KEEP_SNAPSHOTS)->pluck('id')->all();
        DB::table('site_snapshots')->where('project_id', $projectId)->whereNotIn('id', $keep)->delete();
    }
}
