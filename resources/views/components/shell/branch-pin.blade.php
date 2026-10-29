{{--
    ⛔ ফর্ম যে শাখায় খোলা, জমাও সেই শাখায় — RCV-0143 / মালিক: "কোনোভাবেই অন্য শাখায় নয়" (ec, ১৯ অক্টোবর ২০২৬)।

    [[KeepsAFormInItsOwnBranch]] জমা থামালে এখানে কারণ আর "X-এই জমা দিন" বোতাম; ছাড়ের জানালার বা খোলার শাখায়
    জমার খবর এক লাইনে। ⓘ খোলসে একবার, প্রতিটা পর্দায় নয় — ফর্মগুলো এখান থেকেই ফেরত আসে (`back()`)।

    ⭐ বোতামটা একটা সাধারণ POST ফর্ম: আগের ইনপুট লুকানো ঘরে, সাথে সই-করা চিরকুট। কোনো JavaScript নয়, তাই CSP-র কিছু
    লাগে না। ⚠️ পাতার ফর্মের **বাইরে** বসে (`$slot`-এর আগে) — ফর্মের ভিতরে ফর্ম HTML-এ চলে না।
--}}
@php
    $branchPin = session(\App\Core\Services\FormBranchPin::OFFER);
    $branchPinNotice = session(\App\Core\Services\FormBranchPin::NOTICE);
@endphp

@if (is_array($branchPin) && filled($branchPin['message'] ?? null))
    <div role="alert" data-branch-pin
         class="mb-4 rounded-(--radius-field) bg-(--color-badge-warning-bg) px-3 py-2 text-sm text-(--color-badge-warning-ink)">
        <p>{{ $branchPin['message'] }}</p>

        @if (filled($branchPin['confirm'] ?? null) && filled($branchPin['action'] ?? null))
            <form method="POST" action="{{ $branchPin['action'] }}" class="mt-2" data-branch-pin-confirm>
                @csrf
                <input type="hidden" name="{{ \App\Core\Services\FormBranchPin::CONFIRM }}" value="{{ $branchPin['confirm'] }}">
                @foreach (app(\App\Core\Services\FormBranchPin::class)->hiddenFields((array) session()->getOldInput()) as [$pinName, $pinValue])
                    <input type="hidden" name="{{ $pinName }}" value="{{ $pinValue }}">
                @endforeach
                <x-ui.button type="submit" tone="warning">{{ __('core.branch_pin.submit_in', ['opened' => $branchPin['opened'] ?? '']) }}</x-ui.button>
            </form>

            <p class="mt-2">{{ __('core.branch_pin.or_here', ['current' => $branchPin['current'] ?? '']) }}</p>

            @if (! empty($branchPin['files']))
                <p class="mt-1">{{ __('core.branch_pin.files_again') }}</p>
            @endif
        @endif
    </div>
@endif

@if (is_string($branchPinNotice) && $branchPinNotice !== '')
    <div role="status" data-branch-pin-notice
         class="mb-4 rounded-(--radius-field) bg-(--color-badge-warning-bg) px-3 py-2 text-sm text-(--color-badge-warning-ink)">
        {{ $branchPinNotice }}
    </div>
@endif
