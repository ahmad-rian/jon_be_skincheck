<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Client\Response;
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

        // Indonesian local name (without scientific term in parentheses) for DOAJ search
        $indonesianName = $this->extractIndonesianName($penyakit);

        // Fetch from multiple sources, guaranteeing a mix of Indonesian + international references
        $sources = $this->fetchFromAllSources($queries, $indonesianName);

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
        - Sumber berisi campuran jurnal Indonesia dan jurnal internasional; perlakukan semua sumber setara
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

        $aiResponse = Http::timeout(45)
            ->connectTimeout(10)
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
            'issn' => $src['issn'] ?? null,
            'is_open_access' => $src['is_open_access'] ?? false,
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
     * Extract the Indonesian local disease name (without the scientific term in parentheses).
     * "Panu (Tinea Versicolor)" -> "Panu". Used to search Indonesian-language journals on DOAJ.
     */
    private function extractIndonesianName(string $penyakit): string
    {
        $localName = trim(preg_replace('/\s*\([^)]+\)/', '', $penyakit));
        $localName = preg_replace('/[(){}\[\]"\'\/\\\\]/', ' ', $localName !== '' ? $localName : $penyakit);

        return trim(preg_replace('/\s+/', ' ', $localName));
    }

    /**
     * Fetch articles from international (PubMed, Europe PMC) and Indonesian (DOAJ) sources,
     * guaranteeing a mix so references are never fully English. Deduplicated by title.
     *
     * @param  array<int, string>  $queries
     * @return array<int, array{pmid: ?string, title: string, authors: string, journal: string, pubdate: string, url: string, abstract: ?string, issn: ?string, is_open_access: bool, source_db: string}>
     */
    private function fetchFromAllSources(array $queries, string $indonesianName): array
    {
        $maxTotal = 8;
        $indonesianQuota = 5;
        $seenTitles = [];

        // International search uses the best (translated) query; Indonesian sources use the local name.
        $primaryQuery = $queries[0] ?? $indonesianName;

        // Stage 1: fire all independent source searches concurrently to avoid sequential timeouts.
        $stage1 = Http::pool(fn ($pool) => [
            $pool->as('doaj')->timeout(10)->connectTimeout(5)->get(
                'https://doaj.org/api/search/articles/'.rawurlencode($indonesianName.' AND index.language:Indonesian'),
                ['pageSize' => 6, 'sort' => 'score:desc'],
            ),
            $pool->as('wiki')->timeout(10)->connectTimeout(5)->get('https://id.wikipedia.org/w/api.php', [
                'action' => 'query',
                'generator' => 'search',
                'gsrsearch' => $indonesianName,
                'gsrlimit' => 2,
                'prop' => 'extracts|info',
                'exintro' => 1,
                'explaintext' => 1,
                'inprop' => 'url',
                'format' => 'json',
            ]),
            $pool->as('epmc')->timeout(10)->connectTimeout(5)->get('https://www.ebi.ac.uk/europepmc/webservices/rest/search', [
                'query' => $primaryQuery.' skin disease',
                'format' => 'json',
                'pageSize' => 5,
                'resultType' => 'core',
                'sort' => 'RELEVANCE',
            ]),
            $pool->as('pubmed_search')->timeout(10)->connectTimeout(5)->get('https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi', [
                'db' => 'pubmed',
                'term' => $primaryQuery.' skin disease treatment symptoms',
                'retmax' => 5,
                'sort' => 'relevance',
                'retmode' => 'json',
            ]),
        ]);

        // Indonesian / openable sources first: DOAJ journals, then Wikipedia Indonesia.
        $indonesian = $this->collectUnique([
            ...$this->parseDoajArticles($stage1['doaj']),
            ...$this->parseWikipediaArticles($stage1['wiki']),
        ], $seenTitles);

        // International sources (PubMed needs a second pooled call for summaries/abstracts).
        $international = $this->collectUnique([
            ...$this->fetchPubMedSummaries($stage1['pubmed_search']),
            ...$this->parseEpmcArticles($stage1['epmc']),
        ], $seenTitles);

        // Compose: Indonesian sources first (up to quota), then fill remaining slots with
        // international, then top up with any leftover Indonesian articles.
        $reservedIndonesian = array_slice($indonesian, 0, $indonesianQuota);
        $remainingSlots = $maxTotal - count($reservedIndonesian);

        $merged = [
            ...$reservedIndonesian,
            ...array_slice($international, 0, $remainingSlots),
        ];

        if (count($merged) < $maxTotal) {
            $merged = [...$merged, ...array_slice($indonesian, count($reservedIndonesian))];
        }

        return array_slice($merged, 0, $maxTotal);
    }

    /**
     * Whether a pooled response is a usable, successful HTTP response.
     * Http::pool returns a ConnectionException (not a Response) when a request fails to connect.
     */
    private function isSuccessfulResponse(mixed $response): bool
    {
        return $response instanceof Response && $response->successful();
    }

    /**
     * Filter a batch of articles down to ones with an unseen, non-empty normalized title.
     * Mutates $seenTitles to track titles across batches.
     *
     * @param  array<int, array{title: string}>  $articles
     * @param  array<string, bool>  $seenTitles
     * @return array<int, array{title: string}>
     */
    private function collectUnique(array $articles, array &$seenTitles): array
    {
        $unique = [];

        foreach ($articles as $article) {
            $normalizedTitle = mb_strtolower(trim($article['title']));
            if ($normalizedTitle === '' || isset($seenTitles[$normalizedTitle])) {
                continue;
            }
            $seenTitles[$normalizedTitle] = true;
            $unique[] = $article;
        }

        return $unique;
    }

    /**
     * Fetch PubMed article summaries/abstracts from an esearch response (free, no key required).
     * Runs a second pooled call for summary + abstracts.
     *
     * @return array<int, array{pmid: string, title: string, authors: string, journal: string, pubdate: string, url: string, abstract: ?string, issn: ?string, is_open_access: bool, source_db: string}>
     */
    private function fetchPubMedSummaries(mixed $esearchResponse): array
    {
        if (! $this->isSuccessfulResponse($esearchResponse)) {
            return [];
        }

        $ids = $esearchResponse->json('esearchresult.idlist', []);

        if (count($ids) === 0) {
            return [];
        }

        $idString = implode(',', $ids);

        // Fetch summary + abstracts in parallel
        $responses = Http::pool(fn ($pool) => [
            $pool->as('summary')->timeout(10)->connectTimeout(5)->get('https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi', [
                'db' => 'pubmed',
                'id' => $idString,
                'retmode' => 'json',
            ]),
            $pool->as('abstracts')->timeout(10)->connectTimeout(5)->get('https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi', [
                'db' => 'pubmed',
                'id' => $idString,
                'rettype' => 'abstract',
                'retmode' => 'text',
            ]),
        ]);

        if (! $this->isSuccessfulResponse($responses['summary'])) {
            return [];
        }

        $summaryData = $responses['summary']->json('result', []);
        $abstractsRaw = $this->isSuccessfulResponse($responses['abstracts']) ? $responses['abstracts']->body() : '';

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
                'issn' => $article['issn'] ?? null,
                'is_open_access' => false,
                'source_db' => 'PubMed',
            ];
        }

        return $articles;
    }

    /**
     * Parse articles from a Europe PMC search response (free, no key required).
     *
     * @return array<int, array{pmid: ?string, title: string, authors: string, journal: string, pubdate: string, url: string, abstract: ?string, issn: ?string, is_open_access: bool, source_db: string}>
     */
    private function parseEpmcArticles(mixed $response): array
    {
        if (! $this->isSuccessfulResponse($response)) {
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
            $isOpenAccess = ($result['isOpenAccess'] ?? 'N') === 'Y';

            // Prefer a directly-openable open-access full text link so the user does not hit a paywall.
            $openAccessUrl = $this->resolveEpmcOpenAccessUrl($result);

            if ($openAccessUrl !== null) {
                $url = $openAccessUrl;
            } elseif ($pmid) {
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
                'issn' => $result['journalInfo']['journal']['issn'] ?? null,
                'is_open_access' => $isOpenAccess || $openAccessUrl !== null,
                'source_db' => 'Europe PMC',
            ];
        }

        return $articles;
    }

    /**
     * Resolve a directly-openable open-access full text URL from a Europe PMC result.
     * Priority: open-access HTML, then any open-access link, then free full text. Null if none.
     *
     * @param  array<string, mixed>  $result
     */
    private function resolveEpmcOpenAccessUrl(array $result): ?string
    {
        $links = $result['fullTextUrlList']['fullTextUrl'] ?? [];

        $pick = function (callable $matcher) use ($links): ?string {
            foreach ($links as $link) {
                if (! empty($link['url']) && $matcher($link)) {
                    return $link['url'];
                }
            }

            return null;
        };

        return $pick(fn ($l) => ($l['availabilityCode'] ?? '') === 'OA' && ($l['documentStyle'] ?? '') === 'html')
            ?? $pick(fn ($l) => ($l['availabilityCode'] ?? '') === 'OA')
            ?? $pick(fn ($l) => ($l['availability'] ?? '') === 'Free');
    }

    /**
     * Parse openable Indonesian-language articles from a Wikipedia Indonesia (MediaWiki) response.
     * Always open access — useful as a user-friendly, directly-readable reference.
     *
     * @return array<int, array{pmid: ?string, title: string, authors: string, journal: string, pubdate: string, url: string, abstract: ?string, issn: ?string, is_open_access: bool, source_db: string}>
     */
    private function parseWikipediaArticles(mixed $response): array
    {
        if (! $this->isSuccessfulResponse($response)) {
            return [];
        }

        $pages = $response->json('query.pages', []);

        $articles = [];
        foreach ($pages as $page) {
            $title = $page['title'] ?? '';
            $extract = trim($page['extract'] ?? '');
            if ($title === '' || $extract === '') {
                continue;
            }

            $articles[] = [
                'pmid' => null,
                'title' => $title,
                'authors' => 'Artikel Wikipedia',
                'journal' => 'Wikipedia Indonesia',
                'pubdate' => '',
                'url' => $page['fullurl'] ?? 'https://id.wikipedia.org/wiki/'.rawurlencode($title),
                'abstract' => mb_substr($extract, 0, 1500),
                'issn' => null,
                'is_open_access' => true,
                'source_db' => 'Wikipedia Indonesia',
            ];
        }

        return $articles;
    }

    /**
     * Parse Indonesian-language journal articles from a DOAJ API response (free, no key required).
     * Filtered to Indonesian-language journals as a practical proxy for Indonesian (Sinta-indexed)
     * journals — DOAJ does not expose Sinta accreditation levels.
     *
     * @return array<int, array{pmid: ?string, title: string, authors: string, journal: string, pubdate: string, url: string, abstract: ?string, issn: ?string, is_open_access: bool, source_db: string}>
     */
    private function parseDoajArticles(mixed $response): array
    {
        if (! $this->isSuccessfulResponse($response)) {
            return [];
        }

        $results = $response->json('results', []);

        $articles = [];
        foreach ($results as $result) {
            $bibjson = $result['bibjson'] ?? [];
            $title = $bibjson['title'] ?? '';
            if ($title === '') {
                continue;
            }

            $authors = collect($bibjson['author'] ?? [])
                ->pluck('name')
                ->filter()
                ->take(3)
                ->implode(', ');

            if (count($bibjson['author'] ?? []) > 3) {
                $authors .= ', et al.';
            }

            $pubdate = trim(($bibjson['month'] ?? '').' '.($bibjson['year'] ?? ''));

            $articles[] = [
                'pmid' => null,
                'title' => $title,
                'authors' => $authors,
                'journal' => $bibjson['journal']['title'] ?? '',
                'pubdate' => $pubdate,
                'url' => $this->resolveDoajUrl($bibjson, $result['id'] ?? null),
                'abstract' => isset($bibjson['abstract']) ? mb_substr($bibjson['abstract'], 0, 1500) : null,
                'issn' => $this->extractDoajIssn($bibjson),
                'is_open_access' => true,
                'source_db' => 'DOAJ (Jurnal Indonesia)',
            ];
        }

        return $articles;
    }

    /**
     * Resolve the best public URL for a DOAJ article: fulltext link, then DOI, then DOAJ page.
     *
     * @param  array<string, mixed>  $bibjson
     */
    private function resolveDoajUrl(array $bibjson, ?string $id): string
    {
        foreach ($bibjson['link'] ?? [] as $link) {
            if (($link['type'] ?? '') === 'fulltext' && ! empty($link['url'])) {
                return $link['url'];
            }
        }

        foreach ($bibjson['identifier'] ?? [] as $identifier) {
            if (($identifier['type'] ?? '') === 'doi' && ! empty($identifier['id'])) {
                return 'https://doi.org/'.$identifier['id'];
            }
        }

        return $id ? "https://doaj.org/article/{$id}" : 'https://doaj.org';
    }

    /**
     * Extract a journal ISSN (print or electronic) from a DOAJ bibjson record.
     *
     * @param  array<string, mixed>  $bibjson
     */
    private function extractDoajIssn(array $bibjson): ?string
    {
        foreach ($bibjson['identifier'] ?? [] as $identifier) {
            if (in_array($identifier['type'] ?? '', ['pissn', 'eissn'], true) && ! empty($identifier['id'])) {
                return $identifier['id'];
            }
        }

        return null;
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
