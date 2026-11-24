<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Models\Approval;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use ReflectionNamedType;

/**
 * The phone's paper words, and who may open which paper — one place for every phone door that names a paper.
 *
 * ⭐ Moved out of [[DocumentApiController]] (messenger phase 1, 21 Nov 2026): a paper card in a message asks the
 * same two questions — "which paper is `SalesInvoice` + this public_id" and "may this person open it" — and a second
 * copy of the answer would one day disagree with the print door. The print door now asks this class too.
 *
 * ⓘ Type names: the model's class name on the paper's web print route ([[PaperTrail::DOCUMENT_ROUTES]]).
 * ⛔ Payroll papers (`hr_…`) never reach a phone (contract §4, §5).
 */
final class PhonePaperDoor
{
    public const DESK_ONLY_PREFIX = 'hr_';

    /** @var array<string, array{0: string, 1: class-string<Model>}>|null */
    private ?array $printable = null;

    /**
     * Type name → [print-list type, model].
     *
     * ⛔ The first model wins (`??=`) — a second paper of the same model (gate pass, DO) never takes the phone's type
     * (11 Oct 2026).
     *
     * @return array<string, array{0: string, 1: class-string<Model>}>
     */
    public function printable(): array
    {
        if ($this->printable !== null) {
            return $this->printable;
        }

        $out = [];

        foreach (PaperTrail::DOCUMENT_ROUTES as $documentType => $routeName) {
            if (str_starts_with($documentType, self::DESK_ONLY_PREFIX)) {
                continue;
            }

            $route = Route::getRoutes()->getByName($routeName);

            /*
             * ⛔ ছাপার ঠিকানায় নথির আইডি ছাড়া আরও ঘর থাকলে (স্টক ছাড়া ভাউচার: `{kind}` + `{voucher}`) ফোনের দরজা সেটা বানাতে
             * পারে না — কেবল প্রথম ঘরটা ভরে ([[DocumentApiController]])। তাই সেই কাগজ ডেস্কেই, ফোনে নয় (বন্ধ মানে বন্ধ, ৫০০ নয়)।
             */
            if ($route !== null && count($route->parameterNames()) !== 1) {
                continue;
            }

            foreach ($route?->signatureParameters(['subClass' => Model::class]) ?? [] as $parameter) {
                $type = $parameter->getType();
                $class = $type instanceof ReflectionNamedType ? $type->getName() : null;

                if (is_string($class) && is_subclass_of($class, Model::class)) {
                    $out[class_basename($class)] ??= [$documentType, $class];

                    break;
                }
            }
        }

        return $this->printable = $out;
    }

    /**
     * The paper by its phone name and public_id — inside the model's own walls (company, branch reach).
     *
     * @return array{0: string, 1: class-string<Model>, 2: Model}|null [print-list type, model class, the paper]
     */
    public function find(string $type, string $publicId): ?array
    {
        [$documentType, $class] = $this->printable()[$type] ?? [null, null];

        if ($documentType === null) {
            return null;
        }

        $document = method_exists($class, 'findInReach')
            ? $class::findInReach($publicId, 'public_id')
            : $class::query()->wherePublicId($publicId)->first();

        return $document === null ? null : [$documentType, $class, $document];
    }

    /**
     * ⛔ The keys of the paper's web print route — and the two long-standing exceptions:
     *   - the requester or a decider of a PENDING approval sees the paper before signing (owner, 7 Oct 2026);
     *   - without `accounts.report` a cashier sees only the vouchers they wrote themselves.
     * ⚠️ An empty key list means "unknown", never "open to all" — unknown guards stay shut.
     */
    public function mayOpen(User $user, string $documentType, Model $document, bool $checkBranch = false): bool
    {
        $abilities = PaperTrail::abilitiesFor($documentType);

        if ($abilities === []) {
            return false;
        }

        if ($this->signsFor($user, $document)) {
            return true;
        }

        /*
         * ⛔ The branch wall of THIS person — the paper was found inside the asker's reach, which for a message card
         * is the sender's, not the receiver's. A key without the branch still opens nothing on the web.
         * ⓘ The print door does not ask this: it found the paper inside the asker's own reach already.
         */
        $branchId = $checkBranch ? $document->getAttribute('branch_id') : null;
        if ($branchId !== null) {
            $reach = app(DataScope::class)->viewBranchIds($user);
            if ($reach !== null && ! in_array((int) $branchId, array_map('intval', $reach), true)) {
                return false;
            }
        }

        if ($documentType === 'accounts_voucher' && ! $user->can('accounts.report')) {
            return $user->can('accounts.voucher.create') && (int) $document->getAttribute('created_by') === (int) $user->id;
        }

        foreach ($abilities as $ability) {
            if (! $user->can($ability)) {
                return false;
            }
        }

        return true;
    }

    /** Is this person the requester or a decider of a pending approval on this paper? */
    public function signsFor(User $user, Model $document): bool
    {
        $engine = app(ApprovalEngine::class);

        return Approval::query()
            ->where('approvable_type', $document->getMorphClass())
            ->where('approvable_id', $document->getKey())
            ->pending()
            ->get()
            ->contains(fn (Approval $a): bool => (int) $a->requested_by === (int) $user->id || $engine->canDecide($a, $user));
    }
}
