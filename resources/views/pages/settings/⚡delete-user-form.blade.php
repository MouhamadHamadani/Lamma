<?php

use Livewire\Component;

new class extends Component {}; ?>

<div>
    <x-lamma.card :sticker="false" :title="__('Delete account')" :description="__('Deleting your account removes your profile and your saved games. Other players keep their own results.')">
        <div>
            <x-lamma.button variant="danger" icon="trash" x-data x-on:click="$dispatch('open-dialog', { name: 'delete-account' })" data-test="delete-user-button">
                {{ __('Delete account') }}
            </x-lamma.button>
        </div>
    </x-lamma.card>

    <livewire:pages::settings.delete-user-modal />
</div>
