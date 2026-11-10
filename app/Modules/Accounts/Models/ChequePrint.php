<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ ছাপা চেকের খাতার এক সারি — কোন বইয়ের কোন নম্বর, কাকে, কত, কবে, কে ছাপল, কতবার; নষ্ট পাতাও (মালিকের নমুনা, পাতা ৫)।
 *
 * ⓘ টাকার হিসাব নয় — পাহারা: একই বইয়ের একই নম্বর একবারই সারি পায় (টেবিলের unique), আবার ছাপা মানে `times` বাড়ে আর কারণ লাগে,
 * নষ্ট পাতা `spoiled` হয়ে থাকে, নম্বরটা আর কখনো নয়। হিসাব ছাড়া চেক `off_books` চিহ্নে — কোম্পানির চেকের সাথে মেশে না।
 */
class ChequePrint extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    public const PRINTED = 'printed';

    public const SPOILED = 'spoiled';

    protected $table = 'acc_cheque_prints';

    protected $fillable = ['company_id', 'cheque_book_id', 'cheque_no', 'cheque_date', 'payee', 'amount', 'crossed', 'off_books', 'voucher_id', 'cheque_id', 'purpose', 'status', 'times', 'reason', 'printed_by'];

    protected function casts(): array
    {
        return ['cheque_date' => 'date', 'crossed' => 'boolean', 'off_books' => 'boolean', 'cheque_no' => 'integer', 'times' => 'integer'];
    }

    /** @return BelongsTo<ChequeBook, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(ChequeBook::class, 'cheque_book_id');
    }

    /** @return BelongsTo<Voucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /** @return BelongsTo<User, $this> */
    public function printer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by');
    }
}
