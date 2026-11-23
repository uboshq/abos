<?php

declare(strict_types=1);

namespace App\Core\Bulk;

use App\Core\Support\LookPreview;
use App\Core\Support\LookRegistry;
use App\Core\Support\Ui;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * ⭐ তালিকার একসাথে কাজ, ধাপ ২ — মালিক, ১৯ নভেম্বর ২০২৬ (নমুনা Q2W6mM3S v3, "ok"): সারি বেছে "অনুমোদনে পাঠান · খসড়া বাতিল ·
 *   ছাপুন · Excel" (আর যেখানে একটা একটা কাগজের অনুমোদন আগে থেকেই আছে, সেখানে "অনুমোদন দিন")।
 *
 * ⓘ ভাউচারের একসাথে পোস্টের একই নীতি ([[VoucherBulkPostController]]):
 *   · প্রতিটা সারি সার্ভারে আবার দেখা হয় — চাবি, শাখার দেয়াল (মডেলের নিজের প্রশ্ন দিয়ে তোলা; যা ওঠে না তা "পাওয়া গেল না"),
 *     অবস্থা, আর নিজের কাগজ নিজে অনুমোদন নয় (মালিক ছাড়া, [[MakerChecker]])।
 *   · কাজটা সেই দরজা দিয়েই যেটা কাগজের নিজের বোতাম ডাকে — তাই একসাথে করা কোনো নিয়ম এড়াতে পারে না।
 *   · একটা আটকালে বাকিগুলো থামে না; ফল "n টা হলো, m টা বাদ (কেন)" ([[BulkOutcome]])।
 * ⛔ কেবল তিন ABOS রূপে ([[showsHere()]]) — SAP আর Dynamic আগের মতোই, একটা পিক্সেলও নয়।
 */
final class BulkRun
{
    /** ⓘ এক বারে সর্বোচ্চ — তালিকার এক পাতার মাপ, ভাউচারের সীমার সমান */
    public const MAX = 50;

    /** ⭐ ফর্মের বোতামের নাম — `action=submit|cancel|approve` */
    public const SUBMIT = 'submit';

    public const CANCEL = 'cancel';

    public const APPROVE = 'approve';

    public const PRINT = 'print';

    public const EXPORT = 'export';

    /**
     * ⭐ এই মানুষটির রূপে কি একসাথে কাজের বার বসে — কেবল তিন ABOS রূপ (rose, navy, blue; [[Ui::FOLD_MENU_LOOKS]])।
     * ⛔ SAP (`tiles`) আর Dynamic-এ টিক-বাক্সের কলাম আর বার দুটোই নেই — মালিক, ১৯–২০ নভেম্বর ২০২৬।
     */
    public static function showsHere(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return in_array(Ui::clean(LookRegistry::lookFor(LookPreview::orChosen($user->ui))), Ui::FOLD_MENU_LOOKS, true);
    }

    /**
     * ⭐ বারটা কি আঁকা হবে — ABOS রূপ, আর অন্তত একটা কাজ (চাবিতে খোলা কাজ, নয়তো ছাপা/Excel-এর রপ্তানির চাবি)।
     * ⛔ কোনো কাজ না থাকলে টিক-বাক্সের কলামও নয় — বারহীন টিক-বাক্স অর্থহীন।
     *
     * @param  list<array{action: string, url: string}>  $actions
     */
    public static function barOpen(array $actions): bool
    {
        return self::showsHere()
            && ($actions !== [] || (auth()->user()?->can('system_admin.list.export') ?? false));
    }

