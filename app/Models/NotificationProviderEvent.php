<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/** ⭐ প্রোভাইডারের একটা ফেরত-খবর — রসিদ, bounce, অভিযোগ, ব্যর্থতা; যন্ত্রের একবার-লেখা সারি ([[ProviderCallbacks]]) */
class NotificationProviderEvent extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    public const UPDATED_AT = null;

    /** @var list<string> */
    public const EVENTS = ['delivered', 'bounced', 'complained', 'failed'];

    protected $fillable = ['company_id', 'job_id', 'channel', 'provider', 'event', 'provider_ref', 'reason', 'payload_hash', 'occurred_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
