<?php

declare(strict_types=1);

namespace App\Core\Engines\Audit;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\AuditFieldChange;
use App\Models\AuditTrail;
use App\Models\ExportLog;
use App\Models\IssuedNumber;
use App\Models\LedgerEntry;
use App\Models\Notification;
use App\Models\SavedView;
use App\Models\SyncChange;
use App\Models\SyncConflict;
use App\Models\SyncDevice;
use App\Models\SyncState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * অডিট — প্ল্যান সেকশন ২.২, আর Global Features-এর প্রথম নিয়ম।
 *
 * ── কেন ইঞ্জিন, প্রতি মডিউলে হাতে লেখা নয় ────────────────────────────
 * "প্রতিটা মডিউলে অডিট বাধ্যতামূলক" নিয়মটা হাতে মানতে বললে প্রতিটা
 * সেবায় দুই-তিন লাইন করে বসাতে হত, আর একদিন কোথাও বাদ পড়ত। বাদ পড়া
 * অডিট কোনো ভুল দেখায় না — শুধু ইতিহাসে একটা ফাঁক রেখে যায়, আর সেটা
 * টের পাওয়া যায় বছর পরে, যখন কেউ প্রশ্ন করে "এটা কে বদলেছিল?"
 *
 * তাই লেখাটা মডেলের ঘটনা থেকেই হয় (IsAudited), আর যেসব কাজ ঘরের
 * পরিবর্তন দেখে বোঝা যায় না — অনুমোদন, বাতিল — সেগুলোর জন্য এখানে
 * আলাদা পদ্ধতি।
 */
final class AuditEngine
{
    /**
     * যে ঘরগুলো কখনো লগে যাবে না।
     *
     * ── কেন ─────────────────────────────────────────────────────────
     * পাসওয়ার্ডের হ্যাশ বা টোকেন লগে বসলে অডিট নিজেই একটা ফাঁস হয়ে
     * যেত — আর অডিট পড়ার অনুমতি সাধারণত বেশি লোকের থাকে।
     *
     * টাইমস্ট্যাম্পগুলো বাদ আলাদা কারণে: প্রতিটা সম্পাদনায় updated_at
     * বদলায়, তাই ওটা লগ করলে প্রতিটা সারিতে একটা অর্থহীন পরিবর্তন
     * যোগ হত আর আসল পরিবর্তনটা চাপা পড়ত।
     *
     * @var list<string>
     */
    /**
     * এনক্রিপ্টেড ঘরের বদলে যা লেখা হয়।
     *
     * ── কেন মান নয়, আর কেন ঘরটাও বাদ যায় না ─────────────────────────
     * ঘরটা পুরোপুরি বাদ দিলে **কে কখন কারও পরিচয়পত্র বদলেছে তা আর
     * জানাই যেত না** — অথচ ওটাই নিরীক্ষার আসল প্রশ্ন। মান লিখলে উল্টো
     * বিপদ: এনক্রিপশনটাই অর্থহীন হত।
     *
     * তাই ঘটনাটা থাকে, মানটা থাকে না।
     */
    public const HIDDEN = '••••';

    public const NEVER_LOGGED = [
        'password', 'remember_token', 'api_token', 'two_factor_secret',
        'two_factor_recovery_codes', 'created_at', 'updated_at',
    ];

