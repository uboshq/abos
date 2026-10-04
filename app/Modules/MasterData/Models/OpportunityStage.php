<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\IsMasterRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

/**
 * সুযোগের ধাপ — "খোঁজ", "প্রস্তাব", "দরকষাকষি", "জিতেছি", "হেরেছি"।
 *
 * ── কেন তালিকা, স্থির ধাপ নয় ───────────────────────────────────────────
 * ABOS অনেক ব্যবসায় বিক্রি হয়; ডিপোর ধাপ আর ঠিকাদারের ধাপ এক নয়।
 * তাই ধাপগুলো মাস্টার তালিকার ছাঁচে — কোম্পানি নিজে যোগ করে, নাম বদলায়,
 * বন্ধ করে ([[MasterListController]]-এর `opportunity-stages`)।
 *
 * ⓘ মডেলটা MasterData-তে, টেবিল `sal_opportunity_stages`-ই — মাস্টার-তালিকার পর্দা ([[MasterListController]])
 * MasterData-র, আর MasterData Sales-কে চেনে না (BoundariesTest); [[SalesChannel]]-এর মতোই। ৪ অক্টোবর ২০২৬।
 *
 * ⓘ কোডে কেবল দুইটা জিনিস বাঁধা: কোন ধাপ মানে **জেতা** আর কোনটা
 * **হারা**। বাকি সব ধাপ চলমান।
 */
class OpportunityStage extends Model
{
    use BelongsToCompany;
    use HasActiveState;
    use HasPublicId;
    use IsAudited;
    use IsMasterRecord;
    use SoftDeletes;

    protected $table = 'sal_opportunity_stages';

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn',
        'probability', 'sort_order', 'is_won', 'is_lost',
        'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'probability' => 'integer',
            'sort_order' => 'integer',
            'is_won' => 'boolean',
            'is_lost' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        /*
         * ⛔ একই ধাপ জেতা আর হারা একসাথে হতে পারে না।
         *
         * ⚠️ মাস্টার-তালিকার ফর্মে দুইটা আলাদা টিক-ঘর, তাই দুইটাই টিক
         * দেওয়া যায়। এখানে না আটকালে পাইপলাইন ঐ ধাপের সুযোগকে একবার
         * জেতা আর একবার হারা গুনত — দুই দিকেই একই টাকা।
         */
        static::saving(function (self $stage): void {
            // ফর্মের ফাঁকা সংখ্যা-ঘর null পাঠায়, আর কলামটা NOT NULL — শূন্য ধরা
            $stage->probability ??= 0;
            $stage->sort_order ??= 0;

            if ($stage->is_won && $stage->is_lost) {
                throw ValidationException::withMessages([
                    'is_won' => __('sales::crm.stage_both_won_and_lost'),
                ]);
            }

            if ($stage->probability !== null && ($stage->probability < 0 || $stage->probability > 100)) {
                throw ValidationException::withMessages([
                    'probability' => __('sales::crm.probability_range'),
                ]);
            }
        });
    }

    public function isOpen(): bool
    {
        return ! $this->is_won && ! $this->is_lost;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
