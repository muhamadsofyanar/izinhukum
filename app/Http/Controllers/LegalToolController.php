<?php

namespace App\Http\Controllers;

use App\Services\DeedSimulationService;
use App\Services\LegalNameGenerator;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LegalToolController extends Controller
{
    public function index(): View
    {
        return view('tools.index');
    }

    public function nameGenerator(Request $request, LegalNameGenerator $generator): View
    {
        $entityType = (string) $request->query('jenis', 'pt');
        $sector = (string) $request->query('sektor', 'umum');
        $keyword = trim((string) $request->query('kata'));
        $suggestions = [];

        if ($keyword !== '') {
            $validated = $request->validate([
                'jenis' => ['required', 'in:'.implode(',', array_keys(LegalNameGenerator::ENTITY_TYPES))],
                'sektor' => ['required', 'in:'.implode(',', array_keys(LegalNameGenerator::SECTORS))],
                'kata' => ['required', 'string', 'max:40', 'regex:/^[\pL\s]+$/u'],
            ], [
                'kata.regex' => 'Kata utama hanya boleh berisi huruf dan spasi.',
            ]);
            $entityType = $validated['jenis'];
            $sector = $validated['sektor'];
            $keyword = $validated['kata'];
            $suggestions = $generator->generate($entityType, $keyword, $sector);
        } else {
            if (! array_key_exists($entityType, LegalNameGenerator::ENTITY_TYPES)) {
                $entityType = 'pt';
            }

            if (! array_key_exists($sector, LegalNameGenerator::SECTORS)) {
                $sector = 'umum';
            }
        }

        return view('tools.name-generator', [
            'entityTypes' => LegalNameGenerator::ENTITY_TYPES,
            'sectors' => LegalNameGenerator::SECTORS,
            'entityType' => $entityType,
            'sector' => $sector,
            'keyword' => $keyword,
            'suggestions' => $suggestions,
            'rules' => $generator->rulesFor($entityType),
        ]);
    }

    public function deedSimulator(): View
    {
        return view('tools.deed-simulator', [
            'entityTypes' => DeedSimulationService::ENTITY_TYPES,
            'preview' => null,
            'formData' => [],
        ]);
    }

    public function simulateDeed(Request $request, DeedSimulationService $simulator): View
    {
        $validated = $request->validate([
            'entity_type' => ['required', 'in:'.implode(',', array_keys(DeedSimulationService::ENTITY_TYPES))],
            'proposed_name' => ['required', 'string', 'max:180'],
            'domicile' => ['required', 'string', 'max:180'],
            'activity' => ['required', 'string', 'max:1500'],
            'kbli_codes' => ['nullable', 'string', 'max:180', 'regex:/^[0-9,\s-]+$/'],
            'founder_names' => ['required', 'string', 'max:600'],
            'capital' => ['required', 'integer', 'min:0', 'max:999999999999999'],
            'primary_officer' => ['required', 'string', 'max:300'],
            'secondary_officer' => ['nullable', 'required_unless:entity_type,pt_perorangan', 'string', 'max:300'],
            'third_officer' => ['nullable', 'required_if:entity_type,yayasan', 'string', 'max:300'],
            'simulation_consent' => ['accepted'],
        ], [
            'kbli_codes.regex' => 'Kandidat KBLI hanya boleh berisi angka, koma, spasi, atau tanda hubung.',
            'secondary_officer.required_unless' => 'Pejabat/sekutu kedua wajib diisi untuk bentuk ini.',
            'third_officer.required_if' => 'Nama Pengawas wajib diisi untuk Yayasan.',
            'simulation_consent.accepted' => 'Konfirmasi sifat edukatif simulasi wajib disetujui.',
        ]);

        unset($validated['simulation_consent']);

        return view('tools.deed-simulator', [
            'entityTypes' => DeedSimulationService::ENTITY_TYPES,
            'preview' => $simulator->build($validated),
            'formData' => $validated,
        ]);
    }
}
