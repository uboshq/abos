<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\CustomerSalesFilters;
use App\Core\Contracts\PartyOpenBills;
use App\Core\Engines\Drill\DrillResolver;
use App\Core\Engines\Print\PaperSize;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Jobs\SendPartyPaymentNews;
use App\Models\Approval;
use App\Models\Company;
use App\Models\DocumentShare;
use App\Models\LedgerEntry;
use App\Models\PartyMessage;
use App\Models\PartyMessageOptout;
use App\Models\PartyMessageTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

/**
 * ⭐ নিজে থেকে পাঠানো — বাকির তাগাদা আর টাকা পাওয়ার খবর (মালিকের "ok", ২৩ নভেম্বর ২০২৬; নমুনা HUXqpfhxng8823eEFrvH54,
 * ট্যাব ঙ "নিজে থেকে পাঠানো")।
 *
 * ⓘ কোর কোনো মডিউলের নাম জানে না: কার বিল মেয়াদ পেরিয়েছে, সেটা বিক্রয়ের নিজের নিয়ম ([[CustomerSalesFilters::overdueByCustomer()]],
 *   আদায়ের সূচির হুবহু), আর কার কত বাকি, সবচেয়ে পুরনো বিল কোনটা — বিক্রয়ের খোলা বিল ([[PartyOpenBills]], বিলের নিজের
 *   `dueAmount()`)। ⛔ এখানে দ্বিতীয় কোনো সূত্র নেই — বাকির অঙ্ক বিলের পাতা, আদায়ের পর্দা আর রসিদের খোলা বিলের তালিকার সাথে এক।
 * ⓘ পাঠানো সবসময় [[PartyMessageDispatcher::deliver()]]-এ — পথের ক্রম সেখানেই (Cloud API → অফিসের ফোন → ব্যর্থ), আর খাতায় সারি
 *   `via = auto`, `rule` = কোন নিয়ম।
 *
 * ── নিয়ম কোথায় থাকে ─────────────────────────────────────────────────
 * Notification-এর সেটিং (module.php), কোম্পানি ধরে। ⓘ "প্রতিটা শাখার নিজের নিয়ম" চালু থাকলে দুই কার্ডের ঘরগুলো শাখা ধরে আলাদা —
 * [[SettingsService]]-এ শাখার স্তর নেই, তাই সেগুলো একটা json সেটিংয়ে ([[BRANCH_RULES]]: `{"<শাখা>": {<চাবি>: <মান>}}`); যে শাখার
 * নিজের সারি নেই, সে কোম্পানির নিয়মে চলে। চারটা সবার-নিয়ম (রাত, দিনে একবার, বাদ রাখা, শাখা ধরে) সবসময় কোম্পানির।
 */
final class PartyAutoSend
{
    // ── বাকির তাগাদা ─────────────────────────────────────────────────
    public const DUE_ON = 'notification.auto.due.enabled';

    public const DUE_AFTER = 'notification.auto.due.after_days';

    public const DUE_EVERY = 'notification.auto.due.every_days';

    public const DUE_MIN = 'notification.auto.due.min_due';

    public const DUE_TIME = 'notification.auto.due.time';

    public const DUE_DAYS = 'notification.auto.due.days';

    public const DUE_TEMPLATE = 'notification.auto.due.template_id';

    // ── টাকা পাওয়ার খবর ─────────────────────────────────────────────
    public const PAID_ON = 'notification.auto.paid.enabled';

    public const PAID_WHEN = 'notification.auto.paid.when';

    public const PAID_MIN = 'notification.auto.paid.min_amount';

    public const PAID_TEMPLATE = 'notification.auto.paid.template_id';

    public const PAID_LINK = 'notification.auto.paid.with_link';

    // ── সব নিজে-থেকে-পাঠানোয় খাটে ───────────────────────────────────
    public const QUIET = 'notification.auto.quiet';

    public const ONCE_A_DAY = 'notification.auto.once_a_day';

    public const OPTOUT = 'notification.auto.optout';

    public const PER_BRANCH = 'notification.auto.per_branch';

    /** ⓘ শাখার নিজের নিয়ম — `{"<branch_id>": {<RULE_DEFAULTS-এর চাবি>: <মান>}}`; null = কোনো শাখার নিজের নিয়ম নেই */
    public const BRANCH_RULES = 'notification.auto.branch_rules';

    /** দুই কার্ডের ঘর — শাখা ধরে আলাদা হতে পারে; ডিফল্ট module.php-র ঘোষণার হুবহু */
    public const RULE_DEFAULTS = [
        self::DUE_ON => false,
        self::DUE_AFTER => 7,
        self::DUE_EVERY => 7,
        self::DUE_MIN => '5000',
        self::DUE_TIME => '10:00',
        self::DUE_DAYS => self::SAT_THU,
        self::DUE_TEMPLATE => 0,
        self::PAID_ON => false,
        self::PAID_WHEN => self::POSTED,
        self::PAID_MIN => '0',
        self::PAID_TEMPLATE => 0,
        self::PAID_LINK => true,
    ];

