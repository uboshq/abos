{{--
    ⭐ "এতে যে রোল আছে" — যৌথ রোলের ভেতরের রোল বাছাই (মালিকের অনুমোদিত নমুনা, ২০ নভেম্বর ২০২৬, দফা ৯)।

    ⓘ রোল যোগ বা সরালে সব সদস্যের অনুমতি একসাথে বদলায় ([[CompositeRoles::setIncludes()]])।
    ⛔ মালিকের রোল আর নিজে বাছা যায় না; যে রোল এই রোলটাকেই ভেতরে রাখে সেটা বাছলে চক্র — ঘরটা বন্ধ, পাশে কারণ।
    $choices (রোল) · $picked (id) · $self (Role|null)
--}}
@php
    $composites = app(\App\Core\Services\CompositeRoles::class);
    $above = $self ? array_flip($composites->containersOf((int) $self->id)) : [];
@endphp
<p class="role-note">{{ __('system_admin::permission.composite_note') }}</p>
<ul class="role-include-list" data-role-includes>
    @foreach ($choices as $choice)
        @continue($choice->name === \App\Core\Services\PermissionSyncer::SUPER_ADMIN_ROLE)
        @continue($self && (int) $choice->id === (int) $self->id)
        @php
            $blocked = isset($above[(int) $choice->id]);
        @endphp
        <li>
            <label @class(['role-include', 'is-blocked' => $blocked])>
                <input type="checkbox" name="includes[]" value="{{ $choice->id }}" class="role-ck"
                       @checked(in_array((int) $choice->id, $picked, true)) @disabled($blocked)>
                <span class="role-item-name">{{ \App\Core\Support\RoleLabel::for($choice->name) }}</span>
                <small class="num">{{ $choice->users_count }} · {{ $choice->permissions_count }}</small>
                @if ($blocked)
                    <small class="role-include-why">{{ __('system_admin::permission.composite_cycle_short') }}</small>
                @endif
            </label>
        </li>
    @endforeach
</ul>