    /**
     * যে মডেলগুলো অডিটে যায় না — আর কেন।
     *
     * ── কেন তালিকাটা এখানে, প্রতিটা মডেলে "মনে রাখা" নয় ─────────────
     * AuditCoverageTest এই তালিকাটা ধরেই পাহারা দেয়: কোম্পানির প্রতিটা
     * মডেল হয় অডিটেড, নয় এখানে নাম-সহ ব্যতিক্রম। তাই নতুন মডেল লিখে
     * অডিট বসাতে ভুলে গেলে টেস্ট ভাঙে — আর ভাঙার সাথে সাথেই ধরা পড়ে,
     * বছর পরে নয়।
     *
     * ── কোরের নিজের মডেলগুলোই কেবল এখানে ────────────────────────────
     * মডিউলের মডেল এখানে লিখলে কোর ওই মডিউলের নাম জেনে ফেলত (§১৯.৭)।
     * মডিউল নিজের ব্যতিক্রম নিজেই ঘোষণা করে — module.php-তে
     * 'audit_exempt' => [Model::class => 'কেন']. AuditCoverageTest
     * দুইটা তালিকা মিলিয়ে দেখে, তাই পাহারাটা যেমন ছিল তেমনই থাকে।
     *
     * @var array<class-string, string>
     */
    public const NOT_AUDITED = [
        /*
         * অডিটের নিজের টেবিল। অডিট লেখার সময় আবার অডিট লেখা মানে
         * অসীম চক্র — প্রথম সারিটাই কখনো শেষ হত না।
         */
        AuditTrail::class => 'auditing the audit would never terminate',
        AuditFieldChange::class => 'auditing the audit would never terminate',

        /*
         * রপ্তানির খাতা — নিজেই একটা খাতা।
         *
         * সারিগুলো কখনো সম্পাদনা হয় না (`updated_at` কলামই নেই), আর
         * প্রতিটা সারি নিজেই একটা ঘটনার রেকর্ড। অডিট বসালে প্রতিটা
         * রপ্তানিতে দুইটা সারি জমত — একটা খাতায়, একটা অডিটে — আর
         * দ্বিতীয়টা প্রথমটার চেয়ে কম বলত।
         */
        ExportLog::class => 'a journal of its own, append-only and never edited',

        /*
         * বিজ্ঞপ্তি — একটা ঘটনার প্রতিধ্বনি, ঘটনা নয়।
         *
         * প্রতিটা বিজ্ঞপ্তির পেছনে একটা আসল ঘটনা আছে যেটা নিজের
         * জায়গায় নিরীক্ষিত: অনুমোদন, প্রত্যাখ্যান, বাতিল। প্রতিধ্বনির
         * নিরীক্ষা রাখা মানে একই ঘটনা দুইবার লেখা, আর দ্বিতীয় লেখাটা
         * প্রথমটার চেয়ে কম বলে — ওতে কেবল "কাকে জানানো হয়েছিল"
         * থাকে, "কী ঘটেছিল" থাকে না।
         *
         * সারিগুলো সম্পাদনাও হয় না; একটাই বদল ঘটে (`read_at`), আর
         * "কে কখন খবরটা পড়েছেন" নিরীক্ষার প্রশ্ন নয়।
         */
        Notification::class => 'an echo of an event that is already audited where it happened',

        /*
         * খতিয়ান ও স্টকের চলাচল — দুইটাই append-only, কখনো সম্পাদনা
         * হয় না, আর প্রতিটা সারি কোনো না কোনো অডিটেড ডকুমেন্ট থেকে
         * এসেছে। অডিট বসালে একটা বিলে চার-পাঁচটা বাড়তি সারি জমত, আর
         * নতুন কোনো তথ্য যোগ হত না — ওগুলো নিজেরাই ইতিহাস।
         */
        LedgerEntry::class => 'append-only ledger, already traceable to its audited document',

        /*
         * নম্বর ইস্যুর হিসাব — যন্ত্রের খাতা। প্রতিটা ডকুমেন্ট তৈরিতে
         * একটা সারি বসে, আর সেই ডকুমেন্টটা এমনিতেই অডিটেড।
         */
        IssuedNumber::class => 'machine bookkeeping for document numbers',

        /*
         * সংরক্ষিত দৃশ্য — ব্যক্তিগত সুবিধা, ব্যবসার তথ্য নয়।
         *
         * একটা দৃশ্য কেবল একটা ঠিকানা মনে রাখে: "পণ্যের তালিকা,
         * ব্র্যান্ড ৩, বকেয়া"। কেউ তাঁর নিজের ছাঁকনির নাম বদলালে বইয়ের
         * কিছুই বদলায় না।
         *
         * অডিট বসালে দুইটা ক্ষতি হত। একজনের দিনে দশবার ছাঁকনি বদলানোর
         * ইতিহাস আসল বদলগুলোকে চাপা দিত; আর "কে কী দেখেছেন" নিরীক্ষার
         * প্রশ্নই নয় — নিরীক্ষার প্রশ্ন "কে কী বদলেছেন"।
         */
        SavedView::class => 'a private convenience, not business data — it only remembers an address',

        /*
         * ফোনের সাথে কথা বলার বইখাতা — ২ সেপ্টেম্বর ২০২৬।
         *
         * চারটাই **যন্ত্রের নিজের হিসাব**, মানুষের সিদ্ধান্ত নয়। একটা
         * সিঙ্ক পাসে এই সারিগুলো কয়েকশোবার লেখা ও বদলায়: প্রতিটা
         * কলে ডিভাইসের `last_seen_at`, প্রতিটা মডিউলে ওয়াটারমার্ক,
         * প্রতিটা পুশে একটা করে সারি।
         *
         * অডিটে তুললে `audit_trails` **এদের দিয়েই ভরে যেত**, আর আসল
         * ব্যবসার বদলগুলো তার নিচে চাপা পড়ত — অর্থাৎ খাতাটা ঠিক যে
         * কাজের জন্য বানানো, সেটাই আর করতে পারত না।
         *
         * আর যেটা সত্যিই নিরীক্ষার প্রশ্ন — **কে কী পাঠাল, কখন, আর
         * তার কী হলো** — সেটা `sync_changes`-এ নিজেই পুরোটা লেখা আছে,
         * প্রত্যাখ্যানের কারণ সহ। ওটা নিজেই একটা অডিট।
         *
         * (একই ছাড় স্থাপত্যের পাহারাতেও লেখা আছে —
         * `EveryChangeableRowRemembersWhoChangedItTest`। দুইটা তালিকা,
         * একই নিয়ম; একটাতে লিখে অন্যটা ভুলে গেলে সুইট লাল হয়।)
         */
        SyncDevice::class => 'a handset identity and its last contact — changes on every call, decides nothing',
        SyncState::class => 'the watermark: a machine bookmark that moves on every sync',
        SyncChange::class => 'the phone push journal itself — append-only, and it IS the audit',
        SyncConflict::class => 'an event; who settled it and when are written on the row itself',
    ];