    /** সবার নিয়ম — সবসময় কোম্পানির */
    public const SHARED_DEFAULTS = [
        self::QUIET => true,
        self::ONCE_A_DAY => true,
        self::OPTOUT => true,
        self::PER_BRANCH => false,
    ];

    public const AFTER_DAYS = [1, 7, 15, 30];

    public const EVERY_DAYS = [3, 7, 15];

    public const SAT_THU = 'sat_thu';

    public const EVERY_DAY = 'every';

    public const DAYS = [self::SAT_THU, self::EVERY_DAY];

    public const POSTED = 'posted';

    public const APPROVED = 'approved';

    public const WHEN = [self::POSTED, self::APPROVED];

    /** ⓘ রাত 9:00 থেকে সকাল 9:00 — কিছু যায় না, সকালে যায় (নমুনার লেখা) */
    public const QUIET_FROM = '21:00';

    public const QUIET_TO = '09:00';

    /**
     * ⓘ টাকা পাওয়ার খবরের লেখা — "রসিদ"-এর লেখা: টাকা ঢোকার কাগজ RCV ভাউচার হলেও তার লেখা রসিদের ধরনে
     *   ([[PartyMessages::messageType()]]; ভাউচারের "পরিশোধের খবর" রসিদে উল্টো কথা বলত)।
     */
    public const RECEIPT_TYPES = ['sales_collection'];

    /** ⓘ লেজারে রসিদের নাম — নমুনার "যা যাবে"-র জন্য শেষ রসিদটা খুঁজতে ([[DrillResolver]] কাগজটা খোলে) */
    private const RECEIPT_SOURCE = 'receipt_voucher';

