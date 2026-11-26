<?php

declare(strict_types=1);

namespace App\Modules\Mail\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ ABOS থেকে পাঠানো একটা মেইলের খাতা (মালিকের অডিট নির্দেশ, ২৬ নভেম্বর ২০২৬): কে, কোন ঠিকানা থেকে, কাকে, কোন বিষয়ে, সাথে কোন কাগজ।
 * ⛔ মেইলের লেখা এখানে নেই — কেবল কাজের বিবরণ। সারিটা কখনো বদলায় না, মোছে না।
 * ⓘ কাগজের পাতায় "মেইলে পাঠানো" — `paper_type` + `paper_id` ধরে ([[MailSends::forPaper]])।
 */
class MailSend extends Model
{
    use BelongsToCompany;

    protected $table = 'mail_sends';

    protected $fillable = [
        'mail_account_id', 'from_email', 'to', 'cc', 'subject', 'message_id', 'paper_type', 'paper_id', 'paper_no',
        'template_id', 'files', 'auto', 'sent_by', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'auto' => 'boolean', 'files' => 'integer', 'paper_id' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'mail_account_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