    /**
     * একটা ঘটনা লেখা।
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes  ঘর => [পুরাতন, নতুন]
     */
    public function record(
        Model $subject,
        string $action,
        array $changes = [],
        ?string $reason = null,
    ): ?AuditTrail {
        $companyId = $this->companyIdFor($subject);

        /*
         * কোম্পানি ছাড়া অডিট নয়।
         *
         * প্রতিটা সারি কোনো না কোনো কোম্পানির। প্রতিষ্ঠান-নিরপেক্ষ কিছু
         * (মাইগ্রেশন, সিস্টেম টেবিল) বদলালে সেটা এই খাতায় বসে না —
         * বসালে কোন কোম্পানির পর্দায় দেখাবে তার উত্তর থাকত না।
         */
        if ($companyId === null) {
            return null;
        }

        /*
         * সম্পাদনায় কিছুই বদলায়নি — তবু save() ডাকা হয়েছে।
         *
         * এমন সারি লিখলে তালিকাটা "কিছু বদলায়নি" সারিতে ভরে যেত, আর
         * আসল পরিবর্তনগুলো খুঁজে বের করা কঠিন হত।
         */
        if ($action === AuditTrail::UPDATED && $changes === []) {
            return null;
        }

        $write = function () use ($subject, $action, $changes, $reason, $companyId) {
            $trail = AuditTrail::create([
                'company_id' => $companyId,
                'branch_id' => $this->branchIdFor($subject, $companyId),
                'user_id' => auth()->id(),
                'action' => $action,
                'auditable_type' => $subject::class,
                'auditable_id' => $subject->getKey(),
                'document_no' => $this->documentNoFor($subject),
                'label' => $this->labelFor($subject),
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 255) ?: null,
                'reason' => $reason,
            ]);

            /*
             * ঘরগুলো এক কোয়েরিতে, সারি প্রতি একটায় নয়।
             *
             * ── কেন এটা গুরুত্বপূর্ণ ────────────────────────────────
             * বিশটা ঘরের একটা সম্পাদনায় একুশটা INSERT যেত। ব্যবহারে
             * সেটা টের পাওয়া যায় না, কিন্তু সিডার বা আমদানিতে যখন
             * শত শত সারি বসে তখন কোয়েরির সংখ্যা কয়েক হাজার হয়ে যায় —
             * আর টেস্ট সুইট পাঁচ গুণ ধীর হয়ে গিয়েছিল ঠিক এই কারণেই।
             */
            $rows = [];
            $now = now();

            foreach ($changes as $field => [$old, $new]) {
                $rows[] = [
                    /*
                     * বাইরের কী হাতে বসানো, কারণ insert() মডেলের ঘটনা
                     * ডাকে না — HasPublicId-র creating হুকটা এখানে চলবে
                     * না, আর কলামটা খালি থেকে যেত।
                     */
                    'public_id' => (string) Str::uuid7(),
                    'company_id' => $companyId,
                    'audit_trail_id' => $trail->id,
                    'field' => $field,
                    'old_value' => $this->stringify($old),
                    'new_value' => $this->stringify($new),
                    'created_at' => $now,
                ];
            }

            if ($rows !== []) {
                AuditFieldChange::insert($rows);
            }

            return $trail;
        };

