{{--
    ⭐ নতুন খরচের দাবি বা অগ্রিম অনুরোধ — কর্মী নিজে (মালিকের আদেশ, ৭ অক্টোবর ২০২৬; [[ExpenseClaimService::submit()]])।
    ⓘ অগ্রিমে খাত আর তারিখ লাগে না — Alpine দিয়ে লুকানো; সার্ভার নিজেই যাচাই করে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('hr::claim.new') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('hr::claim.new')" :subtitle="__('hr::claim.title')" />
    </x-slot:header>

    <x-ui.errors />

    @if ($employee === null)
        <p class="mb-4 rounded-(--radius-field) bg-(--color-badge-warning-bg) px-3 py-2 text-sm text-(--color-badge-warning-ink)">
            {{ __('hr::claim.no_employee') }}
        </p>
    @endif

    <section data-boxed data-claim-form class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <form method="POST" action="{{ route('hr.claim.store') }}" enctype="multipart/form-data"
              x-data="{ kind: @js(old('kind', \App\Modules\Hr\Models\ExpenseClaim::EXPENSE)) }"
              class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @csrf

            <x-ui.select name="kind" :label="__('hr::claim.kind')" x-model="kind" required
                         :options="collect(\App\Modules\Hr\Models\ExpenseClaim::KINDS)->mapWithKeys(fn ($k) => [$k => __('hr::claim.kind_'.$k)])->all()"
                         :selected="old('kind', \App\Modules\Hr\Models\ExpenseClaim::EXPENSE)" />

            <div x-show="kind === 'expense'">
                <x-ui.select name="expense_account_id" :label="__('hr::claim.head')" :placeholder="__('hr::claim.head_pick')"
                             :options="$heads->mapWithKeys(fn ($a) => [$a->id => $a->code.' — '.$a->name()])->all()"
                             :selected="old('expense_account_id')" />
            </div>

            <x-ui.field name="amount" type="number" step="0.01" min="0.01" :label="__('hr::claim.amount')" required />

            <label class="grid gap-1" x-show="kind === 'expense'">
                <span class="text-2xs text-(--color-ink-muted)">{{ __('hr::claim.spent_on') }}</span>
                <x-ui.date name="spent_on" :value="old('spent_on', now()->toDateString())" />
            </label>

            <x-ui.field name="reason" :label="__('hr::claim.reason')" required />

            <div x-show="kind === 'expense'">
                <label for="claim-receipt" class="mb-1 block text-sm font-medium">{{ __('hr::claim.receipt') }}</label>
                <input id="claim-receipt" type="file" name="receipt" accept="image/*,application/pdf"
                       class="w-full text-sm file:me-2 file:rounded-(--radius-field) file:border file:border-(--color-border) file:bg-(--color-surface-app) file:px-3 file:py-1.5 file:text-sm">
                <span class="mt-1 block text-2xs text-(--color-ink-muted)">{{ __('hr::claim.receipt_hint') }}</span>
            </div>

            <div class="flex flex-wrap items-center gap-2 sm:col-span-2 lg:col-span-3">
                <x-ui.button type="submit" tone="primary">{{ __('hr::claim.send') }}</x-ui.button>
                <x-ui.button tone="secondary" :href="route('hr.claim.index')">{{ __('core.action.cancel') }}</x-ui.button>
            </div>
        </form>
    </section>
</x-layouts.app>
