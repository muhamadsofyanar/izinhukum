#!/usr/bin/env node

import fs from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

const OSS_BASE_URL = 'https://oss.go.id/id';
const DEFAULT_BPS_TEXT = '/tmp/kbli-2025.txt';
const DEFAULT_OUTPUT = path.resolve('database/data/kbli-2025.json');
const DEFAULT_CACHE = '/tmp/izinhukum-kbli-cache';
const CONCURRENCY = Number(process.env.KBLI_SYNC_CONCURRENCY || 10);

const bpsTextPath = path.resolve(process.argv[2] || DEFAULT_BPS_TEXT);
const outputPath = path.resolve(process.argv[3] || DEFAULT_OUTPUT);
const cachePath = path.resolve(process.env.KBLI_SYNC_CACHE || DEFAULT_CACHE);

const sleep = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

function compactWhitespace(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
}

function decodeHtmlEntities(value) {
    return String(value || '')
        .replace(/&amp;/g, '&')
        .replace(/&quot;/g, '"')
        .replace(/&#039;|&apos;/g, "'")
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&nbsp;/g, ' ');
}

function plainText(value) {
    return compactWhitespace(
        decodeHtmlEntities(
            String(value || '')
                .replace(/<\/li>/gi, '; ')
                .replace(/<br\s*\/?>/gi, ' ')
                .replace(/<[^>]+>/g, ' '),
        ),
    ).replace(/;\s*$/, '');
}

function titleFromHtml(html) {
    const match = html.match(/<h1[^>]*>([\s\S]*?)<\/h1>/i);

    return match ? compactWhitespace(decodeHtmlEntities(match[1].replace(/<[^>]+>/g, ''))) : null;
}

function isBookNoise(line) {
    const value = line.replace(/\f/g, '').trim();

    return /^(?:ht|tp|s:|\/\/w|w|\.b|ps|\.go|go|\.id)$/.test(value)
        || /^[htpsw/g.o:id\s]+$/.test(value)
        || /^\d+\s+Klasifikasi Baku Lapangan Usaha Indonesia \(KBLI\) 2025$/.test(value)
        || /^[A-V]\s+[A-Z][A-Z\s,;()/-]+\s+\d+$/.test(value)
        || /^Klasifikasi Baku Lapangan Usaha Indonesia \(KBLI\) 2025\s+\d+$/.test(value);
}

function cleanDescription(lines) {
    const cleaned = [];
    let previousWasBlank = false;

    for (const rawLine of lines) {
        const line = rawLine.replace(/\f/g, '').trim();

        if (line === '') {
            if (!previousWasBlank && cleaned.length > 0) {
                cleaned.push('');
                previousWasBlank = true;
            }
            continue;
        }

        if (isBookNoise(rawLine)) {
            continue;
        }

        cleaned.push(line);
        previousWasBlank = line === '';
    }

    return cleaned
        .join('\n')
        .replace(/\n{3,}/g, '\n\n')
        .replace(/ +/g, ' ')
        .trim();
}

async function parseBpsBook(filePath) {
    const text = await fs.readFile(filePath, 'utf8');
    const lines = text.split(/\r?\n/);
    const firstIndex = lines.findIndex((line) => /^01111\s+PERTANIAN JAGUNG\s*$/.test(line));
    const lastIndex = lines.findIndex((line, index) => index > firstIndex && /^99000\s+AKTIVITAS\s+BADAN/.test(line));

    if (firstIndex < 0 || lastIndex < 0) {
        throw new Error('Bagian daftar utama KBLI 2025 tidak ditemukan pada teks BPS.');
    }

    const entries = [];
    let current = null;

    const finishCurrent = () => {
        if (!current) {
            return;
        }

        const firstDescriptionIndex = current.lines.findIndex((line) => (
            /^(?:Kelompok|Subgolongan|Golongan|Golongan Pokok|Kategori)\s+ini\b/.test(
                line.replace(/\f/g, '').trim(),
            )
        ));
        const index = firstDescriptionIndex >= 0 ? firstDescriptionIndex : current.lines.length;
        const titleLines = current.lines
            .slice(0, index)
            .map((line) => line.replace(/\f/g, '').trim())
            .filter((line) => line !== '' && !isBookNoise(line));

        entries.push({
            code: current.code,
            title: compactWhitespace([current.firstTitle, ...titleLines].join(' ')),
            description: cleanDescription(current.lines.slice(index)),
        });
    };

    for (let index = firstIndex; index < lines.length; index += 1) {
        const line = lines[index].replace(/\f/g, '');
        const heading = line.match(/^(\d{5})\s+(.+)$/);

        if (heading) {
            finishCurrent();
            current = {
                code: heading[1],
                firstTitle: heading[2].trim(),
                lines: [],
            };

            if (heading[1] === '99000') {
                const trailing = lines.slice(index + 1, index + 80);
                current.lines.push(...trailing);
                finishCurrent();
                current = null;
                break;
            }

            continue;
        }

        if (current) {
            current.lines.push(line);
        }
    }

    const uniqueEntries = new Map(entries.map((entry) => [entry.code, entry]));

    if (uniqueEntries.size !== 1559) {
        throw new Error(`Jumlah KBLI 2025 hasil ekstraksi ${uniqueEntries.size}; seharusnya 1.559.`);
    }

    return uniqueEntries;
}

