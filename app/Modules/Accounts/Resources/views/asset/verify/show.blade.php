{{--
    ⭐ একটা গোনার অভিযান — উপরে পার্থক্যের হিসাব, নিচে প্রতিটা সম্পদ (স্থায়ী সম্পদ ধাপ ৪)।
    ⓘ খোলা থাকলে প্রতিটা সারিতে ফল, পাওয়ার জায়গা, মন্তব্য আর ছবি। বন্ধ হলে সব স্থির — পার্থক্যের প্রতিবেদনটাই থাকে।
--}}
@php
    $box = 'h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm';
    $canCount = $campaign->isOpen() && auth()->user()->can('accounts.asset.verify');
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $campaign->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::asset.verify_heading', ['branch' => $campaign->branch?->name()])"
                          :subtitle="$campaign->document_no.' · '.$campaign->started_on?->format('d M Y').($campaign->title ? ' · '.$campaign->title : '')" />
    </x-slot:header>

    @if (session('status'))
        <div role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
            {{ session('status') }}
        </div>
    @endif
    <x-ui.errors />

    {{-- ⭐ পার্থক্য — কতগুলো কোন ফলে --}}
    <div class="mb-5 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @foreach (['found', 'not_found', 'damaged', 'wrong_location', 'unchecked'] as $key)
            <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4 py-3">
                <p class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">{{ __('accounts::asset.verify_'.$key) }}</p>
                <p class="num text-xl font-semibold">{{ $variance['counts'][$key] }}</p>
            </div>
        @endforeach
        <div data-boxed class="flex flex-col justify-between gap-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4 py-3">
            <p class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                {{ __('accounts::asset.verify_status_'.$campaign->status) }} · {{ $variance['total'] }}
            </p>
            @if ($canCount)
                <form method="POST" action="{{ route('accounts.asset.verify.close', $campaign) }}">
                    @csrf
                    <x-ui.button type="submit" tone="secondary">{{ __('accounts::asset.verify_close_action') }}</x-ui.button>
                </form>
            @endif
            <x-ui.button tone="secondary" icon="printer" :href="route('accounts.asset.labels', ['branch_id' => $campaign->branch_id])" target="_blank">
                {{ __('accounts::asset.labels_action') }}
            </x-ui.button>
        </div>
    </div>

    <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold">{{ __('accounts::asset.verify_lines') }}</h2>
        <ul class="divide-y divide-(--color-border) text-sm">
            @foreach ($lines as $line)
                <li class="px-4 py-3">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <x-ui.drill source="fixed_asset" :id="$line->fixed_asset_id">{{ $line->asset?->tag_no ?: $line->asset?->document_no }}</x-ui.drill>
                        <span class="font-medium">{{ $line->asset?->name }}</span>
                        <span class="text-2xs text-(--color-ink-muted)">
                            {{ __('accounts::asset.verify_expected') }}: {{ $line->expected_location ?? '—' }}
                            · {{ $people['employee:'.$line->expected_custodian_id] ?? '—' }}
                        </span>
                        <span class="ms-auto">
                            <x-ui.badge :tone="match ($line->result) { 'found' => 'success', null => 'draft', default => 'pending' }">
                                {{ $line->resultLabel() }}
                            </x-ui.badge>
                        </span>
                    </div>

                    @if ($line->result !== null)
                        <p class="mt-1 text-2xs text-(--color-ink-muted)">
                            @if ($line->found_location) {{ __('accounts::asset.verify_found_at') }}: {{ $line->found_location }} · @endif
                            @if ($line->note) {{ $line->note }} · @endif
                            {{ $line->checker?->name }} · {{ $line->checked_at?->format('d M Y H:i') }}
                            @foreach ($photos[$line->id] ?? [] as $photo)
                                · <a href="{{ route('attachment.download', $photo) }}" class="text-(--color-brand-500) hover:underline" target="_blank">{{ __('accounts::asset.verify_photo') }}</a>
                            @endforeach
                        </p>
                    @endif

                    @if ($canCount)
                        <form method="POST" action="{{ route('accounts.asset.verify.mark', $line) }}" enctype="multipart/form-data"
                              class="mt-2 flex flex-wrap items-end gap-2">
                            @csrf
                            <select name="result" required aria-label="{{ __('accounts::asset.verify_result') }}" class="{{ $box }}">
                                @foreach (\App\Modules\Accounts\Models\AssetVerificationLine::RESULTS as $result)
                                    <option value="{{ $result }}" @selected($line->result === $result)>{{ __('accounts::asset.verify_'.$result) }}</option>
                                @endforeach
                            </select>
                            <input type="text" name="found_location" maxlength="120" value="{{ $line->found_location }}"
                                   placeholder="{{ __('accounts::asset.verify_found_at') }}" class="{{ $box }}">
                            <input type="text" name="note" maxlength="500" value="{{ $line->note }}"
                                   placeholder="{{ __('core.table.narration') }}" class="{{ $box }} min-w-0 flex-1">
                            <input type="file" name="photo" accept="image/*" aria-label="{{ __('accounts::asset.verify_photo') }}" class="text-2xs">
                            <x-ui.button type="submit" tone="secondary">{{ __('core.action.save') }}</x-ui.button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
</x-layouts.app>
