<?php

// Author: ramanpal singh | URL: https://kwebby.com
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_records', function (Blueprint $table) {
            $table->string('collection', 64);
            $table->string('id', 128);
            $table->unsignedBigInteger('version');
            $table->json('payload');
            $table->string('created_at', 32);
            $table->string('updated_at', 32);
            $table->primary(['collection', 'id']);
            $table->index(['collection', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_records');
    }
};
