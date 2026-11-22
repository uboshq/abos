<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\User;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ বিক্রিটা কার — দায়িত্বে থাকা মানুষের, বিল যিনি কেটেছেন তাঁর নয় (মালিক, ২৩ নভেম্বর ২০২৬)।
 *
 * ⓘ আন্তর্জাতিক নিয়ম: SAP partner function "sales employee", D365 "sales responsible", Odoo salesperson।
 *   অফিসে বসে যিনি বিল কাটেন তিনি `created_by` ("বিল করেছেন"); কৃতিত্ব, লক্ষ্যমাত্রা আর কমিশন যায় এখানকার উত্তরে।
 *
 * ক্রম — প্রথম যেটা মেলে:
 *   (ক) বিলের দিনে ডিলারে বাঁধা মানুষ ([[DealerOwnership::boundOn()]] — একই নিয়ম, একই উত্তর)।
 *   (খ) ডিলারের নিজের জায়গা থেকে উপরে হাঁটা (পয়েন্ট › টেরিটরি › এরিয়া …) — **প্রথম** যে জায়গায় সেদিন কেউ
 *       এলাকায় বাঁধা ([[AreaBinding]]), সেখানকার মানুষরা প্রার্থী। ⛔ প্রার্থীদের কেউ যদি আরেক প্রার্থীর উপরওয়ালা হন
 *       (সরাসরি বা কয়েক ধাপ উপরে, `staff_supervisors`), তিনি বাদ — উপরওয়ালা পান যোগফল, সরাসরি কৃতিত্ব নয়।
 *       লাইভ ADI-তে DSM ২২ দেখার জন্য ASM-দের একই টেরিটরিতে বাঁধা; তাই এই নিয়ম ছাড়া বিক্রি DSM-এর খাতায় উঠত।
 *       বাকিদের মধ্যে যাঁর বাঁধন আগে শুরু, সমান হলে যাঁর সারি আগে।
 *   (গ) কোম্পানির সেটিং `sales.default_sales_person`।
 *   (ঘ) কেউ নন — `null`, বিক্রিটা কারো খাতায় ওঠে না ("নেই")।
 *
 * ⛔ যিনিই ফেরেন, এই কোম্পানির সদস্য (`company_user`-এ সারি আছে)। অন্য কোম্পানির আইডি বাঁধনে বা সেটিংয়ে
 *   ভুল করে বসে থাকলেও কখনো ফেরে না — সেই ধাপটা না-মেলা ধরা হয়, পরের ধাপে যায়।
 * ⓘ সদস্যপদ এখন চালু না থাকলেও (চলে গেছেন) পুরনো বিক্রি তাঁরই — ইতিহাস বদলায় না; সারিটাই না থাকলে বাইরের লোক।
 * ⓘ একই অনুরোধে উত্তর মনে থাকে; জায়গার গাছ, এলাকার বাঁধন আর উপরওয়ালার তালিকা কোম্পানি-প্রতি একবার পড়া হয়,
 *   তাই হাজার ডিলারেও জায়গা হাঁটায় প্রতি-ডিলার কোয়েরি নেই।
 */
#[Scoped]
final class SalesResponsibility
{
    public const SETTING = 'sales.default_sales_person';

    /** @var array<string, array{user_id: ?int, via: ?string, location_id: ?int}> */
    private array $memo = [];

    /** @var array<int, array<int, true>> কোম্পানি → সদস্যদের আইডি */
    private array $members = [];

    /** @var array<int, array<int, int>> কোম্পানি → জায়গা → বাবা */
    private array $parents = [];

    /** @var array<int, array<int, list<array{id: int, user_id: int, starts_on: string, ends_on: ?string}>>> কোম্পানি → জায়গা → বাঁধন */
    private array $areas = [];

    /** @var array<int, array<int, int>> কোম্পানি → মানুষ → উপরওয়ালা */
    private array $above = [];

