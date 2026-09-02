<?php

declare(strict_types=1);

namespace App\Core\Engines\Sync;

use App\Core\Support\CompanyContext;
use App\Models\SyncChange;
use App\Models\SyncConflict;
use App\Models\SyncDevice;
use App\Models\SyncState;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * অফলাইন সিঙ্কের সব বইখাতা — ডিভাইস, ওয়াটারমার্ক, দুইবার বসা ঠেকানো,
 * আর দ্বন্দ্ব।
 *
 * ── এই শ্রেণিটা কী **নয়** ───────────────────────────────────────────
 * এটা কোনো মডিউলের সংরক্ষণের নিয়ম বদলায় না। একটা অর্ডার কীভাবে বসে
 * সেটা [[SalesOrderService]]-এর কাজ, আর সেটাই থাকে — এখানে কেবল
 * ডিভাইস, কিউ আর দ্বন্দ্বের হিসাবটা মোড়া হয়।
 *
 * কারণটা গুরুত্বপূর্ণ: নিয়মগুলো এখানে আবার লিখলে **ওয়েব আর ফোন দুইটা
 * আলাদা উত্তর দিত**, আর কোনটা সত্যি তা বলার উপায় থাকত না।
 *
 * ── কেন প্রতিটা বদল নিজের লেনদেনে ──────────────────────────────────
 * পুরো ব্যাচকে একটা লেনদেনে মুড়লে একটা খারাপ সারি বাকি ঊনপঞ্চাশটাকেও
 * ফিরিয়ে দিত। সেলসম্যানের দিক থেকে দেখতে হত: সকালের সব অর্ডার আটকে
 * আছে, কারণ একটা দোকান ক্রেডিট সীমা পেরিয়েছে।
 *
 * তাই প্রতিটা বদল আলাদা লেনদেনে, আর প্রতিটার নিজের পরিণতি।
 */
final class SyncService
{
    public function __construct(private readonly SyncRegistry $registry) {}

    /**
     * হ্যান্ডসেটটাকে চেনা — প্রতিটা সিঙ্ক কলের শুরুতে।
     *
     * ── ⚠️ কোম্পানি বদলালে ওয়াটারমার্ক শূন্য ────────────────────────
     * একই ফোনে অন্য কোম্পানির কেউ ঢুকলে আগের ওয়াটারমার্ক রেখে দেওয়া
     * মানে: নতুন কোম্পানির ক্যাটালগের যে অংশটা ওই তারিখের আগে বদলেছিল
     * সেটা **কোনোদিন নামত না**। ফোনে তখন আগের কোম্পানির ডেটা বসে
     * থাকত আর নতুনটার অর্ধেক — টেন্যান্টের দেয়ালে একটা ফুটো, যেটা
     * কোনো পর্দায় দেখা যেত না।
     *
     * ফোনের দিকেও একই সিদ্ধান্ত: `ReferenceCache.clearAll()` সাইন-আউটে।
     */
    public function register(
        User $user,
        string $deviceId,
        ?string $appVersion = null,
        ?string $platform = null,
    ): SyncDevice {
        $companyId = CompanyContext::id();

        /*
         * ⚠️ কোম্পানির স্কোপ ছাড়া খোঁজা — আর এটাই এখানকার একমাত্র জায়গা
         * যেখানে সেটা করা হয়।
         *
         * ── কেন, আর কী ভেঙেছিল ──────────────────────────────────────
         * [[BelongsToCompany]] স্বাভাবিকভাবে চলতি কোম্পানির সারিগুলোই
         * দেখায় — যা প্রায় সবখানে ঠিক। কিন্তু ঠিক এখানে **প্রশ্নটাই
         * হলো "এই হ্যান্ডসেট কি অন্য কোম্পানির ছিল"**, আর স্কোপ থাকলে
         * উত্তরটা সবসময় "না" হত: সারিটা দেখাই যেত না।
         *
         * ফল: `first()` null দিত, কোড নতুন সারি বসাতে যেত, আর
         * `device_id`-র unique ইনডেক্সে ধাক্কা খেয়ে **পুরো সিঙ্ক কলটা
         * ৫০০ দিত**। সেলসম্যান কেবল দেখতেন "পাঠানো গেল না", বারবার।
         *
         * ধরা পড়েছে `test_switching_company_on_a_handset_clears_its_watermark`
         * লিখে — কোড পড়ে নয়। ইনডেক্সটা কোম্পানি-নিরপেক্ষ, তাই খোঁজাটাও
         * তা-ই হতে হবে; দুইটার একটা বদলালে অন্যটাও বদলাতে হবে।
         */
        $device = SyncDevice::query()->withoutGlobalScopes()->where('device_id', $deviceId)->first();

        if ($device !== null && (int) $device->company_id !== (int) $companyId) {
            /*
             * স্কোপ ছাড়া মোছা, আর ঠিক একই কারণে: ওয়াটারমার্কগুলো
             * **আগের** কোম্পানির, আর চলতি স্কোপ ওগুলো দেখতেই পায় না।
             *
             * স্কোপসহ লিখলে কোয়েরিটা চলত, কিছুই মুছত না, আর কোনো ভুল
             * দেখাত না — ফোনে আগের কোম্পানির ক্যাটালগ থেকে যেত আর
             * নতুনটার পুরনো অংশ কোনোদিন নামত না। **নীরব ফুটো**, ঠিক
             * যে ধরনেরটা এই প্রকল্প সবচেয়ে ভয় পায়।
             */
            SyncState::query()->withoutGlobalScopes()->where('device_id', $deviceId)->delete();
            $device->company_id = $companyId;
        }

        if ($device === null) {
            $device = new SyncDevice(['device_id' => $deviceId]);
            $device->company_id = $companyId;
        }

        $device->user_id = $user->id;
        $device->app_version = $appVersion;
        $device->platform = $platform;
        $device->last_seen_at = now();
        $device->save();

        return $device;
    }

