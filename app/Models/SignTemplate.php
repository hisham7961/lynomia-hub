<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** قالب عقد للتوقيع الإلكتروني — نصٌّ بمتغيرات {var} تُملأ عند الإنشاء */
class SignTemplate extends Model
{
    use HasUuid;

    protected $table = 'sign_templates';
    protected $guarded = ['id'];
    protected $casts = ['blocks' => 'array', 'archived_at' => 'datetime'];

    /** المتغيرات المكتشفة في النص — تُبنى منها حقول نموذج الإنشاء تلقائياً */
    public function vars(): array
    {
        preg_match_all('/\{([^{}\s]{1,40})\}/u', $this->body, $m);

        return array_values(array_unique($m[1]));
    }

    /** بنود القالب — القديم بلا blocks يُعرض بنداً واحداً حراً */
    public function blocksOr(): array
    {
        $b = $this->blocks;
        if (is_array($b) && count($b)) return $b;

        return [['h' => '', 'body' => (string) $this->body, 'break' => false]];
    }

    /** تسطيح البنود لنص الوثيقة — هو ما يُستبدل ويُبصم ويُعرض للموقّع */
    public static function flatten(array $blocks): string
    {
        $out = [];
        foreach ($blocks as $b) {
            $h = trim((string) ($b['h'] ?? ''));
            $body = trim((string) ($b['body'] ?? ''));
            if ($h === '' && $body === '') continue;
            $out[] = trim(($h !== '' ? $h . "\n" : '') . $body);
        }

        return implode("\n\n", $out);
    }

    /** لقطة إصدار قبل تعديل جوهري */
    public function snapshotVersion(?string $note = null): void
    {
        SignTemplateVersion::create([
            'template_id' => $this->id, 'version' => (int) ($this->version ?: 1),
            'name' => $this->name, 'body' => (string) $this->body,
            'note' => $note, 'editor_id' => auth()->id(), 'created_at' => now(),
        ]);
    }
}
