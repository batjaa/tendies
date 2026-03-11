<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('status')->default('pending'); // pending, invited, accepted
            $table->string('invite_token', 64)->nullable()->unique();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('invite_expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};
