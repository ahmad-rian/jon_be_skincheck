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

        // Build search queries from disease name
        $queries = $this->buildSearchQueries($penyakit);

        // Fetch from multiple sources in parallel
        $sources = $this->fetchFromAllSources($queries);

        if (count($sources) === 0) {
            return response()->json([
                'status' => false,
                'message' => 'Tidak ada sumber medis ditemukan untuk: '.$penyakit,
            ], 404);
        }

        // Step 2: Bentuk konteks sumber untuk AI
        $sumberText = '';
        foreach ($sources as $i => $src) {
            $no = $i + 1;
            $sumberText .= "[{$no}] {$src['title']}\n";
            $sumberText .= "Authors: {$src['authors']}\n";
            $sumberText .= "Journal: {$src['journal']} ({$src['pubdate']})\n";
            $sumberText .= "Source: {$src['source_db']}\n";
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
            'pmid' => $src['pmid'] ?? null,
            'source_db' => $src['source_db'],
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
     * Build multiple search queries from disease name.
     * Uses AI to translate Indonesian disease names to English medical terms.
     *
     * @return array<int, string>
     */
    private function buildSearchQueries(string $penyakit): array
    {
        $queries = [];

        // Extract scientific name from parentheses: "Panu (Tinea Versicolor)" -> "Tinea Versicolor"
        if (preg_match('/\(([^)]+)\)/', $penyakit, $matches)) {
            $scientificName = trim($matches[1]);
            $localName = trim(preg_replace('/\s*\([^)]+\)/', '', $penyakit));

            $queries[] = $scientificName;
            if ($localName !== '') {
                $queries[] = $localName;
            }

            return $queries;
        }

        // Clean special characters
        $cleaned = preg_replace('/[(){}\[\]"\'\/\\\\]/', ' ', $penyakit);
        $cleaned = preg_replace('/\s+/', ' ', trim($cleaned));

        // Use AI to translate Indonesian disease name to English medical term
        $englishName = $this->translateToMedicalEnglish($cleaned);

        if ($englishName && mb_strtolower($englishName) !== mb_strtolower($cleaned)) {
            $queries[] = $englishName;
        }
        $queries[] = $cleaned;

        return $queries;
    }

    /**
     * Translate Indonesian disease name to English medical term using Groq AI.
     * Uses a fast, low-token call for translation only.
     */
    private function translateToMedicalEnglish(string $diseaseName): ?string
    {
        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->withHeaders([
                'Authorization' => 'Bearer '.config('services.groq.key'),
            ])
            ->post('https://api.groq.com/openai/v1/chat/completions', [
                'model' => config('services.groq.model'),
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a medical translator. Translate the given disease name to its English medical/scientific term. Reply with ONLY the English term, nothing else. If the input is already in English, return it as-is.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $diseaseName,
                    ],
                ],
                'temperature' => 0,
                'max_tokens' => 50,
            ]);

        if ($response->failed()) {
            return null;
        }

        $content = $response->json('choices.0.message.content');

        if (! $content) {
            return null;
        }

        // Clean up response - remove quotes, periods, extra whitespace
        $content = trim($content, " \t\n\r\0\x0B.\"'");

        return $content !== '' ? $content : null;
    }

    /**
     * Fetch articles from PubMed and Europe PMC, merge and deduplicate.
     *
     * @param  array<int, string>  $queries
     * @return array<int, array{pmid: ?string, title: string, authors: string, journal: string, pubdate: string, url: string, abstract: ?string, source_db: string}>
     */
    private function fetchFromAllSources(array $queries): array
    {
        $allArticles = [];
        $seenTitles = [];

        foreach ($queries as $query) {
            // Fetch from PubMed and Europe PMC in parallel
            $pubmedArticles = $this->fetchPubMedArticles($query);
            $epmcArticles = $this->fetchEuropePmcArticles($query);

            foreach ([...$pubmedArticles, ...$epmcArticles] as $article) {
                // Deduplicate by normalized title
                $normalizedTitle = mb_strtolower(trim($article['title']));
                if (isset($seenTitles[$normalizedTitle]) || $normalizedTitle === '') {
                    continue;
                }
                $seenTitles[$normalizedTitle] = true;
                $allArticles[] = $article;
            }

            // Stop if we have enough articles
            if (count($allArticles) >= 8) {
                break;
            }
        }

        // Limit to 8 articles max to keep AI prompt manageable
        return array_slice($allArticles, 0, 8);
    }

    /**
     * Fetch real articles from PubMed E-utilities API (free, no key required).
     *
     * @return array<int, array{pmid: string, title: string, authors: string, journal: string, pubdate: string, url: string, abstract: ?string, source_db: string}>
     */
    private function fetchPubMedArticles(string $query): array
    {
        $searchQuery = $query.' skin disease treatment symptoms';

        // ESearch: cari artikel IDs
        $searchResponse = Http::timeout(15)->get('https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi', [
            'db' => 'pubmed',
            'term' => $searchQuery,
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
                'source_db' => 'PubMed',
            ];
        }

        return $articles;
    }

    /**
     * Fetch articles from Europe PMC API (free, no key required).
     *
     * @return array<int, array{pmid: ?string, title: string, authors: string, journal: string, pubdate: string, url: string, abstract: ?string, source_db: string}>
     */
    private function fetchEuropePmcArticles(string $query): array
    {
        $searchQuery = $query.' skin disease';

        $response = Http::timeout(15)->get('https://www.ebi.ac.uk/europepmc/webservices/rest/search', [
            'query' => $searchQuery,
            'format' => 'json',
            'pageSize' => 5,
            'resultType' => 'core',
            'sort' => 'RELEVANCE',
        ]);

        if ($response->failed()) {
            return [];
        }

        $results = $response->json('resultList.result', []);

        $articles = [];
        foreach ($results as $result) {
            $title = $result['title'] ?? '';
            if ($title === '') {
                continue;
            }

            $authorList = $result['authorString'] ?? '';
            // Truncate author list
            $authorParts = explode(', ', $authorList);
            if (count($authorParts) > 3) {
                $authorList = implode(', ', array_slice($authorParts, 0, 3)).', et al.';
            }

            $pmid = $result['pmid'] ?? null;
            $doi = $result['doi'] ?? null;
            $epmcId = $result['id'] ?? null;

            // Build URL: prefer PubMed link if PMID exists, otherwise Europe PMC
            if ($pmid) {
                $url = "https://pubmed.ncbi.nlm.nih.gov/{$pmid}/";
            } elseif ($doi) {
                $url = "https://doi.org/{$doi}";
            } else {
                $url = "https://europepmc.org/article/MED/{$epmcId}";
            }

            $articles[] = [
                'pmid' => $pmid,
                'title' => $title,
                'authors' => $authorList,
                'journal' => $result['journalTitle'] ?? $result['bookOrReportDetails']['publisher'] ?? '',
                'pubdate' => $result['firstPublicationDate'] ?? '',
                'url' => $url,
                'abstract' => isset($result['abstractText']) ? mb_substr($result['abstractText'], 0, 1500) : null,
                'source_db' => 'Europe PMC',
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
