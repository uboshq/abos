<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * কে মাসে কত তুলতে পারবেন।
 *
 * ── কেন উত্তোলনের সারিতে নয়, আলাদা টেবিলে ───────────────────────────
 * সীমা বদলায় — বছরে একবার, বা অংশীদাররা বসে ঠিক করলে। উত্তোলনের সারিতে
 * বসালে পুরনো উত্তোলনগুলোর সীমাও বদলে যেত, আর "তখন সীমা কত ছিল"
 * প্রশ্নের উত্তর হারাত।
 *
 * ── সীমা না থাকা মানে সীমা নেই ───────────────────────────────────────
 * সারি না থাকলে ওই মানুষটির কোনো সীমা নেই — শূন্য নয়। শূন্য ধরলে
 * প্রথম দিনেই সবার উত্তোলন আটকে যেত, আর কেউ বুঝত না কেন।
 */
class WithdrawalLimit extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;

    protected $table = 'acc_withdrawal_limits';

    protected $fillable = ['company_id', 'contributor_name', 'monthly_cap'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['monthly_cap' => 'decimal:4'];
    }
}
