@assets
@vite('resources/js/passkeys.js')
@endassets

<div
    x-data="{
        supported: false,
        showForm: false,
        name: '',
        loading: false,
        error: null,
        updateSupport() {
            this.supported = Boolean(window.Passkeys?.isSupported());
        },
        getDefaultPasskeyName() {
            const ua = navigator.userAgent;

            const browser = [
                { pattern: /Edg|Edge/, name: 'Edge' },
                { pattern: /OPR|Opera|OPiOS/, name: 'Opera' },
                { pattern: /Firefox|FxiOS/, name: 'Firefox' },
                { pattern: /Chrome|CriOS/, name: 'Chrome' },
                { pattern: /Safari/, name: 'Safari' },
            ].find(({ pattern }) => pattern.test(ua))?.name;

            const os = [
                { pattern: /iPhone/, name: 'iPhone' },
                { pattern: /iPad|Macintosh(?=.*Mobile)/, name: 'iPad' },
                { pattern: /Android/, name: 'Android' },
                { pattern: /Mac/, name: 'Mac' },
                { pattern: /Windows/, name: 'Windows' },
            ].find(({ pattern }) => pattern.test(ua))?.name;

            return [browser, os].filter(Boolean).join(' on ') || '';
        },
        init() {
            this.name = this.getDefaultPasskeyName();
            this.updateSupport();

            window.addEventListener('passkeys:ready', () => this.updateSupport(), { once: true });
        },
        async register() {
            if (!this.name.trim()) return;

            this.loading = true;
            this.error = null;

            try {
                await window.Passkeys.register({ name: this.name });
                this.name = '';
                this.showForm = false;
                await $wire.loadPasskeys();
            } catch (e) {
                if (e.constructor?.name !== 'UserCancelledError') {
                    this.error = e.message;
                }
            } finally {
                this.loading = false;
            }
        },
        cancel() {
            this.showForm = false;
            this.name = '';
            this.error = null;
        },
    }"
>
    <template x-if="!supported">
        <p class="text-ink-muted">{{ __('Passkeys are not supported in this browser.') }}</p>
    </template>

    <template x-if="supported && !showForm">
        <div>
            <x-lamma.button variant="outline" icon="plus" x-on:click="showForm = true" data-test="add-passkey">
                {{ __('Add passkey') }}
            </x-lamma.button>
        </div>
    </template>

    <template x-if="supported && showForm">
        <div class="flex flex-col gap-4 rounded-tile border-2 border-line bg-cream p-4">
            <div class="flex flex-col gap-2">
                <label for="passkey-name" class="text-[15px] font-semibold">{{ __('Passkey name') }}</label>
                <input
                    id="passkey-name" type="text" x-model="name" autocomplete="off"
                    placeholder="{{ __('e.g., MacBook Pro, iPhone') }}"
                    x-on:keydown.enter.prevent="register()"
                    x-ref="passkeyNameInput"
                    x-init="$nextTick(() => $refs.passkeyNameInput?.focus())"
                    class="h-14 w-full rounded-input border-2 border-line bg-white px-[18px] text-[17px] placeholder:text-ink-subtle"
                >
                <p class="text-sm text-ink-subtle">{{ __('Give this passkey a name to help you identify it later.') }}</p>
            </div>

            <p x-show="error" x-text="error" x-cloak role="alert" class="text-sm font-semibold text-coral-700"></p>

            <div class="flex flex-wrap gap-3">
                <x-lamma.button x-on:click="register()" x-bind:disabled="loading || !name.trim()" class="disabled:cursor-not-allowed disabled:opacity-50">
                    <span x-show="!loading">{{ __('Register passkey') }}</span>
                    <span x-show="loading" x-cloak>{{ __('Registering...') }}</span>
                </x-lamma.button>
                <x-lamma.button variant="ghost" x-on:click="cancel()">{{ __('Cancel') }}</x-lamma.button>
            </div>
        </div>
    </template>
</div>
