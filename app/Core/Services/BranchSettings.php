<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\BranchSetting;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * শাখার নিজের সেটিং — কোম্পানির সেটিংয়ের উপরে একটা বদলের স্তর।
 *
 * ── ⭐ মালিকের নির্দেশ, ৩০ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"inv info, iNVOICE lOGO protiti branch ER JONNO ALADA ALADA HOBE, PRINT TAMPLATE ALADA HOBE … KARON ALADA ALADA
 * BRANCH E ALADA TYPE BUSINESS HOTEPARE"* আর *"INVOICE LOGO ALADA UPLOAD MUST"*।
 *
 * ── ⓘ কোন সেটিং শাখা ধরে বদলায় ──────────────────────────────────────────
 * কেবল যার ঘোষণায় `'per_branch' => true` (মডিউলের `module.php`) — বিলের তথ্য, সুইচ, সই, নকশা, কাগজ, বিলের লোগো।
 * ⛔ বাকি কোনো সেটিং শাখা ধরে বদলায় না: বাকির সীমা বা অনুমোদনের নিয়ম শাখায় শাখায় আলাদা হলে কোম্পানির নিয়মটাই
 * আর নিয়ম থাকত না।
 *
 * ── ⭐ ছাপার সময় কোন শাখা ───────────────────────────────────────────────
 * কাগজটা যে শাখার, সেটা — কে ছাপছেন বা এখন কোন শাখা দেখছেন তা নয় ([[during()]])। ছাপার কন্ট্রোলার কাগজের
 * `branch_id` দিয়ে ছাপার কাজটা ঘিরে দেয়; তার ভেতরে [[get()]] ঐ শাখার বদল দেখে।
 *
 * ⚠️ নতুন ক্লাস, [[SettingsService]]-এ নতুন পদ্ধতি নয়: চলমান টেস্ট-রান পুরনো SettingsService আগেই লোড করে রাখে,
 * আর নতুন পদ্ধতির ডাক এলে প্রতিটা বাকি টেস্ট ভাঙত (৩০ সেপ্টেম্বরের শিক্ষা)।
 */
final class BranchSettings
{
    /** ছাপার বিলের লোগোর সেটিং — সব কাগজের মাথায় একই লোগো (SystemAdmin ঘোষণা করে) */
    public const INVOICE_LOGO = 'print.invoice_logo';

    /** এখন যে কাগজ ছাপা হচ্ছে তার শাখা — [[during()]] বসায়, ছাপা শেষে ফেরায় */
    private static ?int $printing = null;

    /**
     * ছাপার সময় ঐ শাখার সব বদল, একবারে পড়া — key → মান। ⓘ কেবল [[during()]]-এর ভেতরে; শেষে মোছে।
     * ⛔ স্থায়ী স্মৃতি নয়: টেস্টে একই প্রক্রিয়া বহু অনুরোধ চালায়, আর আগের অনুরোধের মান পরেরটায় বসে যেত।
     *
     * @var array<string, mixed>|null
     */
    private static ?array $rows = null;

    public function __construct(private readonly SettingsService $settings) {}

    public function isPerBranch(string $key): bool
    {
        return (bool) ($this->settings->definitions()[$key]['per_branch'] ?? false);
    }

    /**
     * মান — শাখার বদল থাকলে সেটা, নাহলে কোম্পানির। ⓘ শাখা না দিলে এখন যে কাগজ ছাপা হচ্ছে তার শাখা।
     */
    public function get(string $key, ?int $branchId = null): mixed
    {
        $branchId ??= self::$printing;

        if ($branchId !== null && $this->isPerBranch($key)) {
            $own = $this->own($key, $branchId);

            if ($own !== null) {
                return $own;
            }
        }

        return $this->settings->get($key);
    }

    /** কেবল শাখার নিজের বসানো মান — না থাকলে null ("কোম্পানির মতো") */
    public function own(string $key, int $branchId): mixed
    {
        if (self::$rows !== null && $branchId === self::$printing) {
            return self::$rows[$key] ?? null;
        }

        return BranchSetting::query()
            ->where('company_id', CompanyContext::id())
            ->where('branch_id', $branchId)
            ->where('key', $key)
            ->first()
            ?->typedValue();
    }

