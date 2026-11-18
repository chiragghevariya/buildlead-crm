<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('sales')->after('password')->index();
            $table->string('phone', 40)->nullable()->after('role');
            $table->json('regions')->nullable()->after('phone');        // postcode prefixes, e.g. ["10","12"]
            $table->json('project_types')->nullable()->after('regions'); // e.g. ["renovation","roofing"]
            $table->unsignedSmallInteger('max_open_leads')->default(25)->after('project_types');
            $table->boolean('is_active')->default(true)->after('max_open_leads')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropIndex(['is_active']);
            $table->dropColumn(['role', 'phone', 'regions', 'project_types', 'max_open_leads', 'is_active']);
        });
    }
};
