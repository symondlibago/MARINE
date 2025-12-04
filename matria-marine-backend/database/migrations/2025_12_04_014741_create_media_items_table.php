<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::create('media_items', function (Blueprint $table) {
        $table->id();
        $table->foreignId('media_category_id')->constrained()->onDelete('cascade');
        $table->string('type'); // 'img' or 'vid'
        $table->string('src');  // S3 path
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_items');
    }
};
