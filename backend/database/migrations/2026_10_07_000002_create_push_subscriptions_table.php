<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\WebPush\PushSubscription;

/**
 * Abonnements Web Push (un par navigateur/appareil), issus du package
 * laravel-notification-channels/webpush. Adapté : `users.id` est un UUID
 * (uuidMorphs, pas morphs) et on garde le user agent pour libeller
 * l'appareil dans le profil (« iPhone · Safari »).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('webpush.table_name'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuidMorphs('subscribable', 'push_subscriptions_subscribable_morph_idx');
            $table->string('endpoint', PushSubscription::ENDPOINT_MAX_LENGTH)->unique();
            $table->string('public_key')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('content_encoding')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('webpush.table_name'));
    }
};