    /** ⭐ এই দিনে এই ডিলারের বিক্রি কার — ব্যবহারকারীর আইডি, নাহলে `null`। কোম্পানি [[CompanyContext]] থেকে। */
    public function on(int $customerId, \DateTimeInterface|string $date): ?int
    {
        return $this->explain($customerId, $date)['user_id'];
    }

    /**
     * ⭐ উত্তর আর কোন পথে এল — পর্দা আর আদেশের "কেন" দেখানোর জন্য।
     *
     * @return array{user_id: ?int, via: 'dealer'|'place'|'default'|null, location_id: ?int}
     */
    public function explain(int $customerId, \DateTimeInterface|string $date): array
    {
        $companyId = (int) CompanyContext::id();

        return $this->resolveMany($companyId, [$customerId], self::day($date))[$customerId];
    }

    /**
     * ⭐ অনেক ডিলার একসাথে, একই দিনে — ডিলারের বাঁধন এক কোয়েরিতে, জায়গা হাঁটা স্মৃতিতে।
     *
     * @param  list<int>|array<int|string, int>  $customerIds
     * @return array<int, ?int> ডিলার → মানুষ
     */
    public function forMany(array $customerIds, \DateTimeInterface|string $date): array
    {
        $companyId = (int) CompanyContext::id();
        $ids = array_values(array_unique(array_map('intval', $customerIds)));

        return array_map(fn (array $r) => $r['user_id'], $this->resolveMany($companyId, $ids, self::day($date)));
    }

    /** ⭐ বিলের দিনে (`trx_date`) বিলের কোম্পানিতে — বিল যিনি কেটেছেন তাঁর কথা এখানে আসেই না। */
    public function forInvoice(SalesInvoice $i): ?int
    {
        $companyId = (int) $i->company_id;

        return $this->resolveMany($companyId, [(int) $i->customer_id], self::day($i->trx_date ?? Carbon::today()))[(int) $i->customer_id]['user_id'];
    }

    /**
     * ⭐ ফেরত মূল বিলের মানুষের খাতায় — বিক্রি যাঁর, ফেরতও তাঁর (নাহলে একজনের বিক্রি আরেকজনের ফেরতে কাটা যেত)।
     * ⓘ মূল বিল নেই বা তাতে কেউ বসানো নেই → ফেরতের দিনে নতুন করে খোঁজা।
     */
    public function forReturn(SalesReturn $r): ?int
    {
        $companyId = (int) $r->company_id;

        if ($r->sales_invoice_id !== null) {
            $original = DB::table('sal_invoices')
                ->where('company_id', $companyId)
                ->where('id', (int) $r->sales_invoice_id)
                ->value('sales_person_id');

            if ($original !== null && $this->isMember($companyId, (int) $original)) {
                return (int) $original;
            }
        }

        return $this->resolveMany($companyId, [(int) $r->customer_id], self::day($r->trx_date ?? Carbon::today()))[(int) $r->customer_id]['user_id'];
    }

    /** ⓘ বাঁধন বা সেটিং বদলের পরে একই অনুরোধে নতুন উত্তর চাইলে (আদেশ, পরীক্ষা)। */
    public function forget(): void
    {
        $this->memo = $this->members = $this->parents = $this->areas = $this->above = [];
    }

    /**
     * সেটিংয়ের বাছাই — এই কোম্পানির চালু ব্যবহারকারী ([[OwnerCapital::choices()]]-এর একই আকার)।
     *
     * @return array<string, string>
     */
    public static function choices(): array
    {
        $companyId = CompanyContext::id();

        if ($companyId === null) {
            return ['' => '—'];
        }

        return ['' => '—'] + User::query()
            ->where('is_active', true)
            ->whereHas('companies', fn ($q) => $q->whereKey($companyId))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->mapWithKeys(fn (User $u) => [(string) $u->id => (string) $u->name])
            ->all();
    }

