{{-- Account menu for logged-in pages: initial + name, and a panel with My games, Host a game, Settings and Log out.
     A disclosure (button + aria-expanded), not an ARIA menu: Tab walks the links, Escape closes it and returns focus to the button.
     The name is hidden on phones to save room (the button keeps it in its accessible name). `user` defaults to the logged-in user. --}}
@props(['user' => null])
@php($user ??= auth('web')->user())
@if ($user)
    <div
        x-data="{ open: false }" x-id="['user-menu']"
        x-on:keydown.escape.window="if (open) { open = false; $refs.trigger.focus() }"
        x-on:click.outside="open = false"
        x-on:focusin.window="if (open && ! $el.contains($event.target)) open = false"
        {{ $attributes->class('relative') }}
    >
        <button
            type="button" x-ref="trigger" x-on:click="open = ! open"
            x-bind:aria-expanded="open" x-bind:aria-controls="$id('user-menu')"
            aria-expanded="false"
            aria-label="{{ __('Account menu') }}: {{ $user->name }}"
            data-test="user-menu-button"
            class="flex h-11 items-center gap-2 rounded-chip border-2 border-line bg-white ps-1 pe-3 font-semibold hover:bg-tint-navy"
        >
            <x-lamma.avatar :name="$user->name" :size="32" />
            <bdi class="hidden max-w-[10rem] truncate sm:inline">{{ $user->name }}</bdi>
            <x-lamma.icon name="chevron-down" :size="18" :stroke="2.2" class="transition-transform" x-bind:class="open && 'rotate-180'" />
        </button>

        <div
            x-show="open" x-cloak x-bind:id="$id('user-menu')" id="user-menu"
            class="absolute end-0 top-full z-30 mt-2 flex w-64 max-w-[calc(100vw-2.5rem)] flex-col rounded-card border-2 border-line bg-white p-2 shadow-soft"
        >
            <div class="flex min-w-0 flex-col px-3 py-2">
                <bdi class="truncate font-bold">{{ $user->name }}</bdi>
                <span dir="ltr" class="truncate text-start text-sm text-ink-subtle">{{ $user->email }}</span>
            </div>
            <span class="mx-3 my-1 h-px bg-line" aria-hidden="true"></span>

            @foreach ([
                ['me.games', 'trophy', __('My games')],
                ['rooms.create', 'play', __('Host a game')],
                ['profile.edit', 'sliders', __('Settings')],
            ] as [$route, $icon, $label])
                <a href="{{ route($route) }}" class="flex h-12 items-center gap-3 rounded-input px-3 font-semibold hover:bg-tint-navy" data-test="user-menu-{{ $route }}">
                    <x-lamma.icon :name="$icon" :size="20" />
                    {{ $label }}
                </a>
            @endforeach

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="flex h-12 w-full items-center gap-3 rounded-input px-3 text-start font-semibold hover:bg-tint-navy" data-test="logout-button">
                    <x-lamma.icon name="logout" :size="20" class="rtl:-scale-x-100" />
                    {{ __('Log out') }}
                </button>
            </form>
        </div>
    </div>
@endif
