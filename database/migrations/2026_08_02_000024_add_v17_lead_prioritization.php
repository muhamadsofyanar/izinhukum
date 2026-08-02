<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->unsignedTinyInteger('lead_score')->default(10)->after('probability')->index();
            $table->string('temperature', 16)->default('cold')->after('lead_score')->index();
            $table->timestamp('last_stage_changed_at')->nullable()->after('next_follow_up_at')->index();
            $table->timestamp('first_contacted_at')->nullable()->after('last_stage_changed_at');
            $table->unsignedInteger('response_minutes')->nullable()->after('first_contacted_at');
            $table->string('loss_reason_code', 64)->nullable()->after('lost_reason')->index();
            $table->timestamp('reactivate_at')->nullable()->after('loss_reason_code')->index();
            $table->timestamp('last_quote_at')->nullable()->after('reactivate_at');
            $table->timestamp('won_at')->nullable()->after('last_quote_at');
        });

        DB::table('crm_leads')->orderBy('id')->chunkById(100, function ($leads): void {
            foreach ($leads as $lead) {
                $contact = DB::table('crm_contacts')->where('id', $lead->contact_id)->first();
                $inquiry = $lead->inquiry_id ? DB::table('inquiries')->where('id', $lead->inquiry_id)->first() : null;
                $score = 15;
                $score += filled($contact?->email) ? 10 : 0;
                $score += filled($contact?->company) ? 10 : 0;
                $score += filled($lead->service_interest) ? 20 : 0;
                $score += (float) $lead->estimated_value > 0 ? 15 : 0;
                $score += filled($inquiry?->message) && mb_strlen((string) $inquiry->message) >= 40 ? 10 : 0;
                $score += filled($inquiry?->referred_by_partner_id) ? 10 : 0;
                $score += filled($inquiry?->coupon_code) ? 5 : 0;
                $score += filled($inquiry?->utm_campaign) ? 5 : 0;
                $score = min(100, $score);
                $firstContactedAt = DB::table('crm_activities')
                    ->where('lead_id', $lead->id)
                    ->whereIn('type', ['contacted', 'call', 'meeting'])
                    ->whereNotNull('completed_at')
                    ->orderBy('completed_at')
                    ->value('completed_at');
                $lastQuoteAt = DB::table('sales_quotes')
                    ->where(function ($query) use ($lead): void {
                        $query->where('crm_lead_id', $lead->id);
                        if ($lead->inquiry_id) {
                            $query->orWhere('inquiry_id', $lead->inquiry_id);
                        }
                    })
                    ->whereIn('status', ['sent', 'approved'])
                    ->max('sent_at');
                $responseMinutes = null;
                if ($firstContactedAt && $lead->created_at) {
                    $responseMinutes = (int) round(max(
                        0,
                        \Illuminate\Support\Carbon::parse($lead->created_at)
                            ->diffInMinutes(\Illuminate\Support\Carbon::parse($firstContactedAt)),
                    ));
                }

                DB::table('crm_leads')->where('id', $lead->id)->update([
                    'lead_score' => $score,
                    'temperature' => $score >= 70 ? 'hot' : ($score >= 40 ? 'warm' : 'cold'),
                    'last_stage_changed_at' => $lead->updated_at ?: now(),
                    'first_contacted_at' => $firstContactedAt,
                    'response_minutes' => $responseMinutes,
                    'last_quote_at' => $lastQuoteAt,
                    'won_at' => in_array($lead->stage, ['deal', 'waiting_requirements', 'processing', 'completed'], true)
                        ? ($lead->updated_at ?: now())
                        : null,
                ]);
            }
        });

        $this->setting('feature_lead_prioritization', '1');
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->dropColumn([
                'lead_score', 'temperature', 'last_stage_changed_at', 'first_contacted_at',
                'response_minutes', 'loss_reason_code', 'reactivate_at', 'last_quote_at', 'won_at',
            ]);
        });
        DB::table('system_settings')->where('key', 'feature_lead_prioritization')->delete();
    }

    private function setting(string $key, string $value): void
    {
        DB::table('system_settings')->updateOrInsert(['key' => $key], [
            'value' => $value,
            'is_encrypted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
