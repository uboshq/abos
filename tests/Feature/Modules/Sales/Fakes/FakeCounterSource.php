<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales\Fakes;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\ScopedToUserBranch;
use App\Modules\Sales\Contracts\CounterSaleSource;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * পরীক্ষার নকল উৎস — কাউন্টার কেবল চুক্তি চেনে ([[CounterSaleSource]]), তাই DeliveryOrder (abos-2c) আসার আগেই সব দাবি
 * প্রমাণ করা যায়।
 *
 * ⓘ সারিটা `sal_orders`-এ বসে — কেবল পরিচয় আর দেয়ালের জন্য: কোম্পানি ([[BelongsToCompany]]) আর শাখা
 * ([[ScopedToUserBranch]]) আসল মডেলের মতোই। অবস্থা `status`-এ (DO-র একই নামে), বিল `narration`-এ (`invoice:ID`) —
 * লেনদেন ফেরালে দুইটাই ফেরে। লাইন [[$lines]]-এ, পরীক্ষা বসায়।
 */
final class FakeCounterSource extends Model implements CounterSaleSource
{
    use BelongsToCompany;
    use HasPublicId;
    use ScopedToUserBranch;

    public const READY = 'accounts_approved';

    public const DEPOT = 'depot_check';

    public const INVOICED = 'invoiced';

    protected $table = 'sal_orders';

    protected $guarded = [];

    /** @var array<int, list<array<string, mixed>>> উৎসের আইডি → কাউন্টারের লাইন */
    public static array $lines = [];

    /** @var list<array{source: int, invoice: int}> প্রতিটা সত্যিকারের "বিল হয়েছে" */
    public static array $marked = [];

    public static function counterSourceKey(): string
    {
        return 'fake';
    }

    public function assertReadyForCounter(): void
    {
        $now = (string) static::query()->withoutGlobalScopes()->whereKey($this->getKey())->lockForUpdate()->value('status');

        if (! in_array($now, [self::READY, self::DEPOT], true)) {
            throw ValidationException::withMessages(['source' => 'নকল উৎস খোলার মতো নয় — এখন: '.$now]);
        }
    }

    public function counterScreen(): array
    {
        return [
            'ref' => (string) $this->document_no,
            'customer_id' => (int) $this->customer_id,
            'warehouse_id' => $this->warehouse_id === null ? null : (int) $this->warehouse_id,
            'lines' => self::$lines[(int) $this->getKey()] ?? [],
        ];
    }

    public function enterDepotCheck(): void
    {
        static::query()->withoutGlobalScopes()->whereKey($this->getKey())
            ->where('status', self::READY)->update(['status' => self::DEPOT]);
    }

    /** ⓘ চুক্তির বাইরে — কাউন্টার থাকলে ডাকে (রাখা খসড়া বাতিল); DeliveryOrder-এ abos-2c যোগ করবেন */
    public function leaveDepotCheck(): void
    {
        static::query()->withoutGlobalScopes()->whereKey($this->getKey())
            ->where('status', self::DEPOT)->update(['status' => self::READY]);
    }

    public function markInvoiced(SalesInvoice $invoice): void
    {
        $row = static::query()->withoutGlobalScopes()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

        if ($row->status === self::INVOICED) {
            if ($row->narration === 'invoice:'.$invoice->getKey()) {
                return;
            }

            throw ValidationException::withMessages(['source' => 'নকল উৎসের বিল আগেই হয়েছে, অন্য বিলে।']);
        }

        static::query()->withoutGlobalScopes()->whereKey($this->getKey())
            ->update(['status' => self::INVOICED, 'narration' => 'invoice:'.$invoice->getKey()]);

        self::$marked[] = ['source' => (int) $this->getKey(), 'invoice' => (int) $invoice->getKey()];
    }
}
