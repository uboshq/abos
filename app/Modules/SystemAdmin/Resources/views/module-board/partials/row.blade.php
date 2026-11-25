{{-- এক মডিউলের সারি — চালু/বন্ধ, মিশ্র, তালা, কোম্পানির দেয়াল; মিশ্র ঘর না ছুঁলে কিছুই বদলায় না ([[ModuleBoard::wanted()]]) --}}
@php($mixed = $was === 'mixed' && ! $copied)
<div class="flex items-center gap-3 border-b border-(--color-border) py-2 last:border-b-0" data-module-row="{{ $row['code'] }}" data-state="{{ $row['state'] }}">
    <span class="min-w-0 flex-1 text-sm">
        {{ $row['label'] }}
        @if ($row['wall'])
            <small class="block text-2xs text-(--color-badge-pending-ink)">{{ __('system_admin::branch_module.company_off') }}</small>
        @elseif ($row['locked'])
            <small class="block text-2xs text-(--color-ink-muted)">{{ __('system_admin::module_board.always_on') }}</small>
        @elseif ($mixed)
            <small class="block text-2xs text-(--color-ink-muted)">{{ __('system_admin::module_board.mixed', ['on' => $row['count'], 'total' => $row['total']]) }}</small>
            <label class="flex items-center gap-1 text-2xs"><input type="checkbox" name="all_on[{{ $row['code'] }}]" value="1" data-key="all_on.{{ $row['code'] }}" data-was="" class="size-4"> {{ __('system_admin::module_board.on_in_all') }}</label>
        @elseif ($row['state'] === 'off' && $row['needs'] !== [])
            <small class="block text-2xs text-(--color-badge-pending-ink)">{{ __('system_admin::module_board.blocks', ['names' => implode(', ', array_slice($row['needs'], 0, 3)).(count($row['needs']) > 3 ? ' …' : '')]) }}</small>
        @else
            <small class="block text-2xs text-(--color-ink-muted)">{{ __('system_admin::module_board.screens_count', ['n' => $row['screens']]) }}</small>
        @endif
    </span>
    @include('system_admin::partials.switch', ['name' => 'modules['.$row['code'].']', 'label' => $row['label'], 'checked' => $row['on'] && ! $row['wall'], 'disabled' => $row['locked'] || $row['wall'], 'dataKey' => $row['code']])
    @unless ($row['locked'] || $row['wall'])
        <input type="hidden" name="scope[]" value="{{ $row['code'] }}">
        <input type="hidden" name="was[{{ $row['code'] }}]" value="{{ $mixed ? 'mixed' : $was }}">
    @endunless
</div>
