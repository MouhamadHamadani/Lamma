<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/** /.well-known/passkey-endpoints: where a password manager sends the user to add or manage passkeys. */
class PasskeyEndpointsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'enroll' => route('security.edit'),
            'manage' => route('security.edit'),
        ]);
    }
}
