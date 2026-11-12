<?php

use App\Support\Database\SqlCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('product_name', 160);
            $table->text('product_description');
            $table->string('language', 16)->default('en');
            $table->string('tone', 64)->nullable();
            $table->text('brand_notes')->nullable();
            $table->string('target_audience', 255)->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['id', 'user_id']);
            $table->index(['user_id', 'status', 'created_at']);
        });

        SqlCheck::add('projects', 'chk_projects_status', "status IN ('draft','ready','archived')");
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