function decodeNextFlight(html) {
    const parts = [];
    const regex = /<script>self\.__next_f\.push\(([\s\S]*?)\)<\/script>/g;
    let match;

    while ((match = regex.exec(html)) !== null) {
        try {
            const payload = JSON.parse(match[1]);

            if (typeof payload?.[1] === 'string') {
                parts.push(payload[1]);
            }
        } catch {
            // Some scripts are not flight-data payloads and can be ignored.
        }
    }

    return parts.join('');
}

function extractTextReferences(flightData) {
    const references = new Map();
    const regex = /([0-9a-f]+):T([0-9a-f]+),/gi;
    let match;

    while ((match = regex.exec(flightData)) !== null) {
        const byteLength = Number.parseInt(match[2], 16);
        const start = regex.lastIndex;
        const remainingBuffer = Buffer.from(flightData.slice(start), 'utf8');
        const value = remainingBuffer.subarray(0, byteLength).toString('utf8');

        references.set(match[1].toLowerCase(), value);
        regex.lastIndex = start + value.length;
    }

    return references;
}

function extractCodeIds(flightData) {
    const entries = new Map();
    const dataRegex = /"id":"([0-9a-f-]{36})","kode":"([A-V]|\d{2,5})"/g;
    const linkRegex = /"pathname":"\/kbli\/detail\/([0-9a-f-]{36})"[\s\S]{0,1200}?"children":"([A-V]|\d{2,5})"/g;
    let match;

    while ((match = dataRegex.exec(flightData)) !== null) {
        entries.set(match[2], match[1]);
    }

    while ((match = linkRegex.exec(flightData)) !== null) {
        entries.set(match[2], match[1]);
    }

    return entries;
}

function extractBalancedJsonArray(text, startIndex) {
    let depth = 0;
    let inString = false;
    let escaped = false;

    for (let index = startIndex; index < text.length; index += 1) {
        const character = text[index];

        if (inString) {
            if (escaped) {
                escaped = false;
            } else if (character === '\\') {
                escaped = true;
            } else if (character === '"') {
                inString = false;
            }
            continue;
        }

        if (character === '"') {
            inString = true;
        } else if (character === '[') {
            depth += 1;
        } else if (character === ']') {
            depth -= 1;

            if (depth === 0) {
                return text.slice(startIndex, index + 1);
            }
        }
    }

    return null;
}

function riskScopesFromFlightData(flightData) {
    let markerIndex = flightData.indexOf('"KbliResikos":');

    while (markerIndex >= 0) {
        const dataIndex = flightData.lastIndexOf('"data":[', markerIndex);

        if (dataIndex >= 0) {
            const arrayStart = dataIndex + '"data":'.length;
            const rawArray = extractBalancedJsonArray(flightData, arrayStart);

            if (rawArray) {
                try {
                    const parsed = JSON.parse(rawArray);

                    if (Array.isArray(parsed) && parsed.some((item) => Array.isArray(item?.KbliResikos))) {
                        return parsed;
                    }
                } catch {
                    // Continue to the next marker when a React flight reference is encountered.
                }
            }
        }

        markerIndex = flightData.indexOf('"KbliResikos":', markerIndex + 1);
    }

    return [];
}

