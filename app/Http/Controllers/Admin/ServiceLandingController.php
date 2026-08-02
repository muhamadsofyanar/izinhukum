<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\ServiceLandingContentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ServiceLandingController extends Controller
{
    public function index(ServiceLandingContentService $landing): View
    {
        $services = Service::query()
            ->with(['packages' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('sort_order')
            ->get();
        $contents = $services->mapWithKeys(fn (Service $service): array => [
            $service->id => $landing->content($service),
        ]);

        return view('admin.service-landings.index', compact('services', 'contents'));
    }

    public function update(Request $request, Service $service): RedirectResponse
    {
        $data = $request->validate([
            'landing_eyebrow' => ['nullable', 'string', 'max:120'],
            'landing_headline' => ['required', 'string', 'max:220'],
            'landing_subheadline' => ['required', 'string', 'max:3000'],
            'benefits_text' => ['nullable', 'string', 'max:10000'],
            'process_text' => ['nullable', 'string', 'max:20000'],
            'faqs_text' => ['nullable', 'string', 'max:30000'],
            'seo_title' => ['required', 'string', 'max:70'],
            'seo_description' => ['required', 'string', 'max:160'],
        ]);

        $service->update([
            'landing_eyebrow' => $data['landing_eyebrow'] ?: $service->category,
            'landing_headline' => $data['landing_headline'],
            'landing_subheadline' => $data['landing_subheadline'],
            'landing_benefits' => $this->lines($data['benefits_text'] ?? '', 8),
            'landing_process' => $this->pairs($data['process_text'] ?? '', 'process_text', 'Judul tahap | Penjelasan tahap', 'title', 'description', 8),
            'landing_faqs' => $this->pairs($data['faqs_text'] ?? '', 'faqs_text', 'Pertanyaan | Jawaban', 'question', 'answer', 12),
            'seo_title' => $data['seo_title'],
            'seo_description' => $data['seo_description'],
        ]);

        return redirect()->route('admin.service-landings.index', ['open' => $service->slug])
            ->with('success', 'Landing '.$service->short_name.' berhasil diperbarui.');
    }

    private function lines(string $value, int $limit): array
    {
        return Collection::make(preg_split('/\R/u', trim($value)) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    private function pairs(
        string $value,
        string $field,
        string $example,
        string $leftKey,
        string $rightKey,
        int $limit,
    ): array {
        $result = [];
        foreach (preg_split('/\R/u', trim($value)) ?: [] as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $pair = preg_split('/\s*\|\s*/u', trim($line), 2);
            if (count($pair) !== 2 || blank($pair[0]) || blank($pair[1])) {
                throw ValidationException::withMessages([
                    $field => 'Baris '.($index + 1).' harus memakai format: '.$example.'.',
                ]);
            }
            $result[] = [$leftKey => trim($pair[0]), $rightKey => trim($pair[1])];
            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }
}
