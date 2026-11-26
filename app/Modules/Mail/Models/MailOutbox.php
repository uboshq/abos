<?php

declare(strict_types=1);

namespace App\Modules\Mail\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ "পরে পাঠান" — এক মেইল, নির্দিষ্ট সময়ের অপেক্ষায় ([[MailOutboxRun]] প্রতি 5 মিনিটে পাঠায়)।
 * ⛔ লেখা তালাবদ্ধ (encrypted) আর কেবল পাঠানো পর্যন্ত — পাঠানো হলেই সারি মোছে; খাতা থাকে [[MailSend]]-এ।
 */
class MailOutbox extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    protected $table = 'mail_outbox';

    protected $fillable = ['mail_account_id', 'user_id', 'payload', 'send_at', 'last_error', 'tries'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'send_at' => 'datetime', 'tries' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'mail_account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