    /**
     * `POST /sync/{module}/push` — ফোন যা পাঠিয়েছে।
     *
     * ফেরত দেয় প্রতিটা বদলের পরিণতি, **যে ক্রমে এসেছে সেই ক্রমে**।
     * ফোন তবু `changeId` ধরে মেলায়, ক্রম ধরে নয় — দুই পাশ একে অন্যের
     * উপর নির্ভর না করলে একটা পাশ বদলালে অন্যটা ভাঙে না।
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{changeId: string, status: string, message?: string, entityId?: string}>
     */
    public function push(User $user, string $deviceId, string $module, array $rows): array
    {
        $outcomes = [];

        foreach ($rows as $row) {
            /*
             * সারিটা ব্যাখ্যাই করা গেল না — `changeId` ছাড়া ফোনকে
             * কিছু বলার উপায়ও নেই, কারণ সে উত্তর মেলায় ওই চাবি ধরে।
             * তাই যা পাওয়া গেছে তা-ই ফেরত, আর বার্তায় কারণ।
             */
            try {
                $change = PushedChange::fromArray(is_array($row) ? $row : []);
            } catch (ValidationException $refused) {
                $outcomes[] = [
                    'changeId' => is_array($row) && is_string($row['changeId'] ?? null)
                        ? $row['changeId']
                        : '',
                    'status' => SyncChange::REJECTED,
                    'message' => $this->firstMessage($refused),
                ];

                continue;
            }

            $outcomes[] = $this->applyOne($user, $deviceId, $module, $change);
        }

        return $outcomes;
    }

