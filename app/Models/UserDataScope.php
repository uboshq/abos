<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একজন ব্যবহারকারীর একটা সীমা — একটা শাখা, বা একটা গুদাম।
 *
 * সারি না থাকা মানে সীমা নেই, অর্থাৎ সব দেখা যায়। কারণটা মাইগ্রেশনে
 * লেখা: উল্টো ধরলে ফিচারটা চালু হওয়ার মুহূর্তে সবাই অন্ধ হয়ে যেতেন।
 */
class UserDataScope extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    /** শাখা — বেশিরভাগ কাগজে এই ঘরটাই আছে। */
    public const BRANCH = 'branch';

    /** গুদাম — মজুদের কাগজে। */
    public const WAREHOUSE = 'warehouse';

    protected $table = 'user_data_scopes';

    protected $fillable = [
        'company_id', 'user_id', 'scope_type', 'scope_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scope_id' => 'integer',
        ];
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
