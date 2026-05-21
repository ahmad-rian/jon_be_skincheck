<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SkinArticleController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'max:255'],
            'cf' => ['nullable', 'string', 'max:10'],
        ]);

        $penyakit = $request->input('q');
        $cf = $request->input('cf', '');

        // Step 1: Cari artikel nyata dari PubMed
        $sources = $this->fetchPubMedArticles($penyakit);

        if (count($sources) === 0) {
            return response()->json([
                'status' => false,
                'message' => 'Tidak ada sumber medis ditemukan di PubMed untuk: '.$penyakit,
            ], 404);
        }

        // Step 2: Bentuk konteks sumber untuk AI
        $sumberText = '';
        foreach ($sources as $i => $src) {
            $no = $i + 1;
            $sumberText .= "[{$no}] {$src['title']}\n";
            $sumberText .= "Authors: {$src['authors']}\n";
            $sumberText .= "Journal: {$src['journal']} ({$src['pubdate']})\n";
            $sumberText .= "Link: {$src['url']}\n";
            if ($src['abstract']) {
                $sumberText .= "Abstract: {$src['abstract']}\n";
            }
            $sumberText .= "\n";
        }

        // Step 3: Groq AI menyimpulkan dari sumber nyata
        $prompt = <<<PROMPT
        Kamu adalah asisten edukasi kesehatan kulit.

        INSTRUKSI PENTING:
        - Gunakan HANYA informasi dari sumber-sumber ilmiah berikut
        - JANGAN mengarang atau menambah informasi sendiri
        - Jika informasi tidak tersedia dari sumber, tulis: 'informasi tidak tersedia dari sumber yang dirujuk'
        - Gunakan bahasa Indonesia yang mudah dipahami
        - Saat mengutip informasi, cantumkan nomor sumber dalam kurung siku, contoh: [1], [2], [1,3]

        Diagnosis Sistem Pakar: {$penyakit}
        Nilai Certainty Factor: {$cf}%

        === SUMBER ILMIAH ===
        {$sumberText}
        === AKHIR SUMBER ===

        Buat artikel kesimpulan dengan format Markdown:

        ## Tentang Penyakit
        (paragraf berdasarkan sumber, cantumkan nomor rujukan)

        ## Gejala
        - list gejala [nomor sumber]

        ## Penyebab
        - list penyebab [nomor sumber]

        ## Cara Mengatasi
        - list cara mengatasi [nomor sumber]

        ## Kapan Harus ke Dokter
        (paragraf, cantumkan nomor rujukan)
        PROMPT;

        $aiResponse = Http::timeout(60)
            ->connectTimeout(15)
            ->withHeaders([
                'Authorization' => 'Bearer '.config('services.groq.key'),
            ])
            ->post('https://api.groq.com/openai/v1/chat/completions', [
                'model' => config('services.groq.model'),
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a medical education assistant. Synthesize information ONLY from the provided scientific sources. Always cite source numbers in brackets like [1], [2]. Respond in Bahasa Indonesia. Do NOT invent information.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.2,
                'max_tokens' => 2048,
            ]);

        if ($aiResponse->failed()) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil artikel dari AI',
                'debug' => $aiResponse->json() ?? $aiResponse->body(),
            ], 502);
        }

        $aiData = $aiResponse->json();
        $content = $aiData['choices'][0]['message']['content'] ?? null;

        if (! $content) {
            return response()->json([
                'status' => false,
                'message' => 'Artikel tidak tersedia dari AI',
            ], 502);
        }

        // Format referensi untuk response
        $referensi = array_map(fn ($src, $i) => [
            'no' => $i + 1,
            'title' => $src['title'],
            'authors' => $src['authors'],
            'journal' => $src['journal'],
            'pubdate' => $src['pubdate'],
            'url' => $src['url'],
            'pmid' => $src['pmid'],
        ], $sources, array_keys($sources));

        return response()->json([
            'status' => true,
            'penyakit' => $penyakit,
            'cf' => $cf,
            'artikel' => $content,
            'referensi' => $referensi,
            'jumlah_sumber' => count($sources),
            'model' => $aiData['model'] ?? config('services.groq.model'),
        ], options: JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Fetch real articles from PubMed E-utilities API (free, no key required).
     *
     * @return array<int, array{pmid: string, title: string, authors: string, journal: string, pubdate: string, url: string, abstract: ?string}>
     */
    private function fetchPubMedArticles(string $penyakit): array
    {
        $query = $penyakit.' skin disease treatment symptoms';

        // ESearch: cari artikel IDs
        $searchResponse = Http::timeout(15)->get('https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi', [
            'db' => 'pubmed',
            'term' => $query,
            'retmax' => 5,
            'sort' => 'relevance',
            'retmode' => 'json',
        ]);

        if ($searchResponse->failed()) {
            return [];
        }

        $ids = $searchResponse->json('esearchresult.idlist', []);

        if (count($ids) === 0) {
            return [];
        }

        $idString = implode(',', $ids);

        // Fetch summary + abstracts in parallel
        $responses = Http::pool(fn ($pool) => [
            $pool->as('summary')->timeout(15)->get('https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi', [
                'db' => 'pubmed',
                'id' => $idString,
                'retmode' => 'json',
            ]),
            $pool->as('abstracts')->timeout(15)->get('https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi', [
                'db' => 'pubmed',
                'id' => $idString,
                'rettype' => 'abstract',
                'retmode' => 'text',
            ]),
        ]);

        if ($responses['summary']->failed()) {
            return [];
        }

        $summaryData = $responses['summary']->json('result', []);
        $abstractsRaw = $responses['abstracts']->successful() ? $responses['abstracts']->body() : '';

        // Parse abstracts per PMID
        $abstractMap = $this->parseAbstracts($abstractsRaw, $ids);

        $articles = [];
        foreach ($ids as $pmid) {
            $article = $summaryData[$pmid] ?? null;
            if (! $article) {
                continue;
            }

            $authors = collect($article['authors'] ?? [])
                ->pluck('name')
                ->take(3)
                ->implode(', ');

            if (count($article['authors'] ?? []) > 3) {
                $authors .= ', et al.';
            }

            $articles[] = [
                'pmid' => $pmid,
                'title' => $article['title'] ?? '',
                'authors' => $authors,
                'journal' => $article['fulljournalname'] ?? $article['source'] ?? '',
                'pubdate' => $article['pubdate'] ?? '',
                'url' => "https://pubmed.ncbi.nlm.nih.gov/{$pmid}/",
                'abstract' => $abstractMap[$pmid] ?? null,
            ];
        }

        return $articles;
    }

    /**
     * Parse raw PubMed abstract text into a map keyed by PMID.
     *
     * @param  array<string>  $ids
     * @return array<string, string>
     */
    private function parseAbstracts(string $raw, array $ids): array
    {
        $map = [];

        if (! $raw) {
            return $map;
        }

        // PubMed efetch text format separates articles by double newlines with PMID references
        // Split by article boundaries
        $sections = preg_split('/\n\n(?=\d+\.\s)/', $raw);

        foreach ($ids as $i => $pmid) {
            $section = $sections[$i] ?? '';
            // Extract just the abstract portion (trim headers/metadata)
            $abstract = trim($section);
            if (strlen($abstract) > 100) {
                // Limit abstract length for prompt context
                $map[$pmid] = mb_substr($abstract, 0, 1500);
            }
        }

        return $map;
    }
}
