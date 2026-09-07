<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class SavedView extends Model
{
    use HasUuid;

    protected $guarded = ['id'];
    protected $casts = ['is_default' => 'boolean'];

    /** رابط تطبيق العرض: قائمة الوحدة بسلسلة الاستعلام المخزنة + مُعرّف العرض */
    public function url(): string
    {
        // (WP-5.3) التحقيقاتُ المحفوظة: module='audit' وجهتُها شاشةُ التدقيق
        // لا قائمةُ وحدةٍ — الجدولُ نفسُه يخدم الاثنين، لا جدولَ ثانياً.
        // (الطور H · WP-H.2) وعروضُ مستكشف العلاقات: module='graph' وجهتُها
        // المستكشف، وسلسلةُ الاستعلام المحفوظة هي الهدفُ نفسُه (m/id/hops) —
        // الجدولُ القائمُ يركب ثالثةً، لا جدولَ graph_views
        $base = match ($this->module) {
            'audit' => route('audit.index'),
            'graph' => route('graph.explore'),
            default => route('m.index', $this->module),
        };

        return $base . '?' . ($this->query ? $this->query . '&' : '') . 'view=' . $this->id;
    }
}
