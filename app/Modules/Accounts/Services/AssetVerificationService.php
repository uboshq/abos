<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Contracts\KnowsAUsersEmployee;
use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Modules\Accounts\Models\AssetAcknowledgement;
use App\Modules\Accounts\Models\AssetVerification;
use App\Modules\Accounts\Models\AssetVerificationLine;
use App\Modules\Accounts\Models\FixedAsset;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ সরেজমিন গোনা আর দায়িত্ব — স্থায়ী সম্পদ ধাপ ৪ (মালিক, ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ অভিযান শাখা ধরে: খোলার মুহূর্তে শাখার খাতায় থাকা প্রতিটা সম্পদের সারি, কোথায় আর কার কাছে থাকার কথা সেটাসহ।
 * গোনার মানুষ প্রতিটায় ফল লেখেন, চাইলে ছবি দেন। বন্ধ করলে পার্থক্যের প্রতিবেদন স্থির হয়। ⚠️ টাকা নড়ে না — না পাওয়া
 * জিনিস খাতা থেকে বের করা আলাদা কাজ (হারানো, সইসহ; ধাপ ৩)।
 */
final class AssetVerificationService
{
    public function __construct(private readonly NumberSeriesEngine $numbers) {}

    public function open(int $branchId, ?string $title = null, Carbon|string|null $on = null): AssetVerification
    {
        // ⓘ শাখাটা এই কোম্পানির আর মানুষটার দেয়ালের ভেতরে — শাখার মডেলের দেয়ালই সেটা দেখে
        if (! Branch::query()->whereKey($branchId)->exists()) {
            throw ValidationException::withMessages(['branch_id' => __('accounts::asset.verify_branch_wrong')]);
        }

        $on = Carbon::parse($on ?? now())->startOfDay();

        return DB::transaction(function () use ($branchId, $title, $on) {
            // ⛔ শাখায় একসাথে একটাই খোলা অভিযান — দুইটা হলে একই জিনিস দুই তালিকায় দুই রকম ফল পেত
            $already = AssetVerification::query()->withoutGlobalScope('user-branch')
                ->where('branch_id', $branchId)->where('status', AssetVerification::OPEN)->lockForUpdate()->exists();

            if ($already) {
                throw ValidationException::withMessages(['branch_id' => __('accounts::asset.verify_already_open')]);
            }

            $campaign = AssetVerification::query()->create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $branchId,
                'document_no' => $this->numbers->next(AssetVerification::SERIES, $branchId, $on),
                'title' => ($title ?? '') ?: null,
                'started_on' => $on->toDateString(),
                'status' => AssetVerification::OPEN,
                'created_by' => auth()->id(),
            ]);

            $assets = FixedAsset::acrossBranches()->where('branch_id', $branchId)->inService()->orderBy('id')->get();

            if ($assets->isEmpty()) {
                throw ValidationException::withMessages(['branch_id' => __('accounts::asset.verify_nothing_to_count')]);
            }

            foreach ($assets as $asset) {
                AssetVerificationLine::query()->create([
                    'company_id' => $campaign->company_id,
                    'verification_id' => $campaign->id,
                    'fixed_asset_id' => $asset->id,
                    'expected_location' => $asset->location,
                    'expected_custodian_id' => $asset->custodian_id,
                ]);
            }

            return $campaign->refresh();
        });
    }

    /**
     * একটা সম্পদের ফল — ভুল জায়গায় হলে কোথায় পাওয়া গেল লিখতেই হবে; ছবি ঐচ্ছিক।
     */
    public function mark(AssetVerificationLine $line, string $result, ?string $foundLocation = null, ?string $note = null, ?UploadedFile $photo = null): AssetVerificationLine
    {
        if (! in_array($result, AssetVerificationLine::RESULTS, true)) {
            throw ValidationException::withMessages(['result' => __('accounts::asset.verify_result_wrong')]);
        }

        if ($result === AssetVerificationLine::WRONG_LOCATION && blank($foundLocation)) {
            throw ValidationException::withMessages(['found_location' => __('accounts::asset.verify_where_found')]);
        }

        if (! $line->verification?->isOpen()) {
            throw ValidationException::withMessages(['result' => __('accounts::asset.verify_closed')]);
        }

        return DB::transaction(function () use ($line, $result, $foundLocation, $note, $photo) {
            $line->update([
                'result' => $result,
                'found_location' => ($foundLocation ?? '') ?: null,
                'note' => ($note ?? '') ?: null,
                'checked_by' => auth()->id(),
                'checked_at' => now(),
            ]);

            if ($photo !== null) {
                app(AttachmentEngine::class)->store($photo, 'accounts', AssetVerificationLine::ATTACHMENT_ENTITY, (int) $line->id);
            }

            return $line->refresh();
        });
    }

    public function close(AssetVerification $campaign): AssetVerification
    {
        // ⓘ সারিতে তালা দিয়ে আবার দেখা — দুইজন একসাথে বন্ধ চাপলে দ্বিতীয়জন পরিষ্কার কথা পান
        return DB::transaction(function () use ($campaign) {
            $locked = AssetVerification::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['status' => __('accounts::asset.verify_closed')]);
            }

            $locked->update(['status' => AssetVerification::CLOSED, 'closed_on' => now()->toDateString(), 'closed_by' => auth()->id()]);

            return $locked->refresh();
        });
    }

    /**
     * ⭐ পার্থক্য — কতগুলো কোন ফলে, আর যেগুলো মেলেনি (না পাওয়া, ভাঙা, ভুল জায়গা, না গোনা)।
     *
     * @return array{counts: array<string, int>, total: int, exceptions: Collection<int, AssetVerificationLine>}
     */
    public function variance(AssetVerification $campaign): array
    {
        $lines = $campaign->lines()->with('asset')->get();
        $counts = ['unchecked' => 0];

        foreach (AssetVerificationLine::RESULTS as $result) {
            $counts[$result] = 0;
        }

        foreach ($lines as $line) {
            $counts[$line->result ?? 'unchecked']++;
        }

        return [
            'counts' => $counts,
            'total' => $lines->count(),
            'exceptions' => $lines->filter(fn (AssetVerificationLine $l) => $l->result !== AssetVerificationLine::FOUND)->values(),
        ];
    }

    // ── দায়িত্ব ─────────────────────────────────────────────────────────

    /**
     * ⭐ লগইন করা মানুষটার দায়িত্বের সম্পদ — শাখার দেয়াল নয়, দায়িত্বই দেয়াল (কর্মী নিজের জিনিস দেখেন)।
     *
     * @return Collection<int, FixedAsset>
     */
    public function mine(int $userId): Collection
    {
        $employee = app(KnowsAUsersEmployee::class)->employeeIdOf($userId);

        if ($employee === null) {
            return new Collection;
        }

        return FixedAsset::acrossBranches()->where('custodian_id', $employee)->inService()
            ->with(['branch', 'category'])->orderBy('name')->get();
    }

    /**
     * ⭐ "বুঝে নিয়েছি" — কেবল যিনি এখন দায়িত্বে, তিনিই।
     */
    public function acknowledge(FixedAsset $asset, int $userId, string $condition, ?string $note = null): AssetAcknowledgement
    {
        $employee = app(KnowsAUsersEmployee::class)->employeeIdOf($userId);

        if ($employee === null || (int) $asset->custodian_id !== $employee || ! $asset->isInService()) {
            throw ValidationException::withMessages(['condition' => __('accounts::asset.ack_not_yours')]);
        }

        if (! in_array($condition, AssetAcknowledgement::CONDITIONS, true)) {
            throw ValidationException::withMessages(['condition' => __('accounts::asset.ack_condition_wrong')]);
        }

        return AssetAcknowledgement::query()->create([
            'company_id' => $asset->company_id,
            'fixed_asset_id' => $asset->id,
            'employee_id' => $employee,
            'user_id' => $userId,
            'acknowledged_at' => now(),
            'condition' => $condition,
            'note' => ($note ?? '') ?: null,
        ]);
    }
}
