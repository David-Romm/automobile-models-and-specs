<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('automobiles', function (Blueprint $table) {
            $table->string('segment')->nullable()->index('segment')->after('body_type');
            $table->string('infotainment')->nullable()->index('infotainment')->after('segment');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('automobiles', function (Blueprint $table) {
            $table->dropIndex('segment');
            $table->dropIndex('infotainment');
            $table->dropColumn(['segment', 'infotainment']);
        });
    }
};
