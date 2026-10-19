<?php

declare(strict_types=1);

namespace App\Modules\Approval\Services;

use App\Core\Services\PaperParties;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\Branch;
use App\Modules\Accounts\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/**
 * অনুমোদনের সারিতে কাগজের তিনটা কথা — কার, কী বাবদ, কোথায়।
 *
 * ── কেন, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * ইনবক্সে ছিল তারিখ, কাজের নাম, কে চেয়েছেন আর অঙ্ক। মালিক ভাউচারের
 * তালিকা নিয়ে যা বলেছিলেন, এখানেও তাই খাটে: *"kothy theke eseche kothay
 * joma holo ro kichu bistarito kolam dewar dorkar"*। ⓘ কার টাকা আর কোথায়
 * যাচ্ছে না জানলে "অনুমোদন" বোতামটা চাপা মানে চোখ বুজে সই করা, আর তখন
 * প্রতিটা কাগজ আলাদা করে খুলতে হত।
 *
 * ── কেন একটা সেবা, আর ভিউতে নয় ──────────────────────────────────────
 * ইনবক্সে দশ রকমের কাগজ আসে (ভাউচার, মূলধন, উত্তোলন, হাতধার…), আর
 * প্রতিটার ঘরের নাম আলাদা। সারি ধরে খুঁজলে পঞ্চাশটা সারিতে দুইশো কোয়েরি
 * হত ([[N+1]]), তাই কাগজগুলো ধরন ধরে একবারে তোলা হয়।
 *
 * ⚠️ নাম ধরে ঘর খোঁজা হয় — `narration`, `person`, `moneyAccount` — কারণ
 * অনুমোদনযোগ্য কাগজের কোনো সাধারণ চুক্তি (interface) নেই। যে কাগজে ঘরটা
 * নেই, তার ঘর খালি থাকে; ⛔ কিছু অনুমান করা হয় না, কারণ ভুল নাম দেখানোর
 * চেয়ে খালি ঘর ভালো।
 */
final class ApprovalFacts
{
    /** কোন কাগজের লাইনে খাত থাকে — একবার দেখে মনে রাখা হয়। */
    private static array $linesCarryAccounts = [];

    /** কোন কাগজে কোন পক্ষ-সম্পর্ক সত্যিই সম্পর্ক — একবার দেখে মনে রাখা হয় ([[fallbackRelations()]])। */
    private static array $partyRelations = [];

    /** পক্ষের নিজের সম্পর্ক — পুরনো ক্রমে: ব্যক্তি, গ্রাহক, সরবরাহকারী, কর্মী */
    private const PARTY_RELATIONS = ['person', 'customer', 'supplier', 'employee'];

    /** টাকার খাত যে ঘরগুলোয় বসতে পারে, অগ্রাধিকারের ক্রমে। */
    private const ACCOUNT_FIELDS = ['money_account_id', 'account_id', 'bank_account_id', 'to_account_id', 'from_account_id'];

    /**
     * ⛔ দেখার দেয়াল — সইয়ের কাগজ পড়তে এগুলো তোলা হয় (মালিক, ৮ অক্টোবর ২০২৬: *"Receipt voucher approval e
     * full details & voucher vew dekhay na … Party, What for, Where egulo astechena"*)।
     *
     * ⓘ মালিকের হেডারে সুপার বাছা, আর কাউসারের লায়নের রসিদ RCV-0010 সইয়ের জন্য তাঁর কাছেই — তবু কাগজটা
     * শাখার দেয়ালে লুকাত: ঘর খালি, পাতায় না ভাউচার, না সারি। ⚠️ যাঁর কাছে কাগজটা পড়ে (সইকারী বা
     * অনুরোধকারী) তাঁকে কাগজটা দেখতেই হয় — না দেখে সই চাওয়া যায় না। কোম্পানির দেয়াল (টেন্যান্ট) থাকে।
     * ⓘ ডাকেন কেবল সেই পথগুলো যারা আগেই "ইনি সিদ্ধান্ত দেন বা নিজের অনুরোধ" যাচাই করেছে — তাঁরাই
     * [[of()]]-এ `$pastWalls = true` দেন। ⛔ ৯ অক্টোবর ২০২৬ (অডিট): ইনবক্সে `?person=` দিয়ে অন্যের সারি দেখা
     * রিপোর্ট-পাঠকও এই সেবা ডাকেন, আর ডিফল্ট খোলা থাকায় অন্য শাখার কাগজের পক্ষ-অঙ্ক তাঁর চোখে পড়ত।
     */
    public const VIEW_WALLS = ['user-branch', 'user-warehouse', 'viewed-branch-warehouse'];

