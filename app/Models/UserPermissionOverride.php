<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একজন ব্যবহারকারীর একটা অনুমতির ব্যতিক্রম — দেওয়া, বা কেড়ে নেওয়া।
 *
 * রোল যা দেয় তার উপরে এটা বসে। `granted = false` রোলের দেওয়া অনুমতিকেও
 * হারায়, কারণ নাহলে কেড়ে নেওয়ার কোনো উপায়ই থাকত না — আর ঠিক ওই
 * অভাবটার জন্যই আজ একজনের একটা ক্ষমতা তুলতে গেলে তাঁর জন্য আস্ত একটা
 * নতুন রোল বানাতে হয়।
 */
class UserPermissionOverride extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'user_permission_overrides';

    protected $fillable = [
        'company_id', 'user_id', 'permission', 'granted', 'reason', 'created_by',
    ];

    protected function casts(): array
    {
        return ['granted' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