    /**
     * একটা বদল — আর এখানেই দুইবার বসা ঠেকে।
     *
     * @return array{changeId: string, status: string, message?: string, entityId?: string}
     */
    private function applyOne(
        User $user,
        string $deviceId,
        string $module,
        PushedChange $change,
    ): array {
        /*
         * আগেই এসেছে কি না — সারিটা ডাটাবেজে খুঁজে।
         *
         * ── কেন আগে দেখা হয়, unique key-র উপর ছেড়ে দেওয়া হয় না ─────
         * দুইটাই লাগে, আলাদা কাজে। এই পড়াটা **স্বাভাবিক** পুনরাবৃত্তি
         * সামলায় — ফোন উত্তর না পেয়ে এক সেকেন্ড পরে আবার পাঠাল — আর
         * ফোনকে আগের পরিণতিটাই ফেরত দেয়, নতুন করে অর্ডার না বসিয়ে।
         *
         * unique key সামলায় **একই মুহূর্তের** দুইটা অনুরোধ, যেখানে
         * দুইজনেই "নেই" দেখে ফেলেছে। ওটা নিচে ধরা হয়।
         */
        $existing = SyncChange::query()
            ->where('device_id', $deviceId)
            ->where('change_id', $change->changeId)
            ->first();

        if ($existing !== null) {
            return array_filter([
                'changeId' => $change->changeId,
                'status' => $existing->isSettled() ? SyncChange::DUPLICATE : $existing->status,
                'message' => $existing->message,
                'entityId' => $existing->applied_entity_id,
            ], fn ($value) => $value !== null);
        }

        $handler = $this->registry->forEntityType($change->entityType);

        if ($handler === null || $handler::module() !== $module) {
            return $this->refuse($user, $deviceId, $module, $change, __('sync.unknown_entity_type', [
                'type' => $change->entityType,
            ]));
        }

        /*
         * এই ধরনটা কি আদৌ ফোন থেকে আসতে পারে?
         *
         * মালিকের সিদ্ধান্ত: **নেট না থাকলে শুধু অর্ডার**। বাকি সব
         * ধরনের হ্যান্ডলার `acceptsPush()`-এ false বলে, আর এখানে সেটা
         * একটা সৎ প্রত্যাখ্যান হয়ে ফেরে — নীরব উপেক্ষা নয়। একটা পুরনো
         * বিল্ড যদি এমন কিছু পাঠায় যা আজ আর অনুমোদিত নয়, সেলসম্যান
         * কারণটা পর্দায় দেখবেন।
         */
        if (! $handler->acceptsPush()) {
            return $this->refuse($user, $deviceId, $module, $change, __('sync.not_allowed_offline', [
                'type' => $change->entityType,
            ]));
        }

        try {
            $entityId = DB::transaction(fn () => $handler->apply($user, $change));
        } catch (SyncRejection $rejection) {
            return $this->refuse(
                $user, $deviceId, $module, $change, $rejection->getMessage(),
                isConflict: $rejection->isConflict,
                serverSnapshot: $rejection->serverSnapshot,
            );
        } catch (ValidationException $refused) {
            return $this->refuse($user, $deviceId, $module, $change, $this->firstMessage($refused));
        } catch (Throwable $failure) {
            /*
             * ⚠️ এখানে সারিটা REJECTED করা হয় **না**, আর সেটা ইচ্ছাকৃত।
             *
             * একটা অপ্রত্যাশিত ব্যর্থতা মানে "এই বদলটা কখনো নেওয়া যাবে
             * না" নয় — ডাটাবেজ এক মুহূর্তের জন্য ব্যস্ত ছিল, বা একটা
             * বাগ, যেটা কাল সারানো হবে। REJECTED লিখে দিলে সারিটা
             * চিরতরে মরে যেত আর সেলসম্যানকে হাতে আবার লিখতে বলা হত —
             * একটা সাময়িক সমস্যার জন্য।
             *
             * কোনো সারি না বসিয়ে ছুঁড়ে দেওয়া হয়, তাই পুরো পুশটা ব্যর্থ
             * হয় আর ফোন কিউ ধরে রেখে পরে আবার চেষ্টা করে। ভুলটা
             * [[ErrorJournal]]-এ লেখা থাকে, তাই কেউ জানতে পারে।
             */
            throw $failure;
        }

        $record = $this->record(
            $user, $deviceId, $module, $change,
            status: SyncChange::APPLIED,
            appliedEntityId: $entityId,
        );

        return [
            'changeId' => $change->changeId,
            'status' => $record->status,
            'entityId' => $entityId,
        ];
    }