        /*
         * বাইরে ইতিমধ্যে লেনদেন চললে আর একটা মোড়ক নয়।
         *
         * নেস্টেড লেনদেন মানে SAVEPOINT — প্রতি অডিটে দুইটা বাড়তি
         * রাউন্ড-ট্রিপ। প্রায় প্রতিটা অডিটই কোনো সেবার লেনদেনের ভেতরে
         * ঘটে, তাই বাইরেরটাই অখণ্ডতা দেয়।
         */
        return DB::transactionLevel() > 0 ? $write() : DB::transaction($write);
    }

    /**
     * ব্যবসার একটা কাজ — অনুমোদন, বাতিল, নিশ্চিতকরণ।
     *
     * ঘরের পরিবর্তন দেখে এগুলো আলাদা করা যায় না: বাতিল করাও একটা
     * status বদল, আর সাধারণ সম্পাদনাও। কিন্তু তালিকায় দুইটা এক দেখালে
     * "কে বিলটা বাতিল করেছিল" প্রশ্নের উত্তর খুঁজতে প্রতিটা সারি খুলে
     * দেখতে হত।
     */
    public function recordAction(Model $subject, string $action, ?string $reason = null): ?AuditTrail
    {
        return $this->record($subject, $action, [], $reason);
    }

    /**
     * একটা মডেলের পরিবর্তনগুলো — লগযোগ্য ঘরগুলোই।
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function changesOf(Model $subject): array
    {
        $changes = [];

        /*
         * মডেলের নিজের বাদ-তালিকা।
         *
         * ── কেন মডেল প্রতি, একটা বৈশ্বিক তালিকায় নয় ────────────────
         * কোন ঘরটা যন্ত্রের হিসাব আর কোনটা ব্যবসার তথ্য, সেটা মডেলটাই
         * জানে। next_number নম্বর-সিরিজে যন্ত্রের হিসাব, কিন্তু অন্য
         * কোথাও একই নামের ঘর অর্থবহ হতে পারত — নাম দেখে বৈশ্বিকভাবে
         * বাদ দিলে সেটাও চুপচাপ হারাত।
         *
         * @var list<string> $ignored
         */
        $ignored = method_exists($subject, 'auditIgnores') ? $subject->auditIgnores() : [];

        foreach ($subject->getChanges() as $field => $new) {
            if (in_array($field, self::NEVER_LOGGED, true) || in_array($field, $ignored, true)) {
                continue;
            }

            $changes[$field] = $this->encrypted($subject, $field)
                ? [self::HIDDEN, self::HIDDEN]
                : [$subject->getOriginal($field), $new];
        }

        return $changes;
    }

    /**
     * নতুন রেকর্ডের ঘরগুলো — শূন্য ঘরগুলো বাদ।
     *
     * খালি ঘর "null → null" হিসেবে লিখলে একটা নতুন কর্মীর সারিতে
     * চল্লিশটা অর্থহীন লাইন বসত, আর যেগুলো সত্যিই ভরা হয়েছিল সেগুলো
     * তার মধ্যে হারিয়ে যেত।
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function attributesOf(Model $subject): array
    {
        $changes = [];

        foreach ($subject->getAttributes() as $field => $value) {
            if (in_array($field, self::NEVER_LOGGED, true) || $value === null || $value === '') {
                continue;
            }

            $changes[$field] = $this->encrypted($subject, $field)
                ? [null, self::HIDDEN]
                : [null, $value];
        }

        return $changes;
    }

    /**
     * অবস্থার পরিবর্তন থেকে কাজটার নাম — না মিললে null।
     *
     * ── কেন status-এর নতুন মান দেখে, পুরনোটা নয় ─────────────────────
     * "কোথায় গেল" প্রশ্নটাই ব্যবসার প্রশ্ন। খসড়া থেকে নিশ্চিত, বা
     * নিশ্চিত থেকে বাতিল — দুই ক্ষেত্রেই মানুষ জানতে চায় শেষ অবস্থাটা
     * কী, আর কে সেখানে নিয়ে গেল।
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     */
    public function actionForStatus(array $changes): ?string
    {
        if (! array_key_exists('status', $changes)) {
            return null;
        }

        return match ($changes['status'][1]) {
            DocumentStatus::CONFIRMED => AuditTrail::CONFIRMED,
            DocumentStatus::CANCELLED => AuditTrail::CANCELLED,
            default => null,
        };
    }

    /**
     * এই ঘরটা মডেলে এনক্রিপ্ট করা কি না।
     *
     * ── কেন cast দেখে, তালিকা দেখে নয় ───────────────────────────────
     * প্রতিটা এনক্রিপ্টেড ঘরের নাম এখানে হাতে লিখলে সেটা একদিন
     * পিছিয়ে পড়ত: কেউ নতুন একটা ঘর এনক্রিপ্ট করত, এখানে যোগ করতে
     * ভুলে যেত, আর **অডিট চুপচাপ সেটার মান খোলা লিখে রাখত** —
     * অর্থাৎ এনক্রিপশনটা বসানোর দিনেই ফুটো হয়ে যেত।
     *
     * cast দেখলে নিয়মটা নিজে থেকেই চলে: যে ঘর এনক্রিপ্টেড, তার মান
     * অডিটে যায় না। **কিছু মনে রাখতে হয় না।**
     */
    private function encrypted(Model $subject, string $field): bool
    {
        return str_starts_with((string) ($subject->getCasts()[$field] ?? ''), 'encrypted');
    }

    private function companyIdFor(Model $subject): ?int
    {
        /*
         * মডেল নিজে বলতে পারলে তার কথাই চূড়ান্ত।
         *
         * ── কেন এই হুকটা লাগল (২ সেপ্টেম্বর ২০২৬) ───────────────────
         * প্রায় প্রতিটা টেবিলে `company_id` আছে, তাই নিচের লাইনটাই
         * যথেষ্ট ছিল। ব্যতিক্রম [[Company]] নিজে — সে-ই তো টেন্যান্সির
         * উৎস, তার নিজের `company_id` নেই।
         *
         * ওটা ছাড়া কোম্পানির নিজের সম্পাদনা চলতি প্রসঙ্গের উপর ভর
         * করত, আর প্ল্যাটফর্ম-প্রশাসক এক কোম্পানিতে বসে অন্যটার তথ্য
         * বদলালে **সারিটা ভুল কোম্পানির খাতায় বসত** — অর্থাৎ যে
         * প্রতিষ্ঠানের তথ্য বদলাল, তার পর্দায় কিছুই দেখা যেত না।
         */
        if (method_exists($subject, 'auditCompanyId')) {
            return $subject->auditCompanyId();
        }

        $own = $subject->getAttribute('company_id');

        return $own === null ? CompanyContext::id() : (int) $own;
    }

    private function branchIdFor(Model $subject, ?int $companyId): ?int
    {
        /*
         * মডেল নিজে বলতে পারলে তার কথাই চূড়ান্ত।
         *
         * ── কেন এটা লাগল (২ সেপ্টেম্বর ২০২৬, সুইট চালিয়ে) ───────────
         * [[Company]]-র কোনো শাখা নেই — সে-ই তো শাখাগুলোর মালিক। কিন্তু
         * নিচের লাইনটা চলতি প্রসঙ্গের শাখাটা বসাত, আর সিডারে কোম্পানি
         * তৈরি হয় **শাখার আগে**। ফলে সারিটা এমন একটা শাখার দিকে
         * ইশারা করত যার তখনো অস্তিত্ব নেই, আর বিদেশি চাবি সেটা মানেনি।
         *
         * একা চালালে ধরা পড়ত না: `CompanyContext` স্ট্যাটিক, তাই
         * **আগের টেস্টের প্রসঙ্গ পরেরটায় রয়ে যেত** — আর তিনটা টেস্ট
         * কেবল পুরো সুইটে লাল হত। ওই অস্থিরতাটাই আসল বিপদ ছিল।
         */
        if (method_exists($subject, 'auditBranchId')) {
            return $subject->auditBranchId();
        }

        $own = $subject->getAttribute('branch_id');

        if ($own !== null) {
            return (int) $own;
        }

        /*
         * চলতি শাখাটা কেবল তখনই প্রযোজ্য যখন সারিটাও চলতি কোম্পানির।
         *
         * ── কী ভাঙা ছিল (২ সেপ্টেম্বর ২০২৬) ─────────────────────────
         * আগে শর্ত ছাড়াই `CompanyContext::branchId()` বসত। ফলে যে
         * সারির কোম্পানি আলাদা, তারও শাখা হিসেবে **অন্য কোম্পানির
         * শাখা** বসে যেত — একটা সারি যার কোম্পানি আর শাখা দুইটা আলাদা
         * প্রতিষ্ঠানের।
         *
         * [[Company]]-তে অডিট বসাতেই জিনিসটা ধরা পড়ল, আর ধরা পড়ল
         * সবচেয়ে জোরে সম্ভব উপায়ে: **সিডার চলল না**, কারণ কোম্পানি
         * তৈরির মুহূর্তে ওই শাখাটার অস্তিত্বই ছিল না, আর বিদেশি
         * চাবি সেটা মেনে নেয়নি।
         *
         * চুপচাপ ভুল সারি লেখার চেয়ে এভাবে ভাঙাই ভালো ছিল — ভুল
         * সারিগুলো কেউ কোনোদিন খেয়াল করত না।
         */
        return $companyId === CompanyContext::id() ? CompanyContext::branchId() : null;
    }

    private function documentNoFor(Model $subject): ?string
    {
        foreach (['document_no', 'code'] as $field) {
            $value = $subject->getAttribute($field);

            if (filled($value)) {
                return substr((string) $value, 0, 64);
            }
        }

        return null;
    }

    /**
     * পড়ার মতো নাম।
     *
     * name() থাকলে সেটাই — মাস্টার রেকর্ডে ওটাই ব্যবহারকারীর ভাষার নাম।
     */
    private function labelFor(Model $subject): ?string
    {
        if (method_exists($subject, 'name')) {
            $name = $subject->name();

            return filled($name) ? substr((string) $name, 0, 191) : null;
        }

        foreach (['name_en', 'name', 'title'] as $field) {
            $value = $subject->getAttribute($field);

            if (filled($value)) {
                return substr((string) $value, 0, 191);
            }
        }

        return null;
    }

    /**
     * যেকোনো মান পড়ার মতো লেখায়।
     *
     * পতাকাগুলো "হ্যাঁ/না" নয়, "1/0" — কারণ অডিট ভাষা-নিরপেক্ষ থাকা
     * দরকার। পর্দা সেটাকে ব্যবহারকারীর ভাষায় দেখাবে; খাতায় বসবে কাঁচা
     * মানটাই, নাহলে ভাষা বদলালে পুরনো ইতিহাস অন্য কথা বলত।
     */
    private function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: null;
        }

        return (string) $value;
    }

    /** নরম-মোছা মডেল কি না — restored ঘটনার জন্য দরকার। */
    public function usesSoftDeletes(Model $subject): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($subject), true);
    }
}
