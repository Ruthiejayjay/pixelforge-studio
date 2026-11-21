<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key')->unique();
            $table->string('provider_generation_id')->nullable()->unique();
            $table->string('status', 32)->index();
            $table->text('prompt');
            $table->string('size', 32);
            $table->string('quality', 32);
            $table->json('reference_images');
            $table->json('provider_rates')->nullable();
            $table->json('provider_usage')->nullable();
            $table->bigInteger('estimated_min_user_cost_nanos');
            $table->bigInteger('estimated_expected_user_cost_nanos');
            $table->bigInteger('estimated_max_user_cost_nanos');
            $table->bigInteger('reserved_user_cost_nanos')->default(0);
            $table->bigInteger('actual_provider_cost_nanos')->nullable();
            $table->bigInteger('actual_user_cost_nanos')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('provider_started_at')->nullable();
            $table->timestamp('provider_completed_at')->nullable();
            $table->date('completed_utc_date')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'completed_utc_date']);
            $table->index(['status', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
