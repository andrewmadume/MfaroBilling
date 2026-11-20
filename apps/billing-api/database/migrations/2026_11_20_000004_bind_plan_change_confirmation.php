<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_changes', function (Blueprint $t) {
            $t->string('quote_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain commercial confirmation history.');
    }
};