    /** বাকির হিসাবে একজনের কতগুলো খোলা বিল পড়া হয় — এর বেশি বিল এক পার্টির কদাচিৎ */
    private const BILLS = 500;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly PartyRegistry $parties,
        private readonly PartyMessages $messages,
        private readonly PartyMessageDispatcher $dispatcher,
        private readonly WhatsAppCloud $cloud,
        private readonly PaperTrail $trail,
    ) {}

    // ═════════════════════════════════════════════════════════════════
    //  নিয়ম
    // ═════════════════════════════════════════════════════════════════

    /**
     * এক শাখার (বা কোম্পানির, `null`) নিয়ম — সব ঘর, পরিষ্কার মানে।
     *
     * @return array<string, mixed>
     */
    public function rules(?int $branchId = null): array
    {
        $rules = [];

        foreach (self::RULE_DEFAULTS + self::SHARED_DEFAULTS as $key => $default) {
            $rules[$key] = $this->settings->get($key, $default);
        }

        if ($rules[self::PER_BRANCH] && $branchId !== null) {
            $own = $this->branchRules()[(string) $branchId] ?? null;

            if (is_array($own)) {
                foreach (array_keys(self::RULE_DEFAULTS) as $key) {
                    if (array_key_exists($key, $own)) {
                        $rules[$key] = $own[$key];
                    }
                }
            }
        }

        return self::clean($rules);
    }

    /** @return array<string, array<string, mixed>> */
    public function branchRules(): array
    {
        $all = $this->settings->get(self::BRANCH_RULES, null);

        return is_array($all) ? array_filter($all, 'is_array') : [];
    }

    public function hasOwnRules(int $branchId): bool
    {
        return isset($this->branchRules()[(string) $branchId]);
    }

    /**
     * ⭐ পাতার "সংরক্ষণ" — সবার নিয়ম কোম্পানিতে; কার্ডের ঘর শাখায় (শাখা ধরে চালু আর মাথায় একটা শাখা বাছা) নয়তো কোম্পানিতে।
     *
     * @param  array<string, mixed>  $input  [[RULE_DEFAULTS]] + [[SHARED_DEFAULTS]]-এর চাবি, যাচাই করা
     */
    public function save(array $input, ?int $branchId): void
    {
        $input = self::clean($input + self::RULE_DEFAULTS + self::SHARED_DEFAULTS);

        foreach (array_keys(self::SHARED_DEFAULTS) as $key) {
            $this->settings->set($key, $input[$key]);
        }

        $card = array_intersect_key($input, self::RULE_DEFAULTS);

        if ($input[self::PER_BRANCH] && $branchId !== null) {
            $all = $this->branchRules();
            $all[(string) $branchId] = $card;
            $this->settings->set(self::BRANCH_RULES, $all);

            return;
        }

        foreach ($card as $key => $value) {
            $this->settings->set($key, $value);
        }
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private static function clean(array $rules): array
    {
        $int = fn (mixed $v, array $allowed, int $default): int => in_array((int) $v, $allowed, true) ? (int) $v : $default;
        $money = fn (mixed $v, string $default): string => is_numeric($v) && bccomp((string) $v, '0', 4) >= 0 ? bcadd((string) $v, '0', 2) : $default;
        $time = (string) ($rules[self::DUE_TIME] ?? '');

        return [
            self::DUE_ON => (bool) ($rules[self::DUE_ON] ?? false),
            self::DUE_AFTER => $int($rules[self::DUE_AFTER] ?? null, self::AFTER_DAYS, 7),
            self::DUE_EVERY => $int($rules[self::DUE_EVERY] ?? null, self::EVERY_DAYS, 7),
            self::DUE_MIN => $money($rules[self::DUE_MIN] ?? null, '5000.00'),
            self::DUE_TIME => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1 ? $time : '10:00',
            self::DUE_DAYS => in_array($rules[self::DUE_DAYS] ?? null, self::DAYS, true) ? $rules[self::DUE_DAYS] : self::SAT_THU,
            self::DUE_TEMPLATE => max(0, (int) ($rules[self::DUE_TEMPLATE] ?? 0)),
            self::PAID_ON => (bool) ($rules[self::PAID_ON] ?? false),
            self::PAID_WHEN => in_array($rules[self::PAID_WHEN] ?? null, self::WHEN, true) ? $rules[self::PAID_WHEN] : self::POSTED,
            self::PAID_MIN => $money($rules[self::PAID_MIN] ?? null, '0.00'),
            self::PAID_TEMPLATE => max(0, (int) ($rules[self::PAID_TEMPLATE] ?? 0)),
            self::PAID_LINK => (bool) ($rules[self::PAID_LINK] ?? true),
            self::QUIET => (bool) ($rules[self::QUIET] ?? true),
            self::ONCE_A_DAY => (bool) ($rules[self::ONCE_A_DAY] ?? true),
            self::OPTOUT => (bool) ($rules[self::OPTOUT] ?? true),
            self::PER_BRANCH => (bool) ($rules[self::PER_BRANCH] ?? false),
        ];
    }

    // ═════════════════════════════════════════════════════════════════
    //  সময়
    // ═════════════════════════════════════════════════════════════════

    /** রাত 9:00 থেকে সকাল 9:00 */
    public static function isQuiet(Carbon $at): bool
    {
        $t = $at->format('H:i');

        return $t >= self::QUIET_FROM || $t < self::QUIET_TO;
    }

    /** রাতের পরের সকাল 9:00 */
    public static function morningAfter(Carbon $at): Carbon
    {
        $morning = $at->copy()->setTimeFromTimeString(self::QUIET_TO);

        return $at->format('H:i') >= self::QUIET_FROM ? $morning->addDay() : $morning;
    }

    /**
     * আজ তাগাদা কখন যাবে — বসানো সময়; সেটা রাতের ভিতরে পড়লে (আর রাতের নিয়ম চালু) সকাল 9:00-এ।
     *
     * @param  array<string, mixed>  $rules
     */
    public static function dueTimeOn(Carbon $day, array $rules): Carbon
    {
        $at = $day->copy()->setTimeFromTimeString((string) $rules[self::DUE_TIME]);

        return $rules[self::QUIET] && self::isQuiet($at) ? $day->copy()->setTimeFromTimeString(self::QUIET_TO) : $at;
    }

    /** @param  array<string, mixed>  $rules */
    public static function dayAllowed(Carbon $day, array $rules): bool
    {
        return $rules[self::DUE_DAYS] === self::EVERY_DAY || ! $day->isFriday();
    }

    // ═════════════════════════════════════════════════════════════════
    //  লেখা
    // ═════════════════════════════════════════════════════════════════

    /**
     * এক নিয়মের জন্য বাছা যায় এমন চালু লেখা — নিয়মের নিজের কাগজের আগে, তারপর "যেকোনো কাগজ"।
     *
     * @return Builder<PartyMessageTemplate>
     */
    public function templatesFor(string $rule): Builder
    {
        $types = $rule === PartyMessage::DUE_REMINDER ? [PartyMessage::PARTY_ACCOUNT] : self::RECEIPT_TYPES;

        return PartyMessageTemplate::query()
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereIn('document_type', $types)->orWhereNull('document_type'))
            ->orderByRaw('document_type IS NULL')
            // ⓘ ছোট SMS-এর লেখা আগে (নমুনার ক্রম: "বাকির তাগাদা", "টাকা পেয়েছি (ছোট)") — নিজে থেকে পাঠানোয় কেউ বোতাম চাপেন না,
            //   অফিসের ফোনের পথ সবসময় চলে; Cloud API-র লেখায় Meta-র অনুমোদন লাগে
            ->orderByRaw('channel <> ?', [PartyMessageTemplate::SMS])
            ->orderBy('sort')
            ->orderBy('id');
    }

    /**
     * বাছা লেখা; বাছা না থাকলে (বা বন্ধ হয়ে গেলে) নিয়মের নিজের কাগজের প্রথম লেখা।
     * ⛔ "যেকোনো কাগজ"-এর লেখা নিজে থেকে বাছা হয় না — "আপনার কাগজ পাঠানো হলো" তাগাদা নয়।
     *
     * @param  array<string, mixed>  $rules
     */
    public function templateFor(string $rule, array $rules): ?PartyMessageTemplate
    {
        $id = (int) $rules[$rule === PartyMessage::DUE_REMINDER ? self::DUE_TEMPLATE : self::PAID_TEMPLATE];

        if ($id > 0 && ($chosen = $this->templatesFor($rule)->whereKey($id)->first()) !== null) {
            return $chosen;
        }

        return $this->templatesFor($rule)->whereNotNull('document_type')->first();
    }

    // ═════════════════════════════════════════════════════════════════
    //  বাকির তাগাদা
    // ═════════════════════════════════════════════════════════════════

    /**
     * ⭐ আজ কারা তাগাদা পাবেন — "আজ কারা পাবেন" ট্যাব আর সূচির কাজ ([[runDue()]]) একই তালিকা পড়ে।
     *
     * কেবল যাঁদের জন্য: নিয়ম চালু (তাঁর শাখার, বা কোম্পানির) · আজ পাঠানোর দিন (শুক্র বাদ, যদি তাই বসানো) · কোনো বিল মেয়াদ পেরিয়ে
     * অন্তত `after_days` দিন · মোট বাকি অন্তত `min_due` · মোবাইল নম্বর আছে · বাদ রাখা নন · আজ তাগাদা পাননি · শেষ তাগাদার পরে
     * `every_days` দিন পেরিয়েছে · ("দিনে একবার" চালু থাকলে) আজ আর কোনো বার্তা পাননি।
     *
     * @return list<array{party_type: string, party_id: int, branch_id: ?int, name: string, phone: string, due: string, oldest: array<string, mixed>, rules: array<string, mixed>, template: ?PartyMessageTemplate, road: string}>
     */
    public function dueList(Carbon $now): array
    {
        $sets = [$this->rules(null)];

        if ($sets[0][self::PER_BRANCH]) {
            foreach (array_keys($this->branchRules()) as $branch) {
                $sets[] = $this->rules((int) $branch);
            }
        }

        $on = array_values(array_filter($sets, fn (array $r) => $r[self::DUE_ON]));

        if ($on === []) {
            return [];
        }

        $today = $now->copy()->startOfDay();
        $minAfter = min(array_map(fn (array $r) => $r[self::DUE_AFTER], $on));

        // ⓘ শেষ দিন < আজ − (after − 1) দিন ⇔ মেয়াদ পেরিয়েছে অন্তত `after` দিন — বিক্রয়ের নিজের "মেয়াদ পেরোনো" প্রশ্নে
        $overdue = app(CustomerSalesFilters::class)->overdueByCustomer($today->copy()->subDays($minAfter - 1)->toDateString()) ?? [];
        $ids = array_map(fn (array $r) => (int) $r['customer_id'], $overdue);

        if ($ids === []) {
            return [];
        }

        $branches = $this->parties->branchesOf(array_map(fn (int $id) => ['customer', $id], $ids));
        $sentToday = $this->partiesMessagedSince($today, PartyMessage::DUE_REMINDER, $ids, countFailed: true);
        $anyToday = $this->partiesMessagedSince($today, null, $ids, countFailed: false);
        $bills = app(PartyOpenBills::class);
        $out = [];

        foreach ($ids as $id) {
            $branch = $branches['customer:'.$id] ?? null;
            $rules = $this->rules($branch);

            if (! $rules[self::DUE_ON] || ! self::dayAllowed($now, $rules)
                || isset($sentToday[$id]) || ($rules[self::ONCE_A_DAY] && isset($anyToday[$id]))
                || ($rules[self::OPTOUT] && PartyMessageOptout::covers('customer', $id))) {
                continue;
            }

            // ⓘ শেষ তাগাদার পরে `every_days` দিন — ব্যর্থ তাগাদা গোনা হয় না (পার্টির কাছে যায়নি)
            $since = $today->copy()->subDays($rules[self::DUE_EVERY] - 1);

            if ($this->partiesMessagedSince($since, PartyMessage::DUE_REMINDER, [$id], countFailed: false) !== []) {
                continue;
            }

            $open = array_values(array_filter($bills->openBills('customer', $id, self::BILLS), fn (array $b) => ($b['kind'] ?? 'invoice') === 'invoice'));

            if ($open === []) {
                continue;
            }

            $late = max(array_map(fn (array $b) => (int) $b['days_late'], $open));
            // ⓘ মোট বাকি = পক্ষের খাতা, হিসাব বিবরণী আর {due}-এর একই অঙ্ক ([[PartyMessages::dueOf()]]) — দ্বিতীয় কোনো সূত্র নয়
            $due = $this->messages->dueOf('customer', $id) ?? '0';

            if ($late < $rules[self::DUE_AFTER] || bccomp($due, (string) $rules[self::DUE_MIN], 4) < 0) {
                continue;
            }

            $facts = $this->parties->paperFacts('customer', $id);
            $phone = PartyMessages::phone($facts['phone'] ?? null);

            if ($facts === null || $phone === null) {
                continue;
            }

            $template = $this->templateFor(PartyMessage::DUE_REMINDER, $rules);

            $out[] = [
                'party_type' => 'customer',
                'party_id' => $id,
                'branch_id' => $branch,
                'name' => (string) $facts['name'],
                'phone' => $phone,
                'due' => $due,
                'oldest' => $open[0],
                'rules' => $rules,
                'template' => $template,
                'road' => $this->roadFor($phone, $template),
            ];
        }

        return $out;
    }

    /**
     * ⭐ সূচির কাজ — যাঁর সময় হয়েছে, তাঁকে পাঠানো। ⓘ দুইবার ডাকলেও একজন দিনে একবারই পান ([[dueList()]] আজকের তাগাদা বাদ দেয়)।
     *
     * @return int কতজনকে পাঠানো হলো (ব্যর্থ সহ — খাতায় সারি পড়েছে)
     */
    public function runDue(Carbon $now): int
    {
        $sent = 0;

        foreach ($this->dueList($now) as $row) {
            $rules = $row['rules'];

            if ($now->lt(self::dueTimeOn($now, $rules)) || ($rules[self::QUIET] && self::isQuiet($now)) || $row['template'] === null) {
                continue;
            }

            $this->sendDue($row);
            $sent++;
        }

        return $sent;
    }

    /** @param  array{party_type: string, party_id: int, branch_id: ?int, name: string, phone: string, due: string, oldest: array<string, mixed>, rules: array<string, mixed>, template: ?PartyMessageTemplate, road: string}  $row */
    private function sendDue(array $row): PartyMessage
    {
        /** @var PartyMessageTemplate $template */
        $template = $row['template'];
        $locale = $this->localeOf($row['party_type'], $row['party_id']);
        $share = $this->billShare($row['oldest']);
        $values = $this->dueValues($row, $locale, $share === null ? '' : route('paper.shared', $share->token));

        $message = new PartyMessage([
            'company_id' => CompanyContext::id(),
            'branch_id' => $row['branch_id'],
            'channel' => $template->channel,
            'document_type' => PartyMessage::PARTY_ACCOUNT,
            'document_id' => $row['party_id'],
            'document_no' => (string) $row['oldest']['no'],
            'party_type' => $row['party_type'],
            'party_id' => $row['party_id'],
            'party_name' => mb_substr($values['party'], 0, 191) ?: null,
            'phone' => $row['phone'],
            'locale' => $locale,
            'template_id' => $template->id,
            'body' => self::render($template->body($locale), $values),
            'share_id' => $share?->id,
            'via' => PartyMessage::AUTO,
            'rule' => PartyMessage::DUE_REMINDER,
            'status' => PartyMessage::WAITING,
        ]);

        return $this->dispatcher->deliver($message, $template, $values);
    }

    /**
     * তাগাদার লেখার মান — {paper_no} সবচেয়ে পুরনো খোলা বিল, {date} তার দিন, {amount} তার মোট, {due} পক্ষের মোট বাকি
     * ([[PartyMessages::dueOf()]]; টাকার রূপ [[PartyMessages::values()]]-এর হুবহু), {link} সেই বিলের
     * খোলা লিংক (ঘরের পার্টির হিসাব বিবরণীর শেয়ার করা কাগজ নেই — [[PaperTrail::DOCUMENT_ROUTES]])।
     *
     * @param  array{party_type: string, party_id: int, due: string, oldest: array<string, mixed>}  $row
     * @return array<string, string>
     */
    private function dueValues(array $row, string $locale, string $link): array
    {
        return [
            'party' => $this->nameIn($row['party_type'], $row['party_id'], $locale),
            'paper_no' => (string) $row['oldest']['no'],
            'amount' => PartyMessages::taka((string) $row['oldest']['total']),
            'due' => PartyMessages::taka((string) $row['due']),
            'date' => Carbon::parse((string) $row['oldest']['date'])->format('d-m-Y'),
            'company' => $this->companyName($locale),
            'link' => $link,
        ];
    }

    /** সবচেয়ে পুরনো বিলের খোলা লিংক — আগের জীবিত লিংক থাকলে সেটাই ([[PaperTrail::share()]]) */
    private function billShare(array $bill): ?DocumentShare
    {
        $type = (string) ($bill['against_type'] ?? '');
        $id = (int) ($bill['id'] ?? 0);
        $route = PaperTrail::DOCUMENT_ROUTES[$type] ?? null;
        $params = $route === null ? null : PaperTrail::routeParamsFor($type, $id);

        if ($params === null) {
            return null;
        }

        return $this->trail->share($route, $params, $type, $id, PaperSize::A4, (string) ($bill['no'] ?? ''));
    }

    // ═════════════════════════════════════════════════════════════════
    //  টাকা পাওয়ার খবর
    // ═════════════════════════════════════════════════════════════════

    /**
     * ⭐ রসিদ পাকা হলো — খবরটা সারিতে (পোস্ট কখনো ধীর হয় না), রাত হলে সকাল 9:00-এ।
     *
     * ⓘ "অনুমোদনের পরে" বাছা থাকলে কেবল সেই রসিদ, যার অনুমোদন হয়েছে ([[Approval]] অনুমোদিত); যে রসিদে অনুমোদন লাগেনি (অনুমোদন বন্ধ,
     *   বা অঙ্ক সীমার নিচে), তার খবর তখন যায় না — সবার খবর চাইলে "পোস্ট হওয়ামাত্র"। ⓘ এই ঘরে অনুমোদন আগে, পোস্ট পরে
     *   (শেষ সইয়ে নিজে পোস্ট), তাই পোস্টের মুহূর্তেই উত্তরটা জানা।
     */
    public function receiptPosted(Model $receipt, string $documentType): void
    {
        $rules = $this->rules(self::branchOf($receipt));

        if (! $rules[self::PAID_ON] || ($rules[self::PAID_WHEN] === self::APPROVED && ! $this->wasApproved($receipt))) {
            return;
        }

        $job = new SendPartyPaymentNews((int) $receipt->getAttribute('company_id'), $receipt->getMorphClass(), (int) $receipt->getKey(), $documentType);

        dispatch($rules[self::QUIET] && self::isQuiet(now()) ? $job->delay(self::morningAfter(now())) : $job);
    }

    /**
     * ⭐ সারি থেকে — খবরটা পাঠানো। ⓘ একই রসিদে একবারই (খাতায় আগে থাকলে কিছু নয়); রাত হয়ে গেলে সকালে আবার সারিতে।
     */
    public function sendPaymentNews(string $morph, int $id, string $documentType): ?PartyMessage
    {
        $class = Relation::getMorphedModel($morph) ?? $morph;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        /** @var Model|null $receipt */
        $receipt = $class::query()->withoutGlobalScopes()->where('company_id', CompanyContext::id())->find($id);

        if ($receipt === null || PartyMessage::query()->withoutGlobalScope('user-branch')
            ->where('rule', PartyMessage::PAYMENT_RECEIVED)->where('document_type', $documentType)->where('document_id', $id)->exists()) {
            return null;
        }

        $rules = $this->rules(self::branchOf($receipt));

        if (! $rules[self::PAID_ON]) {
            return null;
        }

        if ($rules[self::QUIET] && self::isQuiet(now())) {
            dispatch((new SendPartyPaymentNews((int) CompanyContext::id(), $morph, $id, $documentType))->delay(self::morningAfter(now())));

            return null;
        }

        $amount = (string) ($receipt->getAttribute('amount') ?? $receipt->getAttribute('total') ?? '0');
        $party = $this->messages->partyOf($receipt);

        if (bccomp($amount, (string) $rules[self::PAID_MIN], 4) < 0 || $party['type'] === null || $party['phone'] === null
            || ($rules[self::OPTOUT] && PartyMessageOptout::covers((string) $party['type'], (int) $party['id']))) {
            return null;
        }

        $template = $this->templateFor(PartyMessage::PAYMENT_RECEIVED, $rules);

        if ($template === null) {
            return null;
        }

        $locale = $this->localeOf((string) $party['type'], (int) $party['id']);
        $share = $this->paperShare($receipt, $documentType, (bool) $rules[self::PAID_LINK]);
        $values = $this->paidValues($receipt, $share, $locale, (bool) $rules[self::PAID_LINK]);

        $message = new PartyMessage([
            'company_id' => CompanyContext::id(),
            'branch_id' => self::branchOf($receipt),
            'channel' => $template->channel,
            'document_type' => $documentType,
            'document_id' => $id,
            'document_no' => (string) ($receipt->getAttribute('document_no') ?? ''),
            'party_type' => $party['type'],
            'party_id' => $party['id'],
            'party_name' => mb_substr($values['party'], 0, 191) ?: null,
            'phone' => $party['phone'],
            'locale' => $locale,
            'template_id' => $template->id,
            'body' => self::render($template->body($locale), $values),
            'share_id' => $rules[self::PAID_LINK] ? $share->id : null,
            'via' => PartyMessage::AUTO,
            'rule' => PartyMessage::PAYMENT_RECEIVED,
            'status' => PartyMessage::WAITING,
        ]);

        return $this->dispatcher->deliver($message, $template, $values);
    }

    /**
     * রসিদের লেখার মান — ঘরের নিজের [[PartyMessages::values()]] ({due} সেখানেই, রসিদ পাকা হওয়ার পরে পড়া — তাই "বাকি এখন");
     * লিংক না চাইলে {link} ফাঁকা।
     *
     * @return array<string, string>
     */
    private function paidValues(Model $receipt, DocumentShare $share, string $locale, bool $withLink): array
    {
        $values = $this->messages->values($receipt, $share, $locale);

        return array_merge($values, ['link' => $withLink ? $values['link'] : '']);
    }

    /**
     * রসিদের লিংক — চাইলে আসল ([[PaperTrail::share()]]); না চাইলে কোনো লিংক বানানো হয় না, কেবল মান বানানোর জন্য সারিহীন একটা।
     */
    private function paperShare(Model $receipt, string $documentType, bool $real): DocumentShare
    {
        $route = PaperTrail::DOCUMENT_ROUTES[$documentType] ?? null;
        $params = $route === null ? null : PaperTrail::routeParamsFor($documentType, (int) $receipt->getKey());
        $no = (string) ($receipt->getAttribute('document_no') ?? '');

        if ($real && $params !== null) {
            return $this->trail->share($route, $params, $documentType, (int) $receipt->getKey(), PaperSize::A4, $no);
        }

        return (new DocumentShare)->forceFill(['document_no' => $no, 'token' => 'none']);
    }

    private function wasApproved(Model $receipt): bool
    {
        return Approval::query()
            ->where('approvable_type', $receipt->getMorphClass())
            ->where('approvable_id', $receipt->getKey())
            ->where('status', Approval::APPROVED)
            ->exists();
    }

    private static function branchOf(Model $paper): ?int
    {
        $branch = $paper->getAttribute('branch_id');

        return $branch === null ? null : (int) $branch;
    }

    // ═════════════════════════════════════════════════════════════════
    //  পাতার জন্য — "যা যাবে", আজকের গোনা, পথ
    // ═════════════════════════════════════════════════════════════════

    /**
     * ⭐ "যা যাবে — বাংলা" — একজন আসল পার্টির মানে, প্রতিটা বাছা যায় এমন লেখায় (ঘর বদলালে পাতা অন্যটা দেখায়)।
     * ⓘ তাগাদায় আজকের তালিকার প্রথমজন, নয়তো যাঁর কোনো বিল মেয়াদ পেরিয়েছে; লিংক বানানো হয় না, কেবল দেখানোর জন্য ছোট রূপ।
     *
     * @return array<int, string> লেখার id => লেখা
     */
    public function duePreview(Carbon $now): array
    {
        $row = $this->dueList($now)[0] ?? $this->anyOverdue($now);

        if ($row === null) {
            return [];
        }

        $values = $this->dueValues($row, 'bn', self::linkSample());

        return $this->templatesFor(PartyMessage::DUE_REMINDER)->get()
            ->mapWithKeys(fn (PartyMessageTemplate $t) => [(int) $t->id => self::render($t->body('bn'), $values)])->all();
    }

    /**
     * ⭐ টাকা পাওয়ার "যা যাবে" — শেষ পাকা রসিদটার আসল মানে (লেজার থেকে খুঁজে, [[DrillResolver]] কাগজটা খোলে)।
     *
     * @return array<int, string>
     */
    public function paidPreview(bool $withLink): array
    {
        // ⓘ the sample is a real receipt the person may see — inside the viewed branch, like every screen that shows the ledger
        $entry = \App\Core\Support\ViewedBranch::narrow(LedgerEntry::query(), 'ledger_entries.branch_id')->where('source_type', self::RECEIPT_SOURCE)
            ->whereNotNull('party_type')->whereNotNull('party_id')->latest('id')->first();

        $receipt = $entry === null ? null : app(DrillResolver::class)->resolve(self::RECEIPT_SOURCE, (int) $entry->source_id);

        if (! $receipt instanceof Model) {
            return [];
        }

        $share = (new DocumentShare)->forceFill(['document_no' => (string) ($receipt->getAttribute('document_no') ?? ''), 'token' => 'none']);
        $values = array_merge($this->paidValues($receipt, $share, 'bn', false), ['link' => $withLink ? self::linkSample() : '']);

        return $this->templatesFor(PartyMessage::PAYMENT_RECEIVED)->get()
            ->mapWithKeys(fn (PartyMessageTemplate $t) => [(int) $t->id => self::render($t->body('bn'), $values)])->all();
    }

    /**
     * আজ এক নিয়মে কতজনকে গেছে, কতটা ব্যর্থ — "আজ গেছে: 23 · ব্যর্থ 1"।
     *
     * @return array{sent: int, failed: int}
     */
    public function todayCounts(string $rule, Carbon $now): array
    {
        $rows = PartyMessage::query()->where('via', PartyMessage::AUTO)->where('rule', $rule)
            ->where('created_at', '>=', $now->copy()->startOfDay())->where('created_at', '<', $now->copy()->addDay()->startOfDay());

        return [
            'sent' => (clone $rows)->where('status', '!=', PartyMessage::FAILED)->count(),
            'failed' => (clone $rows)->where('status', PartyMessage::FAILED)->count(),
        ];
    }

    /**
     * এই নম্বরে কোন পথে যাবে — [[PartyMessageDispatcher::deliver()]]-এর একই ক্রম: Cloud API → অফিসের ফোন → কোনো পথ নেই।
     *
     * @return 'cloud'|'phone'|'none'
     */
    public function roadFor(string $phone, ?PartyMessageTemplate $template): string
    {
        if ($template !== null && $this->dispatcher->cloudOn() && $this->cloud->canSend($template)) {
            return PartyMessage::CLOUD;
        }

        if ($this->dispatcher->smsOn() && PartyMessageDispatcher::isBangladeshMobile($phone)) {
            return PartyMessage::PHONE;
        }

        return 'none';
    }

    // ═════════════════════════════════════════════════════════════════
    //  ভিতরের
    // ═════════════════════════════════════════════════════════════════

    /** যাঁর কোনো বিল আজ মেয়াদ পেরিয়েছে, নিয়ম না মেনে — কেবল "যা যাবে"-র নমুনার জন্য */
    private function anyOverdue(Carbon $now): ?array
    {
        $first = (app(CustomerSalesFilters::class)->overdueByCustomer($now->copy()->addDay()->toDateString()) ?? [])[0] ?? null;

        if ($first === null) {
            return null;
        }

        $id = (int) $first['customer_id'];
        $open = array_values(array_filter(app(PartyOpenBills::class)->openBills('customer', $id, self::BILLS), fn (array $b) => ($b['kind'] ?? 'invoice') === 'invoice'));

        if ($open === []) {
            return null;
        }

        return [
            'party_type' => 'customer',
            'party_id' => $id,
            'due' => $this->messages->dueOf('customer', $id) ?? '0',
            'oldest' => $open[0],
        ];
    }

    /**
     * কারা `$since` থেকে বার্তা পেয়েছেন — এক নিয়মের (`null` = যেকোনো, হাতে পাঠানোও), গ্রাহক ধরে।
     *
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private function partiesMessagedSince(Carbon $since, ?string $rule, array $ids, bool $countFailed): array
    {
        return PartyMessage::query()->withoutGlobalScope('user-branch')
            ->where('party_type', 'customer')->whereIn('party_id', $ids ?: [0])
            ->where('created_at', '>=', $since)
            ->when($rule !== null, fn ($q) => $q->where('rule', $rule))
            ->when(! $countFailed, fn ($q) => $q->where(fn ($w) => $w->whereNull('status')->orWhere('status', '!=', PartyMessage::FAILED)))
            ->pluck('party_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /** পার্টির কাগজের ভাষা, নয়তো কোম্পানির পার্টি-বার্তার ভাষা ([[PartyMessageDispatcher::defaultLocale()]]) */
    private function localeOf(string $type, int $id): string
    {
        $own = $this->parties->find($type, $id)?->getAttribute('paper_language');

        return in_array($own, ['bn', 'en'], true) ? $own : $this->dispatcher->defaultLocale();
    }

    /** পার্টির নাম লেখার ভাষায় — [[PartyMessages::values()]]-এর একই নিয়ম */
    private function nameIn(string $type, int $id, string $locale): string
    {
        $screen = app()->getLocale();
        app()->setLocale($locale);

        try {
            return (string) ($this->parties->paperFacts($type, $id)['name'] ?? '');
        } finally {
            app()->setLocale($screen);
        }
    }

    private function companyName(string $locale): string
    {
        $company = Company::query()->find(CompanyContext::id());

        return (string) ($company === null ? '' : ($locale === 'en' ? ($company->name_en ?: $company->name_bn) : ($company->name_bn ?: $company->name_en)));
    }

    /** ⓘ "যা যাবে"-তে লিংকের ছোট রূপ — আসল লিংক পাঠানোর সময়েই বানানো হয় */
    private static function linkSample(): string
    {
        try {
            return str_replace('TOKEN', '…', route('paper.shared', 'TOKEN'));
        } catch (\Throwable) {
            return url('/').'/…';
        }
    }

    /** @param  array<string, string>  $values */
    private static function render(string $text, array $values): string
    {
        return PartyMessages::render($text, $values);
    }
}
