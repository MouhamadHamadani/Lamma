{{-- The header every logged-in page shares (My games, Settings): logo, language switch and the account menu. --}}
<header class="flex h-[72px] shrink-0 items-center justify-between gap-3">
    <a href="{{ route('home') }}"><x-lamma.logo /></a>
    <div class="flex items-center gap-2">
        <x-lamma.language-switcher />
        <x-lamma.user-menu />
    </div>
</header>