    /**
     * `GET /sync/{module}/pull` — এই ডিভাইস যা এখনো পায়নি।
     *
     * ── কেন `since` প্যারামিটার নেই ─────────────────────────────────
     * ওয়াটারমার্ক সার্ভার রাখে ([[SyncState]])। তাই পরপর দুইবার ডাকলে
     * **হুবহু একই ব্যাচ** ফেরে, আর সেটাই একটা হারিয়ে যাওয়া উত্তরের পর
     * আবার চেষ্টা করাকে নিরাপদ করে।
     *
     * ── কেন একটা হ্যান্ডলার ভাঙলে পুরোটা ভাঙে না ────────────────────
     * একটা ধরনের হ্যান্ডলার ছুঁড়লে আগে পুরো পুল ৫০০ দিত, অর্থাৎ ফোনে
     * **কিছুই** নামত না। এখন যেটা পড়া গেছে সেটা যায়, আর যেটা যায়নি
     * তার নাম `unreadable`-এ যায়।
     *
     * ⚠️ আর তখন ওয়াটারমার্ক **এগোয় না** — নাহলে যে ধরনটা পড়া যায়নি
     * সেটা "সিঙ্ক হয়ে গেছে" ধরে নেওয়া হত আর কোনোদিন আসত না।
     *
     * @return array{records: list<array<string, mixed>>, hasMore: bool, unreadable: list<string>}
     */
    public function pull(User $user, string $deviceId, string $module, int $limit): array
    {
        $since = $this->lastSync($deviceId, $module);
        $handlers = $this->registry->forModule($module);

        $records = [];
        $unreadable = [];
        $hasMore = false;

        foreach ($handlers as $handler) {
            /*
             * ── ⚠️ ছাঁকনির মোটা দাগটা এখানে, এক জায়গায় ─────────────
             * সিঙ্কের দরজায় রুট-স্তরে `can:` নেই (এক অ্যাপ, সব রোল),
             * তাই এই যাচাইটাই সেই ভারটা বহন করে।
             *
             * আগে এটা প্রতিটা হ্যান্ডলারের `pull()`-এর ভেতরে ছিল।
             * সরানো হয়েছে কারণ ওভাবে **ভোলা যেত**: দশম হ্যান্ডলারটা
             * আগেরগুলো নকল করে লিখলে, আর লাইনটা বাদ পড়লে, কিছুই লাল
             * হত না। এখন চাবিটা চুক্তির পদ্ধতি
             * ([[SyncsToDevices::requiredPermission()]]) — না লিখলে
             * ক্লাসটাই তৈরি হয় না — আর প্রয়োগটা এই তিন লাইনে।
             *
             * `unreadable`-এ যায় না: অনুমতি না থাকা কোনো ব্যর্থতা নয়।
             * ওখানে ফেললে ফোন ধরে নিত সিঙ্ক অসম্পূর্ণ, আর ওয়াটারমার্ক
             * কোনোদিন এগোত না — একজন ডিলারের ফোন চিরকাল "সিঙ্ক
             * চলছে" দেখাত।
             */
            $permission = $handler::requiredPermission();

            if ($permission !== null && ! $user->can($permission)) {
                continue;
            }

            try {
                $batch = $handler->pull($user, $since, $limit);
            } catch (Throwable $failure) {
                report($failure);
                $unreadable[] = $handler::entityType();

                continue;
            }

            /*
             * ঠিক `$limit`টা ফিরলে ধরে নেওয়া হয় আরও আছে।
             *
             * এটা একটু রক্ষণশীল — শেষ ব্যাচটা যদি ঠিক `$limit` সমান হয়
             * তাহলে একটা বাড়তি খালি পুল হবে। উল্টোটার চেয়ে ভালো:
             * "আর নেই" ভুল করে বললে ওয়াটারমার্ক এগিয়ে যেত আর বাকি
             * রেকর্ডগুলো চিরতরে বাদ পড়ত।
             */
            if (count($batch) >= $limit) {
                $hasMore = true;
            }

            foreach ($batch as $record) {
                $records[] = $record->toArray();
            }
        }

        return [
            'records' => $records,
            'hasMore' => $hasMore,
            'unreadable' => $unreadable,
        ];
    }

    /**
     * ফোন বলল "পুরোটা পেয়েছি" — ওয়াটারমার্ক এগোয়।
     *
     * ── ⚠️ কেন "এখন", শেষ রেকর্ডের সময় নয় ──────────────────────────
     * শেষ রেকর্ডের `updatedAt` বসালে ওই মুহূর্তে চলতে থাকা একটা
     * লেনদেন — যেটা একই সেকেন্ডে কমিট হচ্ছে — চিরতরে বাদ পড়তে পারত।
     *
     * "এখন" বসানো নিরাপদ **কেবল এই শর্তে** যে ফোন এটা ডাকে যখন সে
     * সত্যিই পুরোটা পেয়েছে। সেই শর্তটা ফোনের দিকে লেখা
     * (`reference_sync.dart`: `!hasMore && unreadable.isEmpty`), আর
     * সার্ভারও নিজের দিকে একই কথা ধরে — দুই পাশে একই নিয়ম, ইচ্ছে করে।
     */
    public function recordSuccessfulPull(string $deviceId, string $module): void
    {
        SyncState::query()->updateOrCreate(
            ['device_id' => $deviceId, 'module' => $module],
            ['company_id' => CompanyContext::id(), 'last_synced_at' => now()],
        );
    }

