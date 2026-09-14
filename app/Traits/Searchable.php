<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

/** بحث نصي بسيط وموثوق (LIKE) على أعمدة الوحدة النصية من سجل الوحدات — متوافق مع MySQL */
trait Searchable
{
    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        if (! $term || mb_strlen(trim($term)) < 2) {
            return $q;
        }

        // أحرف البدل تُهرَّب: «%%» كان يطابق كل صفوف كل الوحدات (مسحٌ كامل
        // لسبعين جدولاً بضغطة)، و«_» أيَّ حرف — والبحث عن «50%» الحرفية لا يجدها.
        // حرف الهروب «!» لا «\»: literal الشرطة الخلفية نفسه يختلف بين المحرّكين
        // ('\\' حرفٌ في MySQL وحرفان في SQLite) فيتباعد سلوكهما — و«!» واحدٌ فيهما.
        $t   = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($term)) . '%';
        $def = config('hub.modules.' . static::MODULE, []);

        // **الحقول المخفية عن الدور لا تُبحث**: البحث كان يقرأ كل الأعمدة النصية
        // فيصير أوراكل محتوى — من حُجب عنه حقلٌ (ملاحظات إدارية، شروط تعاقدية)
        // يستنطقه حرفاً حرفاً بتجربة مطابقات. بقية النظام يفرض hide على الفرز
        // والترشيح والعرض — والبحث كان الباب المنسي.
        $u = auth()->user();
        $cols = collect($def['fields'] ?? [])
            ->whereIn('type', ['text', 'ta', 'url', 'sel', 'tags'])
            ->reject(fn ($f) => hub_field_mode($u, static::MODULE, (string) ($f['key'] ?? '')) === 'hide')
            ->pluck('col')
            ->push(hub_display_col(static::MODULE))
            ->unique()
            ->values();

        // **والعربيةُ تُطبَّع قبل المقارنة**: `LIKE` تقارن الحرفَ بالحرف، فمشروعُ
        // «بوّابة الخليج» المخزَّنُ بالشدّةِ لا يجده من كتب «بوابة الخليج» — وهو ما
        // يكتبه كلُّ أحد. ولا رسالةَ تُفسّر، بل «لا نتائج» على سجلٍّ قائم. يُطبَّع
        // الطرفان بالجدولِ نفسِه الذي يقرؤه `hub_ar_norm` (مصدرٌ واحدٌ لا اثنان).
        $norm = hub_has_arabic($term) ? hub_ar_norm(trim($term)) : null;
        $tn = $norm !== null
            ? '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $norm) . '%'
            : null;

        return $q->where(function (Builder $qq) use ($cols, $t, $tn) {
            foreach ($cols as $c) {
                // اسم العمود من سجل الوحدات لا من المستخدم — والقيمة مربوطة
                $qq->orWhereRaw("`{$c}` LIKE ? ESCAPE '!'", [$t]);
                if ($tn !== null) {
                    $qq->orWhereRaw(hub_ar_norm_sql("`{$c}`") . " LIKE ? ESCAPE '!'", [$tn]);
                }
            }
        });
    }
}
