<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->string('provider')->nullable();
            $table->nullableMorphs('owner');
            $table->text('redirect_uris')->nullable();
            $table->text('grant_types')->nullable();
            $table->string('secret', 255)->nullable()->change();
            // Retain legacy columns and their data; new Passport writes the new columns.
            $table->text('redirect')->nullable()->change();
            $table->boolean('personal_access_client')->default(false)->change();
            $table->boolean('password_client')->default(false)->change();
        });

        DB::table('oauth_clients')->orderBy('id')->chunkById(100, function ($clients) {
            foreach ($clients as $client) {
                $grants = $client->password_client ? ['password', 'refresh_token']
                    : ($client->personal_access_client ? ['personal_access']
                        : ['authorization_code', 'refresh_token']);
                if (!$client->password_client && !$client->personal_access_client && !$client->user_id && $client->secret) {
                    $grants[] = 'client_credentials';
                }
                DB::table('oauth_clients')->where('id', $client->id)->update([
                    'owner_id' => $client->user_id,
                    'owner_type' => $client->user_id ? 'App\\User' : null,
                    'redirect_uris' => json_encode($client->redirect ? explode(',', $client->redirect) : []),
                    'grant_types' => json_encode($grants),
                    'secret' => $client->secret && !Hash::isHashed($client->secret)
                        ? Hash::make($client->secret) : $client->secret,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Hashing client secrets cannot be reversed. Use the pre-upgrade backup.
        throw new RuntimeException('Restore the pre-upgrade database backup to roll back Passport.');
    }
};