    /**
     * @param  list<int>  $customerIds
     * @return array<int, array{user_id: ?int, via: ?string, location_id: ?int}>
     */
    private function resolveMany(int $companyId, array $customerIds, string $day): array
    {
        $out = [];
        $todo = [];

        foreach ($customerIds as $id) {
            $key = $companyId.'|'.$id.'|'.$day;
            if (isset($this->memo[$key])) {
                $out[$id] = $this->memo[$key];
            } else {
                $todo[] = $id;
            }
        }

        if ($todo === [] || $companyId === 0) {
            foreach ($todo as $id) {
                $out[$id] = ['user_id' => null, 'via' => null, 'location_id' => null];
            }

            return $out;
        }

        foreach (array_chunk($todo, 500) as $chunk) {
            // ⓘ (ক) ডিলারের বাঁধন — [[DealerOwnership::srOn()]]-এর একই সাবকোয়েরি, এক কোয়েরিতে সবার জন্য
            $rows = DB::table('customers as c')
                ->where('c.company_id', $companyId)
                ->whereIn('c.id', $chunk)
                ->select(['c.id', 'c.location_id'])
                ->selectSub(DealerOwnership::boundOn('c.id', DB::raw(DB::getPdo()->quote($day)), 'c.company_id'), 'sr')
                ->get()
                ->keyBy('id');

            foreach ($chunk as $id) {
                $row = $rows->get($id);

                // ⛔ এই কোম্পানির ডিলার নন (বা নেই) — কারো খাতায় নয়, কোম্পানির সেটিংও খাটে না
                if ($row === null) {
                    $out[$id] = $this->memo[$companyId.'|'.$id.'|'.$day] = ['user_id' => null, 'via' => null, 'location_id' => null];

                    continue;
                }

                $answer = $this->decide(
                    $companyId,
                    $row?->sr !== null ? (int) $row->sr : null,
                    $row?->location_id !== null ? (int) $row->location_id : null,
                    $day,
                );
                $out[$id] = $this->memo[$companyId.'|'.$id.'|'.$day] = $answer;
            }
        }

        return $out;
    }

    /** @return array{user_id: ?int, via: ?string, location_id: ?int} */
    private function decide(int $companyId, ?int $dealerPerson, ?int $locationId, string $day): array
    {
        if ($dealerPerson !== null && $this->isMember($companyId, $dealerPerson)) {
            return ['user_id' => $dealerPerson, 'via' => 'dealer', 'location_id' => null];
        }

        if ($locationId !== null) {
            [$person, $place] = $this->byPlace($companyId, $locationId, $day);
            if ($person !== null) {
                return ['user_id' => $person, 'via' => 'place', 'location_id' => $place];
            }
        }

        $default = $this->defaultPerson($companyId);
        if ($default !== null) {
            return ['user_id' => $default, 'via' => 'default', 'location_id' => null];
        }

        return ['user_id' => null, 'via' => null, 'location_id' => null];
    }

    /**
     * (খ) নিজের জায়গা থেকে উপরে — প্রথম যে জায়গায় সেদিন এই কোম্পানির কেউ বাঁধা।
     *
     * @return array{0: ?int, 1: ?int} মানুষ, জায়গা
     */
    private function byPlace(int $companyId, int $locationId, string $day): array
    {
        $this->loadTree($companyId);
        $parents = $this->parents[$companyId];
        $areas = $this->areas[$companyId];

        $node = $locationId;
        $seen = [];

        // ⓘ গভীরতার সীমা আর দেখা-তালিকা — নষ্ট তথ্যে চক্র থাকলেও থামে ([[Location::ancestors()]]-এর একই সাবধানতা)
        for ($depth = 0; $node !== 0 && ! isset($seen[$node]) && $depth < 12; $depth++) {
            $seen[$node] = true;

            $candidates = [];
            foreach ($areas[$node] ?? [] as $b) {
                if ($b['starts_on'] > $day || ($b['ends_on'] !== null && $b['ends_on'] < $day)) {
                    continue;
                }
                if (! $this->isMember($companyId, $b['user_id'])) {
                    continue;
                }
                $mine = $candidates[$b['user_id']] ?? null;
                if ($mine === null || [$b['starts_on'], $b['id']] < [$mine['starts_on'], $mine['id']]) {
                    $candidates[$b['user_id']] = $b;
                }
            }

            if ($candidates !== []) {
                return [$this->pick($companyId, $candidates), $node];
            }

            $node = $parents[$node] ?? 0;
        }

        return [null, null];
    }