    /**
     * বাছা id-গুলো — পূর্ণসংখ্যা, একবার করে, সর্বোচ্চ [[MAX]]।
     *
     * @return list<int>
     */
    public static function ids(Request $request): array
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:'.self::MAX],
            'ids.*' => ['integer'],
        ]);

        return array_values(array_unique(array_map('intval', $data['ids'])));
    }

    /**
     * ⓘ ফেরার ঠিকানা — কেবল এই সাইটের সেই তালিকা (একই ট্যাব, একই ছাঁকনি); অন্য কিছু এলে তালিকার মাথা।
     * ⛔ বাইরের ঠিকানা ফেরার পথে ঢুকলে পাতাটা অন্য সাইটে পাঠানোর দরজা হত।
     */
    public static function back(Request $request, string $listUrl): string
    {
        $back = (string) $request->input('back', '');
        $base = strtok($listUrl, '?') ?: $listUrl;

        return $back !== '' && str_starts_with($back, $base) ? $back : $listUrl;
    }

    /**
     * ⭐ প্রতিটা সারিতে আগে প্রশ্ন, তারপর কাজ।
     *
     * @template T of Model
     *
     * @param  list<int>  $asked  মানুষ যা বেছেছেন
     * @param  iterable<T>  $found  মডেলের নিজের প্রশ্নে যা উঠল (কোম্পানি আর শাখার দেয়াল পেরিয়ে)
     * @param  Closure(T): string  $label  সারির নাম, বার্তার জন্য (কাগজের নম্বর, নাম)
     * @param  Closure(T): ?string  $why  বাদের কারণের চাবি (`core.bulk.why.*`) বা নিজের লেখা, নইলে null
     * @param  Closure(T): mixed  $do  কাজ — কাগজের নিজের বোতামের দরজা; ছোঁড়া ভুল সেই সারির বাদের কারণ হয়
     */
    public static function each(array $asked, iterable $found, Closure $label, Closure $why, Closure $do): BulkOutcome
    {
        $outcome = new BulkOutcome;
        $seen = [];

        foreach ($found as $row) {
            $seen[(int) $row->getKey()] = true;
            $name = (string) $label($row);
            $reason = $why($row);

            if ($reason !== null) {
                $outcome->skip($name, $reason);

                continue;
            }

            try {
                $do($row);
                $outcome->done();
            } catch (ValidationException $e) {
                $outcome->skip($name, (string) (collect($e->errors())->flatten()->first() ?? $e->getMessage()));
            } catch (AuthorizationException) {
                $outcome->skip($name, 'not_allowed');
            } catch (HttpExceptionInterface $e) {
                $outcome->skip($name, $e->getStatusCode() === 403 ? 'not_allowed' : 'refused');
            } catch (\DomainException $e) {
                $outcome->skip($name, $e->getMessage() !== '' ? $e->getMessage() : 'refused');
            } catch (Throwable $e) {
                // ⛔ ডাটাবেসের ভুলের লেখা পর্দায় নয় (SQL ফাঁস) — লগে যায়, পর্দায় কেবল "আটকে গেছে"
                report($e);
                $outcome->skip($name, 'refused');
            }
        }

        // ⓘ যা বাছা হয়েছিল কিন্তু ওঠেনি — অন্য শাখা বা কোম্পানির, মুছে গেছে, নয়তো হাতে বানানো id
        foreach ($asked as $id) {
            if (! isset($seen[$id])) {
                $outcome->skip('#'.$id, 'not_found');
            }
        }

        return $outcome;
    }

    /**
     * ⭐ একটা কাগজের অপেক্ষমাণ অনুমোদন এই মানুষ এখন দিতে পারেন কি না — না পারলে কারণের চাবি, পারলে null।
     *
     * ⓘ একটা একটা অনুমোদনের একই প্রশ্ন ([[ApprovalEngine::canDecide()]] — পালা, ধাপ, টাকার সীমা), সাথে:
     *   ⛔ নিজের কাগজ নিজে নয় — মালিক ছাড়া ([[MakerChecker::isAnotherHand()]]; মালিক, ২০ নভেম্বর ২০২৬: "মালিক নিজের কাগজ নিজে")।
     */
    public static function approvalFor(Model $paper): ?\App\Models\Approval
    {
        return \App\Models\Approval::query()
            ->where('approvable_type', $paper::class)
            ->where('approvable_id', $paper->getKey())
            ->where('status', \App\Models\Approval::PENDING)
            ->orderByDesc('id')
            ->first();
    }

    public static function whyNotApprove(Model $paper, \App\Models\User $user): ?string
    {
        $approval = self::approvalFor($paper);

        if ($approval === null) {
            return 'not_waiting';
        }

        // ⓘ অনুমোদনের ইনবক্সের একসাথে সইয়ের একই নিয়ম ([[BulkApproval::review()]]): টাকার কাজ একটা একটা, এক ধাপের কাগজ, সীমা, পালা
        $bulk = app(\App\Core\Engines\Approval\BulkApproval::class);
        $engine = app(\App\Core\Engines\Approval\ApprovalEngine::class);
        $why = $bulk->review([(int) $approval->id], $user)['skip'][0]['why'] ?? null;

        // ⭐ মালিক নিজের কাগজ নিজে অনুমোদন দেন (২০ নভেম্বর ২০২৬) — "নিজের" বাদ, বাকি নিয়ম একই
        if ($why === 'own' && \App\Core\Support\MakerChecker::isAnotherHand($user, $approval->requested_by !== null ? (int) $approval->requested_by : null)) {
            $why = match (true) {
                $bulk->movesMoney($approval) => 'money',
                ($engine->standingOf($approval)['levels'] ?? 1) > 1 => 'multi_step',
                ! $engine->canDecide($approval, $user) => 'not_allowed',
                default => null,
            };
        }

        return match ($why) {
            null => null,
            'own' => 'own_paper',
            'not_pending' => 'not_waiting',
            default => $why,
        };
    }

    /** ⓘ অনুমোদন দেওয়া — একটা একটা অনুমোদনের একই দরজা ([[ApprovalEngine::approve()]]); কাগজ পাকা হওয়া তার পরের কাজ, মডিউলের নিজের */
    public static function approve(Model $paper, \App\Models\User $user): void
    {
        $approval = self::approvalFor($paper) ?? throw new \DomainException((string) __('core.bulk.why.not_waiting'));

        app(\App\Core\Engines\Approval\ApprovalEngine::class)->approve($approval, $user);
    }

    /**
     * ⭐ তালিকার টিক-বাক্সের কলাম — [[x-ui.table]]-এর একটা কলাম; ঘরটা কেবল যে সারি বাছা যায় তাতে।
     * ⓘ চেকবক্স টেবিলের বাইরের ফর্মে (`form="<id>"`) — টেবিলের ভেতরে `<form>` বসালে HTML ওটাকে বাইরে ঠেলে দিত।
     *
     * @param  Closure(Model): string  $label
     * @param  (Closure(Model): bool)|null  $eligible
     * @return array<string, mixed>
     */
    public static function pickColumn(string $formId, Closure $label, ?Closure $eligible = null): array
    {
        return ['key' => 'pick', 'label' => __('core.bulk.pick'), 'width' => '3rem',
            'render' => fn (Model $row) => $eligible === null || $eligible($row)
                ? new HtmlString('<label class="flex min-h-(--spacing-touch) items-center justify-center">'
                    .'<input type="checkbox" name="ids[]" value="'.(int) $row->getKey().'" form="'.e($formId).'" class="size-4" data-bulk-pick>'
                    .'<span class="sr-only">'.e(__('core.bulk.pick').' '.$label($row)).'</span></label>')
                : ''];
    }
}
