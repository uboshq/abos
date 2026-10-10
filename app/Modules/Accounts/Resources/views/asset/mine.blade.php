{{--
    ⭐ আমার সম্পদ — দায়িত্বে থাকা কর্মী নিজের জিনিসগুলো দেখেন আর "বুঝে নিয়েছি" বলেন (স্থায়ী সম্পদ ধাপ ৪)।
    ⓘ দাম-অবচয় এখানে নেই: কর্মীর জানার কথা জিনিসটা কোথায় আর কী অবস্থায়, খাতার অঙ্ক নয়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::asset.mine_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::asset.mine_title')" :subtitle="__('accounts::asset.mine_hint')" />
    </x-slot:header>

    @if (session('status'))
        <div role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
            {{ session('status') }}
        </div>
    @endif
    <x-ui.errors />

    @if ($assets->isEmpty())
        <x-ui.empty-state :message="__('accounts::asset.mine_empty')" />
    @else
        <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <ul class="divide-y divide-(--color-border) text-sm">
                @foreach ($assets as $asset)
                    @php($ack = $asset->acknowledgedByCustodian())
                    <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ $asset->name }}</p>
                            <p class="text-2xs text-(--color-ink-muted)">
                                {{ $asset->tag_no ?: $asset->document_no }} · {{ $asset->branch?->name() }}
                                @if ($asset->location) · {{ $asset->location }} @endif
                                @if ($asset->serial_no) · {{ __('accounts::asset.serial_no') }} {{ $asset->serial_no }} @endif
                            </p>
                        </div>

                        @if ($ack !== null)
                            <x-ui.badge tone="success">
                                {{ __('accounts::asset.ack_done', ['date' => $ack->acknowledged_at?->format('d M Y')]) }}
                                · {{ __('accounts::asset.ack_'.$ack->condition) }}
                            </x-ui.badge>
                        @else
                            <form method="POST" action="{{ route('accounts.asset.acknowledge', $asset->id) }}" class="flex flex-wrap items-end gap-2">
                                @csrf
                                <select name="condition" aria-label="{{ __('accounts::asset.ack_condition') }}"
                                        class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm">
                                    @foreach (\App\Modules\Accounts\Models\AssetAcknowledgement::CONDITIONS as $condition)
                                        <option value="{{ $condition }}">{{ __('accounts::asset.ack_'.$condition) }}</option>
                                    @endforeach
                                </select>
                                <input type="text" name="note" maxlength="500" placeholder="{{ __('core.table.narration') }}"
                                       class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm">
                                <x-ui.button type="submit" tone="primary">{{ __('accounts::asset.ack_action') }}</x-ui.button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-layouts.app>
