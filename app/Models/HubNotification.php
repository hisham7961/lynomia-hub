<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class HubNotification extends Model
{
    use HasUuid;

    protected $table = 'notifications_hub';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['read' => 'boolean', 'created_at' => 'datetime'];
}
