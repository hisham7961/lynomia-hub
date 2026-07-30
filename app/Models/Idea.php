<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use App\Traits\HasVersions;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Idea extends Model
{
    use HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'ideas';
    public const MODULE = 'ideas';
    public const DISPLAY = 'title';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'impact' => 'integer', 'confidence' => 'integer', 'ease' => 'integer',
        'tags' => 'array', 'custom' => 'array', 'meta' => 'array', 'archived' => 'boolean',
    ];

    /** درجة ICE ٠-١٠٠٠ = الأثر × الثقة × سهولة التنفيذ (كل منها ١-١٠) */
    public function iceScore(): ?int
    {
        if ($this->impact === null || $this->confidence === null || $this->ease === null) return null;

        return $this->impact * $this->confidence * $this->ease;
    }
}