    public function __construct(private readonly PaperParties $parties) {}

    /**
     * @param  iterable<Approval>  $approvals
     * @return array<int, array{party: ?string, about: ?string, where: ?string, branch: ?string}>
     */
    public function of(iterable $approvals, bool $pastWalls = false): array
    {
        $idsByType = [];
        $rows = [];

        foreach ($approvals as $approval) {
            $rows[(int) $approval->id] = ['no' => null, 'party' => null, 'about' => null, 'where' => null, 'branch' => null];

            if (is_string($approval->approvable_type) && class_exists($approval->approvable_type)) {
                $idsByType[$approval->approvable_type][(int) $approval->id] = (int) $approval->approvable_id;
            }
        }

        $loaded = [];

        foreach ($idsByType as $class => $ids) {
            if (! is_subclass_of($class, Model::class)) {
                continue;
            }

            $documents = $class::query()
                ->when($pastWalls, fn ($q) => $q->withoutGlobalScopes(self::VIEW_WALLS))
                /*
                 * ⓘ ভাউচারে টাকার খাতটা মাথায় নয়, দাখিলার লাইনে — তাই
                 * লাইনগুলোও একবারেই আসে।
                 *
                 * ⚠️ `lines()` থাকা মানেই `lines.account` থাকা নয় — ২০
                 * সেপ্টেম্বর ২০২৬, abos-d1-এর ধরা। বিক্রয় বিলের লাইনে
                 * `account` সম্পর্কটা নেই, আর ওরকম একটা অনুরোধ ইনবক্সে এলে
                 * Eloquent ছুঁড়ত — অর্থাৎ পুরো ইনবক্স ৫০০। তাই লাইনের
                 * মডেলটাকে জিজ্ঞেস করা হয়, কাগজটাকে নয়।
                 */
                ->when($this->linesCarryAccounts($class), fn ($q) => $q->with('lines.account'))
                ->whereKey(array_values(array_unique($ids)))
                ->get()
                ->keyBy(fn (Model $m) => (int) $m->getKey());

            $loaded[$class] = [$ids, $documents];
        }

        /*
         * ⭐ পক্ষ — গোটা পাতার সব ধরনের কাগজের জন্য **একবারে** (মালিক, ১৯ অক্টোবর ২০২৬: *"নম্বরের পাশে গ্রাহকের নাম আর
         * পয়েন্টের নাম"*)। ⛔ আগে কাগজের ধরন প্রতি আলাদা গ্রাহক-প্রশ্ন, আর সরবরাহকারী/ব্যক্তি সারি ধরে তোলা হত (N+1);
         * এখন পক্ষের ধরন প্রতি একটা প্রশ্ন ([[PaperParties::of()]]) — গ্রাহক হলে "নাম · পয়েন্ট", বাকিরা নাম।
         */
        $partyLabels = $this->parties->of(collect($loaded)->flatMap(fn (array $pair) => $pair[1]->all()));

        foreach ($loaded as $class => [$ids, $documents]) {
            $this->loadFallbackParties($class, $documents, $partyLabels);
            $accountNames = $this->accountNames($documents);
            $branchNames = $this->branchNames($documents);

            foreach ($ids as $approvalId => $documentId) {
                $document = $documents->get($documentId);

                if ($document !== null) {
                    $rows[$approvalId] = [
                        // ⓘ কাগজের নম্বর — সারি দেখেই বোঝা যায় কোন চালান (S-0008 না S-0009), ২৯ সেপ্টেম্বর ২০২৬
                        'no' => method_exists($document, 'drillDocumentNo') ? (string) $document->drillDocumentNo() : null,
                        'party' => $this->party($document, $partyLabels),
                        'about' => $this->about($document),
                        'where' => $this->where($document, $accountNames),
                        // ⭐ কোন শাখার কাগজ — মালিক, ১৪ অক্টোবর ২০২৬: *"kon branch e joma tar ullekh nai"*
                        'branch' => $branchNames[(int) ($document->getAttribute('branch_id') ?? 0)] ?? null,
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * কাগজের পক্ষ — আগেই একবারে তোলা নাম ([[PaperParties::of()]]), না থাকলে কাগজে হাতে লেখা নাম।
     *
     * ⓘ পক্ষ পড়া হয় কাগজের নিজের ঘর থেকে — `party_type`/`party_id` জোড়া (ভাউচার, চেক), নয়তো `customer_id`,
     * `supplier_id`, `employee_id`, `person_id` (চালান, ক্রয়ের বিল, খরচের দাবি, উত্তোলন)। ⭐ গ্রাহক হলে "নাম · পয়েন্ট" —
     * মালিক, ৭ অক্টোবর ২০২৬: *"app e sob jaygay customer er pase obosoi point dibe nahoy cina zayna"*।
     * ⛔ আগে সরবরাহকারী আর ব্যক্তি সারি ধরে সম্পর্ক দিয়ে তোলা হত — পঞ্চাশ সারির ক্রয়-ইনবক্সে পঞ্চাশটা প্রশ্ন; আর কর্মীর
     * কাগজে (খরচের দাবি, ছুটি) পক্ষ "—" দেখাত।
     *
     * @param  array<string, string>  $partyLabels
     */
    private function party(Model $document, array $partyLabels): ?string
    {
        $label = $partyLabels[PaperParties::slot($document)] ?? null;

        if ($label !== null) {
            return $label;
        }

        // ⓘ নাম না মেলা কাগজ — আগের পথ, তবে কেবল আগেই একবারে তোলা সম্পর্ক ([[loadFallbackParties()]]); সারি ধরে কখনো নয়
        foreach (self::PARTY_RELATIONS as $relation) {
            if ($document->relationLoaded($relation) && $document->getRelation($relation) instanceof Model) {
                $party = $document->getRelation($relation);
                $point = method_exists($party, 'pointName') ? $party->pointName() : null;

                return $this->nameOf($party).($point === null ? '' : ' · '.$point);
            }
        }

        return $this->firstFilled($document, ['payee_name', 'holder_name', 'party_name']);
    }

    /**
     * ⛔ পিছিয়ে না পড়া — যে কাগজের পক্ষ [[PaperParties]] চিনল না (পক্ষের ধরনটা নিবন্ধিত নয়: মডিউল বন্ধ, যেমন MasterData-র
     * `person`), তার জন্য আগের মতো কাগজের নিজের সম্পর্ক — ব্যক্তি, গ্রাহক (পয়েন্টসহ), সরবরাহকারী, কর্মী।
     *
     * ⓘ কেবল নাম-না-মেলা সারিগুলোর জন্য, আর গোটা দলের জন্য একবারে (`load()`, সম্পর্ক প্রতি একটা প্রশ্ন) — সারি ধরে নয়।
     * সবার নাম মিললে একটা প্রশ্নও যায় না।
     *
     * @param  class-string<Model>  $class
     * @param  \Illuminate\Database\Eloquent\Collection<int, Model>  $documents
     * @param  array<string, string>  $partyLabels
     */
    private function loadFallbackParties(string $class, $documents, array $partyLabels): void
    {
        $unnamed = $documents->filter(fn (Model $d) => ! isset($partyLabels[PaperParties::slot($d)]));
        $relations = $this->fallbackRelations($class);

        if ($unnamed->isEmpty() || $relations === []) {
            return;
        }

        $with = array_map(
            fn (string $r) => $r === 'customer' && method_exists($unnamed->first()->customer()->getRelated(), 'location')
                ? 'customer.location.parent'
                : $r,
            $relations,
        );

        try {
            $unnamed->load($with);
        } catch (Throwable $e) {
            // ⓘ সম্পর্ক তোলা গেল না — তখন হাতে লেখা নাম (নিচে), ইনবক্স ৫০০ নয়
            report($e);
        }
    }

    /**
     * এই কাগজের কোন পক্ষ-নামের পদ্ধতি সত্যিই Eloquent সম্পর্ক।
     *
     * ⚠️ `method_exists` যথেষ্ট নয় — একই নামের সাধারণ পদ্ধতি `load()`-কে ছুঁড়তে বাধ্য করত।
     *
     * @param  class-string<Model>  $class
     * @return list<string>
     */
    private function fallbackRelations(string $class): array
    {
        if (! array_key_exists($class, self::$partyRelations)) {
            $found = [];

            foreach (self::PARTY_RELATIONS as $relation) {
                if (! method_exists($class, $relation)) {
                    continue;
                }

                try {
                    if ((new $class)->{$relation}() instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                        $found[] = $relation;
                    }
                } catch (Throwable) {
                    // ⓘ পড়া গেল না — না-চাওয়াই নিরাপদ
                }
            }

            self::$partyRelations[$class] = $found;
        }

        return self::$partyRelations[$class];
    }

    private function about(Model $document): ?string
    {
        $written = $this->firstFilled($document, ['narration', 'purpose', 'note', 'description', 'title']);

        if ($written !== null) {
            return $written;
        }

        /*
         * ⓘ কেউ কিছু না লিখলে — কাগজের পণ্য, ২৯ সেপ্টেম্বর ২০২৬। ⛔ কাউন্টারের চালান আর
         * বিলে বিবরণ প্রায় কখনো থাকে না, তাই "কী বাবদ" ঘরটা "—" দেখাত; অথচ উত্তরটা
         * সারিতেই আছে — প্রথম পণ্য, আর বাকি কয়টা।
         */
        if (! $this->has($document, 'lines')) {
            return null;
        }

        $lines = $document->lines;
        $first = $lines->first(fn ($line) => $line instanceof Model && $this->has($line, 'product') && $line->product !== null);

        if ($first === null) {
            return null;
        }

        $name = (string) $this->nameOf($first->product);

        return $lines->count() > 1
            ? __('approval::field.and_more', ['first' => $name, 'count' => $lines->count() - 1])
            : $name;
    }

    /**
     * খাতের নামগুলো একবারে — যে কাগজে খাতের ঘর সরাসরি বসে।
     *
     * ⚠️ ভাউচারে `money_account_id` আছে অথচ কোনো সম্পর্ক নেই, তাই কেবল
     * সম্পর্ক ধরে খুঁজলে ঘরটা চিরকাল খালি থাকত — আর ঠিক ভাউচারই
     * ইনবক্সে সবচেয়ে বেশি আসে।
     *
     * @param  Collection<int, Model>  $documents
     * @return array<int, string>
     */
    private function accountNames($documents): array
    {
        $ids = [];

        foreach ($documents as $document) {
            foreach (self::ACCOUNT_FIELDS as $field) {
                $id = (int) ($document->getAttribute($field) ?? 0);

                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        return Account::query()
            ->whereKey(array_unique($ids))
            ->get()
            ->mapWithKeys(fn (Account $a) => [(int) $a->getKey() => $a->name()])
            ->all();
    }

    /**
     * টাকাটা কোন খাতে বা কোন ব্যাংকে — যেটা কাগজে লেখা আছে।
     *
     * @param  array<int, string>  $accountNames
     */
    private function where(Model $document, array $accountNames): ?string
    {
        /*
         * দাখিলার লাইনে যে খাতটা নগদ · ব্যাংক · মোবাইল, সেটাই "কোথায়" —
         * ভাউচারের তালিকাও ঠিক এভাবেই পড়ে।
         */
        if ($this->has($document, 'lines') && $this->linesCarryAccounts($document::class)) {
            $money = $document->lines
                ->map(fn ($line) => $line->account)
                ->first(fn ($account) => $account !== null && $account->money_kind !== null);

            if ($money !== null) {
                return $this->nameOf($money);
            }
        }

        foreach (['moneyAccount', 'account', 'bankAccount'] as $relation) {
            if ($this->has($document, $relation) && $document->{$relation} !== null) {
                return $this->nameOf($document->{$relation});
            }
        }

        foreach (self::ACCOUNT_FIELDS as $field) {
            $id = (int) ($document->getAttribute($field) ?? 0);

            if ($id > 0 && isset($accountNames[$id])) {
                return $accountNames[$id];
            }
        }

        return null;
    }

    /**
     * শাখার নামগুলো একবারে — কাগজের নিজের `branch_id` ধরে, কোম্পানির ভিতরে (দেখার দেয়াল ছাড়া: সইকারী কাগজটা দেখেনই)।
     *
     * @param  Collection<int, Model>  $documents
     * @return array<int, string>
     */
    private function branchNames($documents): array
    {
        $ids = $documents->map(fn (Model $d) => (int) ($d->getAttribute('branch_id') ?? 0))->filter()->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        return Branch::query()->withoutGlobalScopes()
            ->where('company_id', CompanyContext::id())
            ->whereKey($ids)
            ->get()
            ->mapWithKeys(fn (Branch $b) => [(int) $b->getKey() => $b->name()])
            ->all();
    }

    /** @param list<string> $fields */
    private function firstFilled(Model $document, array $fields): ?string
    {
        foreach ($fields as $field) {
            $value = $document->getAttribute($field);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    private function nameOf(object $row): ?string
    {
        foreach (['name', 'drillLabel'] as $method) {
            if (method_exists($row, $method)) {
                $value = $row->{$method}();

                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        $value = $row->name ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * এই কাগজের লাইনে খাতের সম্পর্ক আছে কি না — লাইনের মডেলকে জিজ্ঞেস করে।
     *
     * ⓘ ভাউচারের লাইনে আছে, বিক্রয় বা ক্রয়ের বিলের লাইনে নেই (ওখানে
     * পণ্য থাকে, খাত নয়)। ⛔ কাগজটার নিজের `lines()` দেখে সিদ্ধান্ত নিলে
     * বিক্রয় বিলের একটা অনুরোধেই পুরো ইনবক্স ৫০০ দিত।
     *
     * @param  class-string<Model>  $class
     */
    private function linesCarryAccounts(string $class): bool
    {
        if (! method_exists($class, 'lines')) {
            return false;
        }

        if (! array_key_exists($class, self::$linesCarryAccounts)) {
            try {
                $related = (new $class)->lines()->getRelated();

                self::$linesCarryAccounts[$class] = method_exists($related, 'account');
            } catch (Throwable) {
                // ⓘ সম্পর্কটা পড়া গেল না — তখন না-চাওয়াই নিরাপদ
                self::$linesCarryAccounts[$class] = false;
            }
        }

        return self::$linesCarryAccounts[$class];
    }

    /**
     * ঘর বা সম্পর্কটা এই কাগজে আছে কি না।
     *
     * ⚠️ `isset($model->relation)` সম্পর্ককে ডেকে ফেলে, তাই সম্পর্কের
     * জন্য `method_exists` আগে দেখা হয় — নাহলে যে কাগজে সম্পর্কটা নেই
     * সেখানে Eloquent ছুঁড়ত।
     */
    private function has(Model $document, string $name): bool
    {
        return method_exists($document, $name);
    }
}
