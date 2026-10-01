@props([
    'title',
    'description',
])

<div class="flex w-full flex-col gap-1.5">
    <h2 class="font-display text-4xl font-extrabold leading-[1.1] lg:text-[44px]">{{ $title }}</h2>
    <p class="text-[17px] text-ink-muted">{{ $description }}</p>
</div>