function resolveTextReference(value, references) {
    const match = typeof value === 'string' ? value.match(/^\$([0-9a-f]+)$/i) : null;

    return match ? (references.get(match[1].toLowerCase()) ?? value) : value;
}

function localized(object, key = 'uraian', references = new Map()) {
    const value = object?.localization?.id?.[key]
        ?? object?.local?.id?.[key]
        ?? object?.localization?.en?.[key]
        ?? object?.local?.en?.[key]
        ?? null;

    return resolveTextReference(value, references);
}

function uniqueStrings(values) {
    return [...new Set(values.map(compactWhitespace).filter(Boolean))];
}

function normalizeRequirements(items, references) {
    return (items || []).map((item) => ({
        text: plainText(localized(item, 'uraian', references)),
        period: item?.jangka_waktu
            ? compactWhitespace(`${item.jangka_waktu} ${localized(item.SatuanJangkaWaktu, 'uraian', references) || ''}`)
            : null,
    })).filter((item) => item.text);
}

function normalizeRiskScopes(scopes, references) {
    return (scopes || []).map((scope) => {
        const sectorLocalization = scope?.Sektor?.localization?.id
            ?? scope?.Sektor?.localization?.en
            ?? {};

        const profiles = (scope?.KbliResikos || []).map((profile) => {
            const licenses = uniqueStrings((profile?.KbliIzins || []).flatMap((item) => [
                plainText(localized(item?.Izin, 'nama_dokumen', references)),
                plainText(localized(item?.JenisPerizinan, 'uraian', references)),
            ]));

            const authorities = (profile?.KbliResikoKewenangans || []).map((item) => ({
                parameter: plainText(localized(item?.ParameterKewenangan, 'uraian', references)),
                authority: plainText(localized(item?.Kewenangan, 'uraian', references)),
            })).filter((item) => item.parameter || item.authority);

            return {
                external_code: profile?.kode || null,
                business_scale: plainText(localized(profile?.SkalaUsaha, 'uraian', references)) || 'Seluruh skala',
                risk_level: plainText(localized(profile?.Resiko, 'uraian', references)) || 'Tidak dicantumkan',
                land_area: profile?.luas_lahan && profile.luas_lahan !== '-'
                    ? compactWhitespace(`${profile.luas_lahan} ${localized(profile?.SatuanLuasTanah, 'uraian', references) || ''}`)
                    : null,
                licenses,
                issue_period: profile?.jangka_waktu
                    ? compactWhitespace(`${profile.jangka_waktu} ${localized(profile?.SatuanJangkaWaktu, 'uraian', references) || ''}`)
                    : null,
                requirements: normalizeRequirements(profile?.KbliPersyaratans, references),
                obligations: normalizeRequirements(profile?.KbliKewajibans, references),
                authorities,
            };
        });

        const regulations = uniqueStrings((scope?.ReferensiPeraturans || []).map((item) => (
            plainText(resolveTextReference(
                item?.localization?.id?.judul
                ?? item?.localization?.en?.judul
                ?? null,
                references,
            ))
        )));

        return {
            external_id: scope?.id || null,
            name: plainText(localized(scope, 'uraian', references)) || 'Seluruh',
            sector: plainText(resolveTextReference(
                sectorLocalization.uraian || sectorLocalization.deskripsi,
                references,
            )),
            regulations,
            profiles,
        };
    }).filter((scope) => scope.profiles.length > 0);
}

