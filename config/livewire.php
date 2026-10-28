<?php

// Only what differs from Livewire's own defaults (vendor/livewire/livewire/config/livewire.php). Config files are merged one level deep, so the
// whole temporary_file_upload block is repeated.
return [

    // The only file uploads in Lamma are players' selfies (App\Game\PlayerPhoto), which the phone's camera makes as a JPEG of at most 640x640 (a
    // few hundred KB). The temporary upload endpoint refuses anything else, by content (mimetypes), before the file is even stored; the service
    // checks and re-encodes again. The temporary files live on the private `local` disk and the component deletes them as soon as they are used.
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        'rules' => ['required', 'file', 'max:3072', 'mimetypes:image/jpeg,image/webp'],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => ['jpg', 'jpeg', 'webp'],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],

];
