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
 * বিক্রয়ের পথ — পরিবেশক, ডিলার, খুচরা, পাইকারি, প্রাতিষ্ঠানিক, ই-কমার্স,
 * অনলাইন, সরাসরি, কাউন্টার, অন্যান্য (NEXUS §২৮)।
 *
 * ── ⚠️ কেন এটা [[PartyType]] নয়, যদিও কয়েকটা নাম মেলে ─────────────────
 * পক্ষের ধরন বলে **কে** — ক্রেতাটা কী রকম ব্যবসা (আর সরবরাহকারীর দিকেও
 * খাটে, আর "এক পয়েন্টে একজন পরিবেশক" নিয়মটা ওতেই বাঁধা)। পথ বলে
 * **কোন রাস্তায় বিক্রি হলো** — কাউন্টার, অনলাইন, ই-কমার্স, সরাসরি।
 * ⛔ "কাউন্টার" বা "অনলাইন" কোনো ব্যবসার ধরন নয়; ওগুলো পক্ষের ধরনে
 * বসালে সরবরাহকারীর তালিকাতেও ভেসে উঠত, আর পরিবেশকের নিয়মটা ঘোলা হত।
 *
 * ── ⚠️ আর কেন `sal_orders.source` নয় ─────────────────────────────────
 * `source` (counter · portal · sr) ব্যবস্থার নিজের দরজা — কোডে স্থির,
 * কোনো কোম্পানি নতুন দরজা যোগ করতে পারে না। পথ ব্যবসার তালিকা —
 * প্রতিটা কোম্পানি নিজের মতো সাজায়। তাই সারি, enum নয়।
 */
class SalesChannel extends Model implements Drillable
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use IsMasterRecord;
    use SoftDeletes;

    protected $table = 'mdm_sales_channels';

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn',
        'is_default', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
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
        return 'sales_channel';
    }

    public function drillDocumentNo(): string
    {
        return $this->code;
    }

    public function drillLabel(): string
    {
        return $this->name();
    }

    /*
     * ⓘ সাধারণ তালিকার কোনো show পাতা নেই — সম্পাদনার পাতাটাই সারিটার ঘর।
     */
    public function drillRoute(): array
    {
        return ['master_data.sales_channel.edit', ['id' => $this->id]];
    }
}
