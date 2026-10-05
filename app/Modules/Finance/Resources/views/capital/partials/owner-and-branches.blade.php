{{--
    ⭐ কোম্পানির মালিক আর শাখা ধরে মূলধন — মালিকের আদেশ, ৫ অক্টোবর ২০২৬:
    *"মূলধন ও বিনিয়োগে মালিকের নামই নেই, কোথায় বসালে তাহলে"* আর *"প্রতিটা শাখার মূলধন আলাদা দেখাবে, নাকি একসাথে?"* — দুটোই।

    ⓘ মালিক বাছা না থাকলে প্রথমবার বাছতে বা নতুন নাম দিতে বলে (কেবল সুপার অ্যাডমিন); বাছার মুহূর্তে খাতার খোলা জের তাঁর নামে
    শুরুর মূলধন হয়ে বসে ([[CapitalController::setOwner()]])। শাখার সারি এক লাইনে এক কথা — মালিকের পর্দা ডান দিক কাটে।
--}}
@if ($owner === null)
    <section data-owner-needed role="status"
             class="border-b border-(--color-border) bg-(--color-badge-pending-bg) px-4 py-3 text-sm text-(--color-badge-pending-ink)">
        <p>{{ __('finance::message.owner_needed') }}</p>

        @if ($mayChooseOwner)
            <form method="POST" action="{{ route('finance.capital.owner') }}" class="mt-2 flex flex-wrap items-end gap-2">
                @csrf
                <x-ui.select name="person_id" :label="__('finance::message.owner_pick')"
                             :options="$ownerChoices->mapWithKeys(fn ($p) => [$p->id => $p->name()])"
                             placeholder="-" />
                <x-ui.field name="person_new" :label="__('finance::message.owner_new')" />
                <x-ui.button type="submit" tone="primary">{{ __('finance::message.owner_save') }}</x-ui.button>
            </form>
        @endif
    </section>
@endif

@if (count($branchCapital['rows']) > 0)
    <section data-branch-capital class="border-b border-(--color-border) px-4 py-3 text-sm">
        <h3 class="font-semibold">{{ __('finance::message.branch_capital') }}</h3>
        <ul class="mt-1">
            @foreach ($branchCapital['rows'] as $row)
                <li class="flex justify-between gap-4 py-0.5">
                    <span>{{ $row['branch'] }}</span>
                    <span class="num">{{ \App\Core\Support\Money::format($row['total']) }}</span>
                </li>
            @endforeach
            <li class="flex justify-between gap-4 border-t border-(--color-border) pt-1 font-semibold" data-branch-capital-total>
                <span>{{ __('finance::message.company_total') }}</span>
                <span class="num">{{ \App\Core\Support\Money::format($branchCapital['total']) }}</span>
            </li>
        </ul>
    </section>
@endif
