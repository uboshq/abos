<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\MasterData\Models\Person;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    protected $fillable = ['company_id', 'person_id', 'monthly_cap'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['monthly_cap' => 'decimal:4'];
    }

    /**
     * কার সীমা — নাম নয়, তালিকার সারি।
     *
     * ── ⛔ কেন নামটা সরানো হলো, ১৩ সেপ্টেম্বর ২০২৬ ───────────────────
     * আগে ঘরটা ছিল `contributor_name`, মুক্ত লেখা, আর
     * [[App\Modules\Finance\Services\WithdrawalService::assertWithinCap]]
     * সীমাটা খুঁজত **হুবহু নাম মিলিয়ে**। বানান এক অক্ষর আলাদা হলেই
     * সীমাটা পাওয়া যেত না, আর পদ্ধতিটা চুপচাপ `return` করত — অর্থাৎ
     * সীমা **একেবারেই বসত না**, কোনো বার্তা ছাড়া।
     *
     * টাকা বেরিয়ে যাওয়ার একটা নীরব পথ, আর সেটা বন্ধ হয় কেবল পরিচয়টা
     * একটা সারিতে বাঁধলে।
     *
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }
}
