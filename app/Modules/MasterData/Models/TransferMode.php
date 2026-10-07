<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\IsMasterRecord;
use App\Core\Contracts\Drillable;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * টাকা কীভাবে সরল — "ONLINE", "NPSB", "BEFTN", "RTGS"।
 *
 * ── কেন এটা method-এর ভেতরে একটা কলাম নয় ────────────────────────────
 * payment method বলে টাকা *কীসে* এল (ব্যাংক, MFS)। মাধ্যম বলে *কোন
 * চ্যানেলে* — আর একই ব্যাংক-জমা RTGS বা BEFTN দুইভাবেই আসতে পারে। তাই
 * method-এর একটা কলাম হলে চলত না; এটা তার নিজের তালিকা।
 *
 * সারি, enum নয়: চ্যানেলের তালিকা দেশ ও ব্যাংকভেদে বাড়ে, আর নতুন
 * একটা যোগ করতে রিলিজ লাগলে সেটা আর মাস্টার ডাটা থাকে না।
 *
 * `applies_to` বলে কোন payment-kind-এর সাথে মাধ্যমটা যায় (bank · mfs …);
 * খালি হলে সব ধরনে চলে।
 */
class TransferMode extends Model implements Drillable
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use IsMasterRecord;
    use SoftDeletes;

    protected $table = 'mdm_transfer_modes';

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn',
        'applies_to', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'transfer_mode';
    }

    public function drillDocumentNo(): string
    {
        return $this->code;
    }

    public function drillLabel(): string
    {
        return $this->name();
    }

    public function drillRoute(): array
    {
        return ['master_data.transfer_mode.edit', ['id' => $this->id]];
    }
}
