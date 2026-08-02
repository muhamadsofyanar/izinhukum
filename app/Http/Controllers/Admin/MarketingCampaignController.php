<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketingCampaign;
use App\Models\Service;
use App\Models\User;
use App\Services\FeatureFlagService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MarketingCampaignController extends Controller
{
    public function index(FeatureFlagService $features): View
    {
        return view('admin.marketing-campaigns.index', [
            'campaigns' => MarketingCampaign::query()->withCount('inquiries')->latest()->paginate(25),
            'statuses' => MarketingCampaign::STATUSES,
            'services' => Service::query()->where('is_active', true)->orderBy('name')->get(['name', 'slug']),
            'growthEnabled' => $features->enabled('growth_analytics'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->attributes->get('currentUser');
        abort_unless($actor instanceof User, 403);
        $data = $this->validateData($request);
        $data['slug'] = Str::slug($data['slug'] ?: $data['name']);
        $data['budget'] = (int) ($data['budget'] ?? 0);
        $data['spend'] = (int) ($data['spend'] ?? 0);
        if ($data['slug'] === '') {
            return back()->withErrors(['slug' => 'Nama campaign harus menghasilkan kode URL yang valid.'])->withInput();
        }
        if (MarketingCampaign::query()->where('slug', $data['slug'])->exists()) {
            return back()->withErrors(['slug' => 'Kode campaign sudah digunakan.'])->withInput();
        }
        MarketingCampaign::query()->create([...$data, 'created_by' => $actor->id]);

        return back()->with('success', 'Campaign berhasil dibuat dan siap menghasilkan tautan UTM.');
    }

    public function update(Request $request, MarketingCampaign $campaign): RedirectResponse
    {
        $data = $this->validateData($request, $campaign);
        $data['slug'] = Str::slug($data['slug'] ?: $data['name']);
        $data['budget'] = (int) ($data['budget'] ?? 0);
        $data['spend'] = (int) ($data['spend'] ?? 0);
        if (MarketingCampaign::query()->where('slug', $data['slug'])->where('id', '!=', $campaign->id)->exists()) {
            return back()->withErrors(['slug' => 'Kode campaign sudah digunakan.'])->withInput();
        }
        $campaign->update($data);

        return back()->with('success', 'Biaya dan status campaign berhasil diperbarui.');
    }

    private function validateData(Request $request, ?MarketingCampaign $campaign = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:180', Rule::unique('marketing_campaigns', 'slug')->ignore($campaign?->id)],
            'source' => ['required', 'string', 'max:120'],
            'medium' => ['required', 'string', 'max:120'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'budget' => ['nullable', 'integer', 'min:0'],
            'spend' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', Rule::in(array_keys(MarketingCampaign::STATUSES))],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);
    }
}
