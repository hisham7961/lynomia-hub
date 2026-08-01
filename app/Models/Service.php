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

/** الخدمات والمنتجات */
class Service extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'services';
    public const MODULE = 'services';
    public const DISPLAY = 'name';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'price' => 'decimal:3',
        'cost' => 'decimal:3',
        'sla' => 'decimal:3',
        // المراجع المتعددة: بلا cast يُخزَّن JSON نصّاً ثم يُقرأ (array) فيصير
        // ['["uuid"]'] — عنصرٌ واحد نصُّه السلسلة كلها، فتُعرض البطاقة فارغة
        'competitor_ids' => 'array',
        'idea_ids' => 'array',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class, 'project_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'team_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Server::class, 'server_id');
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Domain::class, 'domain_id');
    }
}
