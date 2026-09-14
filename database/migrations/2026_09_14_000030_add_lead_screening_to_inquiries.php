<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table): void {
            $table->string('phone_normalized', 20)->nullable()->after('phone')->index();
            $table->string('disposition', 32)->default('new')->after('status')->index();
            $table->unsignedSmallInteger('spam_score')->default(0)->after('disposition');
            $table->json('spam_reasons')->nullable()->after('spam_score');
            $table->text('review_note')->nullable()->after('spam_reasons');
            $table->foreignId('reviewed_by')->nullable()->after('review_note')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->softDeletes();
        });

        DB::table('inquiries')->orderBy('id')->chunkById(100, function ($inquiries): void {
            foreach ($inquiries as $inquiry) {
                $disposition = match ($inquiry->status) {
                    'dihubungi' => 'contacted',
                    'proses', 'selesai' => 'won',
                    'batal' => 'lost',
                    default => 'new',
                };

                DB::table('inquiries')->where('id', $inquiry->id)->update(['disposition' => $disposition]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropIndex(['phone_normalized']);
            $table->dropIndex(['disposition']);
            $table->dropColumn([
                'phone_normalized',
                'disposition',
                'spam_score',
                'spam_reasons',
                'review_note',
                'reviewed_at',
                'deleted_at',
            ]);
        });
    }
};
