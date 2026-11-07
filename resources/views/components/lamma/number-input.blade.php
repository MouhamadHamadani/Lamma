{{-- The phone's number box for a closest-number question: one big left-to-right field (a decimal keypad on a phone) and one clear lock-in button. Alpine owns
     what is typed, inside wire:ignore, so a Livewire refresh (the poll, a broadcast) never wipes a number half way through; the button hands the text, exactly as
     typed, to the Livewire action `action`, and the server reads it (Arabic digits and separators included: App\Game\GuessNumber). One go: there is no confirm step,
     like a tap on a tile. The button is off until there is a digit in the field. --}}
@props(['action' => 'guess', 'label', 'button'])
<form
    wire:ignore x-data="{ v: '', busy: false, get ready() { return /[0-9٠-٩۰-۹]/.test(this.v) && ! this.busy } }"
    x-on:submit.prevent="if (ready) { busy = true; $store.sound.play('lock-in'); $store.sound.buzz('tap'); $wire.{{ $action }}(v).finally(() => busy = false) }"
    class="flex flex-col gap-3" data-test="number-form"
>
    <label for="guess-input" class="text-[15px] font-bold text-ink-muted">{{ $label }}</label>
    <input
        id="guess-input" name="guess" type="text" inputmode="decimal" dir="ltr" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" enterkeyhint="send"
        maxlength="40" x-model="v" placeholder="0" data-test="number-input"
        class="h-20 w-full rounded-[20px] border-3 border-navy bg-white px-5 text-center font-display text-[40px] font-extrabold text-navy placeholder:text-line-strong [@media(max-height:700px)]:h-[68px]"
    >
    <button
        type="submit" x-bind:disabled="! ready" data-test="number-lock"
        class="sticker-press inline-flex min-h-[72px] items-center justify-center gap-2.5 rounded-btn border-3 border-navy bg-coral px-7 text-[19px] font-bold text-navy shadow-sticker disabled:cursor-not-allowed disabled:border-dashed disabled:border-line-strong disabled:bg-cream disabled:text-ink-subtle disabled:shadow-none"
    >
        <x-lamma.icon name="lock" :size="22" :stroke="2.4" />
        {{ $button }}
    </button>
</form>
