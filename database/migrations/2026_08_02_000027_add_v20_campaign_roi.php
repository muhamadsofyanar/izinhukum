<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('slug', 180)->unique();
            $table->string('source', 120)->default('whatsapp')->index();
            $table->string('medium', 120)->default('broadcast')->index();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedBigInteger('budget')->default(0);
            $table->unsignedBigInteger('spend')->default(0);
            $table->string('status', 24)->default('active')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('inquiries', function (Blueprint $table): void {
            $table->foreignId('marketing_campaign_id')->nullable()->after('utm_campaign')->constrained()->nullOnDelete();
        });

        DB::table('inquiries')->whereNotNull('utm_campaign')->orderBy('id')->chunkById(100, function ($inquiries): void {
            foreach ($inquiries as $inquiry) {
                $slug = Str::slug((string) $inquiry->utm_campaign);
                if ($slug === '') {
                    continue;
                }
                $campaignId = DB::table('marketing_campaigns')->where('slug', $slug)->value('id');
                if (! $campaignId) {
                    $campaignId = DB::table('marketing_campaigns')->insertGetId([
                        'name' => $inquiry->utm_campaign,
                        'slug' => $slug,
                        'source' => $inquiry->utm_source ?: 'unknown',
                        'medium' => $inquiry->utm_medium ?: 'unknown',
                        'status' => 'archived',
                        'budget' => 0,
                        'spend' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                DB::table('inquiries')->where('id', $inquiry->id)->update(['marketing_campaign_id' => $campaignId]);
            }
        });
        $this->setting('feature_campaign_roi', '1');
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('marketing_campaign_id');
        });
        Schema::dropIfExists('marketing_campaigns');
        DB::table('system_settings')->where('key', 'feature_campaign_roi')->delete();
    }

    private function setting(string $key, string $value): void
    {
        DB::table('system_settings')->updateOrInsert(['key' => $key], [
            'value' => $value, 'is_encrypted' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
};