    public function lastSync(string $deviceId, string $module): ?Carbon
    {
        $state = SyncState::query()
            ->where('device_id', $deviceId)
            ->where('module', $module)
            ->first();

        return $state?->last_synced_at;
    }

    /**
     * মানুষের সিদ্ধান্তের অপেক্ষায় থাকা দ্বন্দ্বগুলো।
     *
     * @return list<SyncConflict>
     */
    public function conflicts(): array
    {
        return SyncConflict::query()
            ->where('status', SyncConflict::PENDING)
            ->orderByDesc('detected_at')
            ->get()
            ->all();
    }

    public function resolveConflict(SyncConflict $conflict, User $user, ?string $note = null): void
    {
        $conflict->status = SyncConflict::RESOLVED;
        $conflict->resolved_at = now();
        $conflict->resolved_by = $user->id;
        $conflict->note = $note;
        $conflict->save();
    }

    /**
     * প্রত্যাখ্যান লিখে রাখা, আর ফোনকে কারণ জানানো।
     *
     * @param  array<string, mixed>|null  $serverSnapshot
     * @return array{changeId: string, status: string, message: string}
     */
    private function refuse(
        User $user,
        string $deviceId,
        string $module,
        PushedChange $change,
        string $message,
        bool $isConflict = false,
        ?array $serverSnapshot = null,
    ): array {
        $status = $isConflict ? SyncChange::CONFLICT : SyncChange::REJECTED;

        $this->record($user, $deviceId, $module, $change, $status, message: $message);

        if ($isConflict) {
            /*
             * দুইটা রূপই রাখা হয় — ফোনেরটা আর সার্ভারেরটা। একটা ছাড়া
             * অন্যটা দেখে "কোনটা রাখব" সিদ্ধান্ত নেওয়া যায় না।
             */
            SyncConflict::query()->create([
                'company_id' => CompanyContext::id(),
                'device_id' => $deviceId,
                'module' => $module,
                'entity_type' => $change->entityType,
                'entity_id' => $change->entityId,
                'reason' => $message,
                'client_payload_json' => $change->payloadJson,
                'server_snapshot_json' => $serverSnapshot === null
                    ? null
                    : json_encode($serverSnapshot, JSON_UNESCAPED_UNICODE),
                'status' => SyncConflict::PENDING,
                'detected_at' => now(),
            ]);
        }

        return [
            'changeId' => $change->changeId,
            'status' => $status,
            'message' => $message,
        ];
    }

    private function record(
        User $user,
        string $deviceId,
        string $module,
        PushedChange $change,
        string $status,
        ?string $message = null,
        ?string $appliedEntityId = null,
    ): SyncChange {
        return SyncChange::query()->create([
            'company_id' => CompanyContext::id(),
            'device_id' => $deviceId,
            'change_id' => $change->changeId,
            'module' => $module,
            'entity_type' => $change->entityType,
            'entity_id' => $change->entityId,
            'operation' => $change->operation,
            'payload_json' => $change->payloadJson,
            'client_version' => $change->clientVersion,
            'status' => $status,
            'message' => $message,
            'applied_entity_id' => $appliedEntityId,
            'user_id' => $user->id,
            'received_at' => now(),
        ]);
    }

    /**
     * ভ্যালিডেশনের প্রথম বার্তাটা — ফোনে একটা বাক্যই যায়।
     *
     * ঘরের নামগুলো বাদ দেওয়া হয়, কারণ ফোনের পর্দায় ঘরগুলোর নাম আলাদা
     * (বা ঘরটাই নেই — অফলাইন অর্ডারের ফর্ম ওয়েবের ফর্ম নয়)।
     */
    private function firstMessage(ValidationException $exception): string
    {
        $messages = $exception->validator->errors()->all();

        return $messages[0] ?? __('sync.refused_without_reason');
    }
}
