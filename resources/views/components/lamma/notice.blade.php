{{-- A one-time message carried over a redirect (session "notice"): "The host closed this room", "You are not in this room". --}}
@if ($message = session('notice'))
    <div role="status" data-test="notice" {{ $attributes->class('flex items-center gap-2.5 rounded-input border-2 border-sun bg-tint-sun px-4 py-3 text-[15px] font-semibold') }}>
        <x-lamma.icon name="alert" :size="20" />
        {{ $message }}
    </div>
@endif
