<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * কে একটা নোটিশ মেনে নিয়েছেন — সই, পড়া নয়.
 *
 * ⓘ ক্লাসের নাম `NoticeAck`, টেবিলের নাম পুরো — `notice_acknowledgements`.
 * ⚠️ নামটা ছোট রাখা হয়েছে কারণ এটা কোডে বহুবার লেখা হয়, আর টেবিলের
 * নামটা পুরো রাখা হয়েছে কারণ ওটা ডাটাবেজ পড়া মানুষ দেখেন.
 */
final class NoticeAck extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    protected $table = 'notice_acknowledgements';

    protected $fillable = ['company_id', 'notice_id', 'user_id', 'acknowledged_at', 'ip', 'agent'];

    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime'];
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(Notice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
