<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // El administrador de la plataforma no pertenece a ninguna empresa (RF-011).
            $table->boolean('is_platform_admin')->default(false)->after('password');
            $table->boolean('active')->default(true)->after('is_platform_admin');
            $table->timestamp('last_login_at')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_platform_admin', 'active', 'last_login_at']);
        });
    }
};
