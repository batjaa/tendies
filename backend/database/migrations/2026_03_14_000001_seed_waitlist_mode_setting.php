<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('nova_settings')->insert([
            'key' => 'waitlist_mode',
            'value' => true,
        ]);
    }

    public function down(): void
    {
        DB::table('nova_settings')->where('key', 'waitlist_mode')->delete();
    }
};
