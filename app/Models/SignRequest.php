<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** طلب توقيع إلكتروني — رابط خاص بكلمة سر، وأثرٌ كامل: من ومتى ومن أين */
class SignRequest extends Model
{
    use HasUuid;

    protected $table = 'sign_requests';
    protected $guarded = ['id'];
    protected $hidden = ['pass'];
    protected $casts = ['opened_at' => 'datetime', 'signed_at' => 'datetime'];
}
