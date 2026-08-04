<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * এক স্তরের একটা সিদ্ধান্ত।
 *
 * প্রতিটা স্তর আলাদা রো, কারণ শুধু চূড়ান্ত অবস্থা রাখলে "তিন নম্বর স্তরে
 * চার দিন আটকে ছিল কেন" প্রশ্নের উত্তর কখনো পাওয়া যায় না।
 */
class ApprovalDecision extends Model
{
    use HasFactory;
    use HasPublicId;

    protected $fillable = ['approval_id', 'level', 'user_id', 'decision', 'remarks', 'decided_at'];

    protected function casts(): array
    {
        return ['level' => 'integer', 'decided_at' => 'datetime'];
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