    /**
     * শাখার বদল বসানো। ⛔ শাখাটা এই কোম্পানিরই হতে হবে, আর সেটিংটা শাখা ধরে বদলানোর মতো ঘোষিত হতে হবে।
     */
    public function set(string $key, int $branchId, mixed $value): void
    {
        $definition = $this->assertBranchable($key, $branchId);
        $type = (string) ($definition['type'] ?? 'string');

        /*
         * ⛔ শাখার সারিতেও তালিকার বাইরের মান নয় — ৩ অক্টোবর ২০২৬, [[SettingsService::set()]]-এর একই পাহারা।
         * ⓘ লাইভে কোম্পানির সারিতে `"0"` বসে মালিকের ছাপার নকশা নীরবে বন্ধ হয়েছিল; নকশা আর কাগজ শাখা ধরেও
         * বসে, তাই এই দরজাটা খোলা রাখলে একই ঘটনা শাখার স্তরে ফিরে আসত।
         */
        if (! SettingOptions::allows($definition, $value)) {
            throw new InvalidArgumentException(sprintf(
                "Setting '%s' cannot be '%s' for branch %d: it offers only [%s].",
                $key,
                is_scalar($value) ? (string) $value : get_debug_type($value),
                $branchId,
                implode(', ', SettingOptions::of($definition) ?? []),
            ));
        }

        DB::transaction(function () use ($key, $branchId, $value, $type) {
            BranchSetting::query()->updateOrCreate(
                ['company_id' => CompanyContext::id(), 'branch_id' => $branchId, 'key' => $key],
                ['type' => $type, 'value' => match ($type) {
                    'boolean' => $value ? '1' : '0',
                    'json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    default => (string) $value,
                }],
            );
        });

        self::$rows = null;
    }

    /** "কোম্পানির মতো"-তে ফেরা — মডেল ধরে মোছা, যাতে নিরীক্ষায় ওঠে */
    public function reset(string $key, int $branchId): void
    {
        $this->assertBranchable($key, $branchId);

        BranchSetting::query()
            ->where('company_id', CompanyContext::id())
            ->where('branch_id', $branchId)
            ->where('key', $key)
            ->get()
            ->each(fn (BranchSetting $row) => $row->delete());

        self::$rows = null;
    }

    /**
     * একটা কাগজ ছাপার কাজটা তার শাখার সেটিংয়ে চালানো। ⓘ শেষে আগের অবস্থায় ফেরে — ব্যতিক্রম হলেও।
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function during(?int $branchId, Closure $work): mixed
    {
        [$before, $beforeRows] = [self::$printing, self::$rows];
        self::$printing = $branchId;
        self::$rows = $branchId === null ? null : BranchSetting::query()
            ->where('company_id', CompanyContext::id())
            ->where('branch_id', $branchId)
            ->get()
            ->mapWithKeys(fn (BranchSetting $row) => [$row->key => $row->typedValue()])
            ->all();

        try {
            return $work();
        } finally {
            [self::$printing, self::$rows] = [$before, $beforeRows];
        }
    }

    public function printingBranch(): ?int
    {
        return self::$printing;
    }

    /**
     * বিলের লোগো — শাখার নিজেরটা, নাহলে কোম্পানির বিলের লোগো; দুইটাই না থাকলে null (তখন প্রোফাইলের লোগো)।
     */
    public function invoiceLogoPath(?int $branchId = null): ?string
    {
        $path = trim((string) $this->get(self::INVOICE_LOGO, $branchId));

        return $path === '' ? null : $path;
    }

    /** @return array<string, mixed> */
    private function assertBranchable(string $key, int $branchId): array
    {
        $definition = $this->settings->definitions()[$key] ?? null;

        if ($definition === null || ! ($definition['per_branch'] ?? false)) {
            throw new InvalidArgumentException("Setting '{$key}' is not declared per_branch.");
        }

        $belongs = Branch::query()->whereKey($branchId)->where('company_id', CompanyContext::id())->exists();

        if (! $belongs) {
            throw new InvalidArgumentException("Branch {$branchId} is not in the current company.");
        }

        return $definition;
    }
}