    /**
     * ⛔ একই জায়গায় উপরওয়ালা আর তাঁর নিচের লোক — উপরওয়ালা বাদ; তারপর আগে শুরু, তারপর ছোট সারি।
     *
     * @param  array<int, array{id: int, user_id: int, starts_on: string, ends_on: ?string}>  $candidates
     */
    private function pick(int $companyId, array $candidates): int
    {
        $above = $this->above[$companyId];
        $bosses = [];

        foreach (array_keys($candidates) as $person) {
            $walk = $above[$person] ?? 0;
            $seen = [$person => true];
            while ($walk !== 0 && ! isset($seen[$walk])) {
                $seen[$walk] = true;
                if (isset($candidates[$walk])) {
                    $bosses[$walk] = true;
                }
                $walk = $above[$walk] ?? 0;
            }
        }

        // ⓘ চক্রে সবাই সবার উপরওয়ালা হলে কেউ বাদ যায় না — নষ্ট গাছে খালি হাতে ফেরার চেয়ে নিয়মের পরের ধাপ ভালো
        $left = array_diff_key($candidates, $bosses) ?: $candidates;

        usort($left, fn (array $a, array $b) => [$a['starts_on'], $a['id']] <=> [$b['starts_on'], $b['id']]);

        return $left[0]['user_id'];
    }

    /** (গ) কোম্পানির সেটিং — ⛔ কেবল এই কোম্পানির সদস্য হলে। */
    private function defaultPerson(int $companyId): ?int
    {
        $read = fn () => (int) app(SettingsService::class)->get(self::SETTING, 0);
        $id = (int) CompanyContext::id() === $companyId ? $read() : CompanyContext::forCompany($companyId, $read);

        return $id > 0 && $this->isMember($companyId, $id) ? $id : null;
    }

    private function isMember(int $companyId, int $userId): bool
    {
        if (! isset($this->members[$companyId])) {
            $this->members[$companyId] = DB::table('company_user')
                ->join('users', 'users.id', '=', 'company_user.user_id')
                ->where('company_user.company_id', $companyId)
                ->whereNull('users.deleted_at')
                ->pluck('company_user.user_id')
                ->mapWithKeys(fn ($id) => [(int) $id => true])
                ->all();
        }

        return isset($this->members[$companyId][$userId]);
    }

    /** জায়গার গাছ, এলাকার বাঁধন, উপরওয়ালা — কোম্পানি-প্রতি একবার (তিনটাই ছোট টেবিল)। */
    private function loadTree(int $companyId): void
    {
        if (isset($this->parents[$companyId])) {
            return;
        }

        $this->parents[$companyId] = DB::table('mdm_locations')
            ->where('company_id', $companyId)
            ->whereNotNull('parent_id')
            ->pluck('parent_id', 'id')
            ->mapWithKeys(fn ($p, $id) => [(int) $id => (int) $p])
            ->all();

        $areas = [];
        foreach (DB::table('area_bindings')->where('company_id', $companyId)->orderBy('id')->get(['id', 'user_id', 'location_id', 'starts_on', 'ends_on']) as $b) {
            $areas[(int) $b->location_id][] = [
                'id' => (int) $b->id,
                'user_id' => (int) $b->user_id,
                'starts_on' => substr((string) $b->starts_on, 0, 10),
                'ends_on' => $b->ends_on === null ? null : substr((string) $b->ends_on, 0, 10),
            ];
        }
        $this->areas[$companyId] = $areas;

        $this->above[$companyId] = DB::table('staff_supervisors')
            ->where('company_id', $companyId)
            ->pluck('supervisor_id', 'user_id')
            ->mapWithKeys(fn ($s, $u) => [(int) $u => (int) $s])
            ->all();
    }

    private static function day(\DateTimeInterface|string $date): string
    {
        return ($date instanceof \DateTimeInterface ? Carbon::instance($date) : Carbon::parse($date))->toDateString();
    }
}