async function fetchWithCache(url, cacheKey) {
    await fs.mkdir(cachePath, { recursive: true });
    const filePath = path.join(cachePath, `${cacheKey}.html`);

    try {
        const cached = await fs.readFile(filePath, 'utf8');

        if (cached.length > 1000) {
            return cached;
        }
    } catch {
        // Cache miss.
    }

    let lastError;

    for (let attempt = 1; attempt <= 5; attempt += 1) {
        try {
            const response = await fetch(url, {
                headers: {
                    Accept: 'text/html,application/xhtml+xml',
                    'User-Agent': 'IzinHukum-KBLI-Updater/1.0 (+https://izinhukum.com)',
                },
                signal: AbortSignal.timeout(60_000),
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const html = await response.text();

            if (html.length < 1000) {
                throw new Error('Respons OSS tidak lengkap.');
            }

            await fs.writeFile(filePath, html, 'utf8');
            return html;
        } catch (error) {
            lastError = error;
            await sleep(attempt * 1500);
        }
    }

    throw new Error(`Gagal mengambil ${url}: ${lastError?.message || lastError}`);
}

async function mapLimit(items, limit, callback) {
    const output = new Array(items.length);
    let cursor = 0;

    async function worker() {
        while (cursor < items.length) {
            const index = cursor;
            cursor += 1;
            output[index] = await callback(items[index], index);
        }
    }

    await Promise.all(Array.from({ length: Math.min(limit, items.length) }, () => worker()));

    return output;
}

async function discoverLeafIds(validCodes) {
    const validByLength = new Map();

    for (const code of validCodes.keys()) {
        for (const length of [2, 3, 4, 5]) {
            if (!validByLength.has(length)) {
                validByLength.set(length, new Set());
            }

            validByLength.get(length).add(code.slice(0, length));
        }
    }

    const rootHtml = await fetchWithCache(`${OSS_BASE_URL}/kbli`, 'root');
    const rootIds = extractCodeIds(decodeNextFlight(rootHtml));
    let parents = [...rootIds.entries()]
        .filter(([code]) => /^[A-V]$/.test(code))
        .map(([code, id]) => ({ code, id, category: true }));

    if (parents.length !== 22) {
        throw new Error(`Kategori OSS yang ditemukan ${parents.length}; seharusnya 22.`);
    }

    for (const targetLength of [2, 3, 4, 5]) {
        const childMaps = await mapLimit(parents, CONCURRENCY, async (parent, index) => {
            const url = parent.category
                ? `${OSS_BASE_URL}/kbli/${parent.id}`
                : `${OSS_BASE_URL}/kbli/detail/${parent.id}`;
            const html = await fetchWithCache(url, `node-${parent.id}`);
            const entries = extractCodeIds(decodeNextFlight(html));
            const children = new Map();

            for (const [code, id] of entries) {
                const correctLength = code.length === targetLength;
                const correctPrefix = parent.category || code.startsWith(parent.code);
                const validCode = validByLength.get(targetLength)?.has(code);

                if (correctLength && correctPrefix && validCode) {
                    children.set(code, id);
                }
            }

            if ((index + 1) % 25 === 0 || index + 1 === parents.length) {
                process.stdout.write(`\rMenelusuri level ${targetLength} digit: ${index + 1}/${parents.length}`);
            }

            return children;
        });
        process.stdout.write('\n');

        const merged = new Map();

        for (const childMap of childMaps) {
            for (const [code, id] of childMap) {
                merged.set(code, id);
            }
        }

        console.log(`Ditemukan ${merged.size} kode level ${targetLength} digit.`);
        parents = [...merged.entries()].map(([code, id]) => ({ code, id, category: false }));
    }

    return new Map(parents.map((entry) => [entry.code, entry.id]));
}

async function buildDataset(bpsEntries, leafIds) {
    const codes = [...bpsEntries.keys()].sort();
    const missingIds = codes.filter((code) => !leafIds.has(code));

    if (missingIds.length > 0) {
        throw new Error(`ID OSS tidak ditemukan untuk ${missingIds.length} kode: ${missingIds.slice(0, 20).join(', ')}`);
    }

    return mapLimit(codes, CONCURRENCY, async (code, index) => {
        const ossId = leafIds.get(code);
        const html = await fetchWithCache(`${OSS_BASE_URL}/kbli/detail/${ossId}`, `leaf-${ossId}`);
        const flightData = decodeNextFlight(html);
        const references = extractTextReferences(flightData);
        const scopes = normalizeRiskScopes(riskScopesFromFlightData(flightData), references);
        const bpsEntry = bpsEntries.get(code);
        const title = titleFromHtml(html) || bpsEntry.title;
        const riskLevels = uniqueStrings(scopes.flatMap((scope) => scope.profiles.map((profile) => profile.risk_level)));
        const licenses = uniqueStrings(scopes.flatMap((scope) => (
            scope.profiles.flatMap((profile) => profile.licenses)
        )));

        if ((index + 1) % 20 === 0 || index + 1 === codes.length) {
            process.stdout.write(`\rMengambil profil risiko OSS: ${index + 1}/${codes.length}`);
        }

        return {
            code,
            title,
            description: bpsEntry.description,
            category_code: null,
            category_title: null,
            oss_id: ossId,
            risk_levels: riskLevels,
            licenses,
            scopes,
        };
    });
}

async function main() {
    console.log(`Membaca KBLI 2025 BPS dari ${bpsTextPath}`);
    const bpsEntries = await parseBpsBook(bpsTextPath);
    console.log(`Ditemukan ${bpsEntries.size} kode KBLI 2025 resmi.`);

    const leafIds = await discoverLeafIds(bpsEntries);
    console.log(`Ditemukan ${leafIds.size} halaman detail OSS KBLI 2025.`);

    const records = await buildDataset(bpsEntries, leafIds);
    process.stdout.write('\n');

    const categories = {
        A: 'Pertanian, Kehutanan, dan Perikanan',
        B: 'Pertambangan dan Penggalian',
        C: 'Industri',
        D: 'Penyediaan Listrik, Gas, Uap/Air Panas, dan Udara Dingin',
        E: 'Penyediaan Air; Pengelolaan Air Limbah, Penanganan Limbah, dan Remediasi',
        F: 'Konstruksi',
        G: 'Perdagangan Besar dan Eceran',
        H: 'Transportasi dan Penyimpanan',
        I: 'Aktivitas Penyediaan Akomodasi dan Makan Minum',
        J: 'Aktivitas Penerbitan, Penyiaran, serta Produksi dan Distribusi Konten',
        K: 'Aktivitas Telekomunikasi, Pemrograman Komputer, Konsultansi, Infrastruktur Komputasi, dan Jasa Informasi Lainnya',
        L: 'Aktivitas Keuangan dan Asuransi',
        M: 'Aktivitas Real Estat',
        N: 'Aktivitas Profesional, Ilmiah, dan Teknis',
        O: 'Aktivitas Administratif dan Penunjang Usaha',
        P: 'Administrasi Pemerintahan dan Pertahanan, serta Jaminan Sosial Wajib',
        Q: 'Pendidikan',
        R: 'Aktivitas Kesehatan Manusia dan Aktivitas Sosial',
        S: 'Kesenian, Olahraga, dan Rekreasi',
        T: 'Aktivitas Jasa Lainnya',
        U: 'Aktivitas Rumah Tangga sebagai Pemberi Kerja dan Aktivitas Produksi untuk Keperluan Sendiri',
        V: 'Aktivitas Badan Internasional dan Badan Ekstra Internasional Lainnya',
    };

    for (const record of records) {
        const category = record.scopes[0]?.profiles[0]?.external_code?.slice(0, 0) || null;
        void category;
    }

    const categoryRanges = [
        ['A', 1, 3], ['B', 5, 9], ['C', 10, 33], ['D', 35, 35], ['E', 36, 39],
        ['F', 41, 43], ['G', 46, 47], ['H', 49, 53], ['I', 55, 56], ['J', 58, 60],
        ['K', 61, 63], ['L', 64, 66], ['M', 68, 68], ['N', 69, 75], ['O', 77, 82],
        ['P', 84, 84], ['Q', 85, 85], ['R', 86, 88], ['S', 90, 93], ['T', 94, 96],
        ['U', 97, 98], ['V', 99, 99],
    ];

    for (const record of records) {
        const division = Number(record.code.slice(0, 2));
        const range = categoryRanges.find(([, start, end]) => division >= start && division <= end);
        record.category_code = range?.[0] || null;
        record.category_title = range ? categories[range[0]] : null;
    }

    const dataset = {
        metadata: {
            version: '2025',
            generated_at: new Date().toISOString(),
            code_count: records.length,
            source_classification: 'Peraturan Badan Pusat Statistik Nomor 7 Tahun 2025 dan publikasi KBLI 2025 revisi 13 Januari 2026',
            source_risk: 'OSS RBA berdasarkan PP Nomor 28 Tahun 2025',
            classification_url: 'https://www.bps.go.id/id/publication/2025/12/24/a9b2f130776c7bea36008556/klasifikasi-baku-lapangan-usaha-indonesia-kbli-2025-.html',
            risk_url: 'https://oss.go.id/id/kbli',
        },
        records,
    };

    await fs.mkdir(path.dirname(outputPath), { recursive: true });
    await fs.writeFile(outputPath, `${JSON.stringify(dataset)}\n`, 'utf8');
    console.log(`Dataset tersimpan di ${outputPath}`);
}

main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
