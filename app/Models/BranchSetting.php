<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একটা শাখার নিজের সেটিং — কোম্পানির সেটিংয়ের উপর বদল ([[BranchSettings]])।
 *
 * ⭐ মালিক, ৩০ সেপ্টেম্বর ২০২৬: আলাদা শাখায় আলাদা ধরনের ব্যবসা হতে পারে — তাই বিলের তথ্য, লোগো আর নকশা শাখা ধরে।
 * ⓘ সারি না থাকা মানে "কোম্পানির মতো"; সারিটা মুছলেই কোম্পানিরটায় ফেরে। ⛔ নিরীক্ষিত — কে কোন শাখার কাগজের
 * চেহারা বদলাল, খাতায় থাকে।
 */
class BranchSetting extends Model
{
    use HasPublicId;
    use IsAudited;

    protected $fillable = ['company_id', 'branch_id', 'key', 'type', 'value'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** সংরক্ষিত লেখাকে আসল ধরনে — [[Setting::typedValue()]]-এর মতো */
    public function typedValue(): mixed
    {
        return match ($this->type) {
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $this->value,
            'json' => json_decode((string) $this->value, true),
            default => $this->value,
        };
    }
}
