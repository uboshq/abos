<?php

declare(strict_types=1);

namespace App\Modules\Approval\Services;

use App\Core\Services\PartyRegistry;
use App\Models\Approval;
use App\Modules\Accounts\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

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
    /** টাকার খাত যে ঘরগুলোয় বসতে পারে, অগ্রাধিকারের ক্রমে। */
    private const ACCOUNT_FIELDS = ['money_account_id', 'account_id', 'bank_account_id', 'to_account_id', 'from_account_id'];

    public function __construct(private readonly PartyRegistry $parties) {}

    /**
     * @param  iterable<Approval>  $approvals
     * @return array<int, array{party: ?string, about: ?string, where: ?string}>
     */
    public function of(iterable $approvals): array
    {
        $idsByType = [];
        $rows = [];

        foreach ($approvals as $approval) {
            $rows[(int) $approval->id] = ['party' => null, 'about' => null, 'where' => null];

            if (is_string($approval->approvable_type) && class_exists($approval->approvable_type)) {
                $idsByType[$approval->approvable_type][(int) $approval->id] = (int) $approval->approvable_id;
            }
        }

        foreach ($idsByType as $class => $ids) {
            if (! is_subclass_of($class, Model::class)) {
                continue;
            }

            $documents = $class::query()
                /*
                 * ⓘ ভাউচারে টাকার খাতটা মাথায় নয়, দাখিলার লাইনে — তাই
                 * লাইনগুলোও একবারেই আসে। যে কাগজে `lines` নেই সেখানে
                 * চাওয়া হয় না, নাহলে Eloquent ছুঁড়ত।
                 */
                ->when(method_exists($class, 'lines'), fn ($q) => $q->with('lines.account'))
                ->whereKey(array_values(array_unique($ids)))
                ->get()
                ->keyBy(fn (Model $m) => (int) $m->getKey());

            $partyLabels = $this->partyLabels($documents);
            $accountNames = $this->accountNames($documents);

            foreach ($ids as $approvalId => $documentId) {
                $document = $documents->get($documentId);

                if ($document !== null) {
                    $rows[$approvalId] = [
                        'party' => $this->party($document, $partyLabels),
                        'about' => $this->about($document),
                        'where' => $this->where($document, $accountNames),
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * পক্ষের নামগুলো একবারে — `party_type`/`party_id` জোড়া ধরে।
     *
     * @param  Collection<int, Model>  $documents
     * @return array<string, string>
     */
    private function partyLabels($documents): array
    {
        $pairs = [];

        foreach ($documents as $document) {
            if (isset($document->party_type, $document->party_id)) {
                $pairs[] = [(string) $document->party_type, (int) $document->party_id];
            }
        }

        return $pairs === [] ? [] : $this->parties->labelsOf($pairs);
    }

    /** @param array<string, string> $partyLabels */
    private function party(Model $document, array $partyLabels): ?string
    {
        if (isset($document->party_type, $document->party_id)) {
            $label = $partyLabels[$document->party_type.':'.$document->party_id] ?? null;

            if ($label !== null) {
                return $label;
            }
        }

        // ⓘ অর্থের কাগজগুলোয় মানুষটা `person` সম্পর্কে বসে, পক্ষের জোড়ায় নয়
        if ($this->has($document, 'person')) {
            $person = $document->person;

            if ($person !== null) {
                return $this->nameOf($person);
            }
        }

        return $this->firstFilled($document, ['payee_name', 'holder_name', 'party_name']);
    }

    private function about(Model $document): ?string
    {
        return $this->firstFilled($document, ['narration', 'purpose', 'note', 'description', 'title']);
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
        if ($this->has($document, 'lines')) {
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
