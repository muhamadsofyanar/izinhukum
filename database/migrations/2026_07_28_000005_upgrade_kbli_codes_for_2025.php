<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kbli_codes', function (Blueprint $table): void {
            $table->string('version', 4)->default('2025')->after('code')->index();
            $table->string('category_code', 1)->nullable()->after('version')->index();
            $table->string('category_title')->nullable()->after('category_code');
            $table->uuid('oss_id')->nullable()->after('description')->unique();
            $table->json('risk_levels')->nullable()->after('risk_level');
            $table->json('licenses')->nullable()->after('licensing');
            $table->string('source_url')->nullable()->after('licenses');
            $table->timestamp('source_updated_at')->nullable()->after('source_url');
        });

        Schema::create('kbli_scopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kbli_code_id')->constrained()->cascadeOnDelete();
            $table->uuid('external_id')->nullable()->unique();
            $table->text('name');
            $table->string('sector')->nullable();
            $table->json('regulations')->nullable();
            $table->timestamps();
        });

        Schema::create('kbli_risk_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kbli_scope_id')->constrained()->cascadeOnDelete();
            $table->string('external_code')->nullable()->index();
            $table->string('business_scale');
            $table->string('risk_level');
            $table->string('land_area')->nullable();
            $table->json('licenses')->nullable();
            $table->string('issue_period')->nullable();
            $table->json('requirements')->nullable();
            $table->json('obligations')->nullable();
            $table->json('authorities')->nullable();
            $table->timestamps();
            $table->index(['kbli_scope_id', 'business_scale']);
            $table->index('risk_level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kbli_risk_profiles');
        Schema::dropIfExists('kbli_scopes');

        Schema::table('kbli_codes', function (Blueprint $table): void {
            $table->dropUnique(['oss_id']);
            $table->dropIndex(['version']);
            $table->dropIndex(['category_code']);
            $table->dropColumn([
                'version',
                'category_code',
                'category_title',
                'oss_id',
                'risk_levels',
                'licenses',
                'source_url',
                'source_updated_at',
            ]);
        });
    }
};
