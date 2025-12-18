<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('mms_updates', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description');
            $table->date('date_posted'); // Specific date field
            $table->string('image_path'); // S3 Path
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('mms_updates');
    }
};