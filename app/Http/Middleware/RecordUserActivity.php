<?php

namespace App\Http\Middleware;

use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RecordUserActivity
{
    public function handle(Request $request, \Closure $next): Response
    {
        $response = $next($request);
        $user = $request->user('api');

        if (!$response->isSuccessful() || !$user instanceof User || !$user->has_activated || $user->has_banned) {
            return $response;
        }

        $now = now();
        $cutoff = $now->copy()->subMinutes(5);
        if ($user->last_active_at && $user->last_active_at->greaterThan($cutoff)) {
            return $response;
        }

        // Keep profile timestamps and model events unchanged. The conditional update
        // also prevents concurrent requests from writing more than once per interval.
        DB::table('users')->where('id', $user->id)
            ->whereNull('deleted_at')->whereNull('banned_at')->whereNotNull('activated_at')
            ->where(function ($query) use ($cutoff) {
                $query->whereNull('last_active_at')->orWhere('last_active_at', '<=', $cutoff);
            })
            ->update(['last_active_at' => $now]);

        return $response;
    }
}
