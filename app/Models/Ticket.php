<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use App\Traits\HasVersions;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** تذاكر العملاء */
class Ticket extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'tickets';
    public const MODULE = 'tickets';
    public const DISPLAY = 'subject';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'tags' => 'array',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    protected static function booted(): void
    {
        /*
         * **الميلُ الأخير يبلغ صاحبَه** (الجولة 2 · G5): تُحلّ تذكرةُ العميل ولا
         * يصله حرف — لا حالةٌ ولا إشعار. الختمُ هنا على **تغيّر الحالة** فحسب، في
         * النموذج لا في متحكّمٍ بعينه، فيسري على كلِّ بابِ كتابة (نموذجُ الوحدة،
         * والحالةُ من الكانبان، والإجراءُ الجماعيّ، وAPI) بلا محرّكٍ ثانٍ.
         * القرارُ والنصُّ والمستقبِلون في `ClientPortalData::announceTicketResolution`
         * (مساحةُ العميل مصدرٌ واحد)، وهو لا يرمي: إشعارٌ متعثّرٌ لا يكسر الحفظ.
         */
        static::updated(function (self $t): void {
            if (! $t->wasChanged('status')) return;

            try { \App\Support\Collaboration\ClientPortalData::announceTicketResolution($t); }
            catch (\Throwable $e) { report($e); }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class, 'project_id');
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Application::class, 'app_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'assignee_id');
    }
}
