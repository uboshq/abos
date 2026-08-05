<?php

declare(strict_types=1);

namespace App\Modules\Customer\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\Drillable;
use App\Models\Branch;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\MasterData\Models\PartyType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * একজন গ্রাহক।
 *
 * Phase 2-এর কাজ: এই মডিউলটা দিয়েই ভিত্তিটা প্রমাণ করা (সেকশন ২.৩)।
 * তাই এখানে কোনো নতুন কৌশল নেই — যা যা Phase 1-এ বানানো হয়েছে সেগুলোই
 * ব্যবহার করা হচ্ছে: company scope, status, drill-down, নম্বর সিরিজ,
 * সেটিংস, অনুমতি। কোথাও কিছু আলাদা করে লিখতে হলে সেটাই ভিত্তির ফাঁক।
 */
class Customer extends Model implements Drillable
{
    use BelongsToCompany;
    use HasActiveState;
    use HasDocumentStatus;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'branch_id', 'code', 'name_en', 'name_bn',
        'phone', 'email', 'address_en', 'address_bn', 'customer_type', 'party_type_id',
        'credit_limit', 'credit_days', 'opening_balance', 'opening_date',
        'receivable_account_id', 'status', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:4',
            'credit_days' => 'integer',
            'opening_balance' => 'decimal:4',
            'opening_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * গ্রাহকের ধরন — খুচরা, পাইকারি, ডিলার।
     *
     * মাস্টার তালিকা থেকে, মুক্ত লেখা নয়: আগে customer_type ছিল একটা
     * string, আর কেউ "খুচরা", কেউ "Retail", কেউ শেষে একটা স্পেস দিয়ে
     * লিখত। "কোন ধরনের গ্রাহক সবচেয়ে বেশি" প্রশ্নের উত্তর তখন বের করা
     * যেত না।
     */
    public function partyType(): BelongsTo
    {
        return $this->belongsTo(PartyType::class, 'party_type_id');
    }

    /**
     * ধরনের নাম — নতুন সম্পর্ক থেকে, না থাকলে পুরনো লেখাটা।
     *
     * পুরনো কলামটা রাখা হয়েছে যাতে মাইগ্রেশনে যেগুলো নাম মিলিয়ে জোড়া
     * যায়নি সেগুলো হারিয়ে না যায় — কেউ পরে হাতে মিলিয়ে দিতে পারবে।
     */
    public function typeName(): ?string
    {
        return $this->partyType?->name() ?? $this->customer_type;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** ব্যবহারকারীর ভাষায় নাম — বাংলা না থাকলে ইংরেজি (সেকশন ১৮.৩)। */
    public function name(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        if ($locale === 'bn' && filled($this->name_bn)) {
            return $this->name_bn;
        }

        return $this->name_en;
    }

    public function address(?string $locale = null): ?string
    {
        $locale = $locale ?? app()->getLocale();

        if ($locale === 'bn' && filled($this->address_bn)) {
            return $this->address_bn;
        }

        return $this->address_en;
    }

    /**
     * নাম, কোড বা ফোন — তিনটার যেকোনোটা দিয়ে খোঁজা।
     *
     * কাউন্টারে দাঁড়ানো অবস্থায় কেউ গ্রাহকের কোড মনে রাখে না, কিন্তু
     * ফোন নম্বরটা প্রায়ই হাতের কাছে থাকে।
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($term)).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('name_en', 'like', $like)
                ->orWhere('name_bn', 'like', $like)
                ->orWhere('code', 'like', $like)
                ->orWhere('phone', 'like', $like);
        });
    }

    /**
     * এই গ্রাহকের বর্তমান পাওনা।
     *
     * পুরোটাই লেজার থেকে। লেজারে সব লেনদেনই আছে (Posting engine ছাড়া কেউ
     * লেখে না), তাই আলাদা করে "due" কলাম রাখা হয়নি — রাখলে সেটা একদিন
     * লেজারের সাথে অমিল হত, আর কোনটা সত্যি তা বলার উপায় থাকত না।
     *
     * খোলা ব্যালেন্স আগে এখানে যোগ করা হত, কারণ ওটা শুধু গ্রাহকের সারিতে
     * বসত। ফল: এই পাতায় পাওনা দেখাত, অথচ ট্রায়াল ব্যালেন্স বা বকেয়া
     * তালিকায় অঙ্কটা কোথাও ছিল না — ওরা লেজার থেকে গোনে। এখন খোলা
     * ব্যালেন্সও একটা দাখিলা (OpeningBalanceService), তাই এখানে যোগ
     * করলে দ্বিগুণ হত।
     */
    public function outstanding(): string
    {
        /*
         * তালিকা withOutstanding() দিয়ে এলে নিটটা সারির সাথেই এসেছে —
         * তখন আবার কোয়েরি চালানো মানে ৫০ সারিতে ৫০টা কোয়েরি।
         */
        $net = $this->getAttribute('outstanding_net')
            ?? LedgerEntry::query()
                ->forParty('customer', $this->id)
                ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as net')
                ->value('net')
            ?? 0;

        return bcadd((string) $net, '0', 4);
    }

    /**
     * তালিকার জন্য বকেয়া — সারি প্রতি একটা নয়, পুরোটার জন্য একটা কোয়েরি।
     *
     * সাব-কোয়েরি হওয়ায় এটা দিয়ে ডাটাবেজেই সাজানো যায়। PHP-তে সাজালে
     * শুধু চলতি পাতাটা সাজত — "সবচেয়ে বেশি বকেয়া আগে" বেছে নিয়েও
     * ব্যবহারকারী দ্বিতীয় পাতায় আরও বড় অঙ্ক পেতেন।
     */
    public function scopeWithOutstanding(Builder $query): Builder
    {
        $net = LedgerEntry::query()
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0)')
            ->whereColumn('ledger_entries.party_id', 'customers.id')
            ->where('ledger_entries.party_type', self::drillSourceType());

        // customers.* না দিলে addSelect শুধু সাব-কোয়েরিটাই আনত
        return $query->addSelect(['customers.*', 'outstanding_net' => $net]);
    }

    /**
     * এই বিলটা করলে ক্রেডিট লিমিট ছাড়াবে কি না।
     *
     * লিমিট শূন্য মানে সীমাহীন, বন্ধ নয় — শূন্যকে "কিছুই বাকি রাখা যাবে না"
     * ধরলে নতুন গ্রাহকের প্রথম বিলটাই আটকে যেত।
     */
    public function wouldExceedCreditLimit(string $additional): bool
    {
        if (bccomp((string) $this->credit_limit, '0', 4) === 0) {
            return false;
        }

        return bccomp(bcadd($this->outstanding(), $additional, 4), (string) $this->credit_limit, 4) > 0;
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'customer';
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
        return ['customer.show', ['customer' => $this->id]];
    }
}
