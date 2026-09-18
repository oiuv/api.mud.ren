<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

class TokenController extends Controller
{
    public function destroy(Request $request, string $token)
    {
        $accessToken = Passport::token()->newQuery()
            ->where('user_id', $request->user()->getAuthIdentifier())->findOrFail($token);

        DB::transaction(function () use ($accessToken) {
            $accessToken->revoke();
            Passport::refreshToken()->newQuery()->where('access_token_id', $accessToken->id)
                ->update(['revoked' => true]);
        });

        return response()->noContent();
    }
}
