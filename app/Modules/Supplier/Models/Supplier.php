<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Models;

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
use App\Modules\MasterData\Models\PaymentTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * একজন সরবরাহকারী।
 *
 * গ্রাহকের আয়না, কিন্তু চিহ্ন উল্টো: গ্রাহকের কাছে আমাদের পাওনা, আর
 * সরবরাহকারীর কাছে আমাদের দেনা। লেজারে সেটা ক্রেডিট প্রকৃতির খাত,
 * তাই এখানে "বকেয়া" ধনাত্মক মানে আমরা দিতে বাকি।
 */
class Supplier extends Model implements Drillable
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
        'phone', 'email', 'address_en', 'address_bn',
        'contact_person', 'contact_phone',
        'party_type_id', 'payment_term_id', 'bin', 'tin',
        'credit_limit', 'credit_days', 'opening_balance', 'opening_date',
        'status', 'is_active', 'created_by',
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

    public function partyType(): BelongsTo
    {
        return $this->belongsTo(PartyType::class, 'party_type_id');
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class, 'payment_term_id');
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
     * নাম, কোড, ফোন বা BIN — চারটার যেকোনোটা দিয়ে খোঁজা।
     *
     * BIN-ও, কারণ ক্রয় বিল হাতে নিয়ে বসা মানুষ প্রায়ই কাগজে ওই
     * নম্বরটাই দেখতে পায়, নাম নয়।
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
                ->orWhere('phone', 'like', $like)
                ->orWhere('bin', 'like', $like);
        });
    }

    /**
     * এই সরবরাহকারীকে আমরা কত দিতে বাকি।
     *
     * পুরোটাই লেজার থেকে, চিহ্ন উল্টে: দেনা ক্রেডিট প্রকৃতির, তাই
     * ক্রেডিট বেশি হলে সংখ্যাটা ধনাত্মক হওয়া উচিত। উল্টে না দিলে
     * প্রতিটা পর্দায় "প্রদেয় −৫,০০০" দেখাত।
     *
     * opening_balance এখানে যোগ হয় না — ওটা তৈরির সময় একটা দাখিলা
     * হিসেবে খাতায় বসে গেছে (OpeningBalanceService)। যোগ করলে অঙ্কটা
     * দ্বিগুণ হত। কলামটা তবু আছে: ব্যবহারকারী কী লিখেছিল তার রেকর্ড।
     *
     * আলাদা "due" কলাম রাখা হয়নি — গ্রাহকের ক্ষেত্রেও একই সিদ্ধান্ত,
     * একই কারণে: দুই কপি একদিন আলাদা হয়, আর কোনটা সত্যি তা বলার
     * উপায় থাকে না।
     */
    public function payable(?string $upto = null): string
    {
        /*
         * তালিকা withPayable() দিয়ে এলে নিটটা সারির সাথেই এসেছে —
         * তখন আবার কোয়েরি চালানো মানে N+1 ফিরিয়ে আনা। তারিখ বলা থাকলে
         * নয়: ওই ঘরটা "আজ পর্যন্ত", অন্য কোনো দিনের নয়।
         */
        $net = $upto === null && $this->getAttribute('payable_net') !== null
            ? $this->getAttribute('payable_net')
            : LedgerEntry::query()
                ->forParty(self::drillSourceType(), $this->id)
                ->when($upto, fn (Builder $q, string $date) => $q->whereDate('trx_date', '<=', $date))
                ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as net')
                ->value('net') ?? 0;

        return bcadd((string) $net, '0', 4);
    }

    /**
     * তালিকার জন্য প্রদেয় — সারি প্রতি একটা নয়, পুরোটার জন্য একটা কোয়েরি।
     *
     * payable() একজনের জন্য ঠিক, কিন্তু ৫০ সারির তালিকায় ওটা ৫০টা
     * কোয়েরি চালাত। ব্যাপারটা ছোট ডেটায় চোখে পড়ে না, আর ডিপোতে
     * দুই হাজার সরবরাহকারী হওয়ার পর ধরা পড়ে — তখন কারণটা খুঁজতে হয়।
     *
     * ফল বসে payable_net-এ — খোলা ব্যালেন্স ছাড়া শুধু লেজারের নিট।
     * payable() ওই ঘরটা পেলে আর নিজে কোয়েরি চালায় না, তাই ভিউয়ের কোড
     * এক থাকে: তালিকাতেও $supplier->payable(), একক পাতাতেও।
     */
    public function scopeWithPayable(Builder $query): Builder
    {
        $net = LedgerEntry::query()
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0)')
            ->whereColumn('ledger_entries.party_id', 'suppliers.id')
            ->where('ledger_entries.party_type', self::drillSourceType());

        // suppliers.* না দিলে addSelect শুধু সাব-কোয়েরিটাই আনত
        return $query->addSelect(['suppliers.*', 'payable_net' => $net]);
    }

    /**
     * তারা যত বাকিতে দেয় তার চেয়ে বেশি নেওয়া হয়ে গেছে কি না।
     *
     * শূন্য মানে সীমা বলা নেই, বন্ধ নয়। আর এটা কিছু আটকায় না — সীমাটা
     * তাদের সিদ্ধান্ত, আমাদের নয়। শুধু ক্রয়কারীর জানা দরকার, কারণ
     * পরের চালান আটকে যেতে পারে।
     */
    public function isOverTheirLimit(): bool
    {
        if (bccomp((string) $this->credit_limit, '0', 4) === 0) {
            return false;
        }

        return bccomp($this->payable(), (string) $this->credit_limit, 4) > 0;
    }

    /** শর্ত অনুযায়ী শেষ তারিখ — শর্ত না থাকলে credit_days। */
    public function dueDateFrom(Carbon|string $invoiceDate): Carbon
    {
        if ($this->paymentTerm !== null) {
            return $this->paymentTerm->dueDateFrom($invoiceDate);
        }

        $date = $invoiceDate instanceof Carbon ? $invoiceDate->copy() : Carbon::parse($invoiceDate);

        return $date->addDays($this->credit_days);
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'supplier';
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
        return ['supplier.show', ['supplier' => $this->id]];
    }
}
