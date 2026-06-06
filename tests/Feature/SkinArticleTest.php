<?php

use Illuminate\Support\Facades\Http;

test('skin article requires q parameter', function () {
    $response = $this->getJson('/api/skin-article');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['q']);
});

test('skin article fetches pubmed and europe pmc sources and returns article from groq', function () {
    Http::fake([
        'eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi*' => Http::response([
            'esearchresult' => [
                'idlist' => ['12345678', '87654321'],
            ],
        ]),
        'eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi*' => Http::response([
            'result' => [
                '12345678' => [
                    'title' => 'Eczema treatment review',
                    'authors' => [['name' => 'Smith J'], ['name' => 'Doe A']],
                    'fulljournalname' => 'Journal of Dermatology',
                    'pubdate' => '2024 Jan',
                ],
                '87654321' => [
                    'title' => 'Atopic dermatitis pathogenesis',
                    'authors' => [['name' => 'Lee K']],
                    'fulljournalname' => 'Skin Research',
                    'pubdate' => '2023 Jun',
                ],
            ],
        ]),
        'eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi*' => Http::response(
            "1. Smith J. Eczema treatment review. This is a detailed abstract about eczema treatment options including topical corticosteroids and moisturizers for managing symptoms.\n\n2. Lee K. Atopic dermatitis pathogenesis. This abstract covers the pathogenesis of atopic dermatitis including genetic and environmental factors."
        ),
        'www.ebi.ac.uk/europepmc/webservices/rest/search*' => Http::response([
            'resultList' => [
                'result' => [
                    [
                        'id' => '99999999',
                        'pmid' => '99999999',
                        'title' => 'Europe PMC article on eczema management',
                        'authorString' => 'Garcia M, Wang L',
                        'journalTitle' => 'European Journal of Dermatology',
                        'firstPublicationDate' => '2024-03-15',
                        'abstractText' => 'This study reviews current management strategies for eczema including biological therapies and lifestyle modifications that have shown promising results in clinical trials.',
                    ],
                ],
            ],
        ]),
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => "## Tentang Penyakit\nEczema adalah kondisi kulit [1]. Penyebabnya melibatkan faktor genetik [2].\n\n## Gejala\n- Kulit kering [1]\n- Gatal [1,2]",
                    ],
                ],
            ],
            'model' => 'llama-3.3-70b-versatile',
        ]),
    ]);

    $response = $this->getJson('/api/skin-article?q=eczema&cf=85');

    $response->assertStatus(200)
        ->assertJson([
            'status' => true,
            'penyakit' => 'eczema',
            'cf' => '85',
        ])
        ->assertJsonStructure([
            'status',
            'penyakit',
            'cf',
            'artikel',
            'referensi' => [
                '*' => ['no', 'title', 'authors', 'journal', 'pubdate', 'url', 'source_db'],
            ],
            'jumlah_sumber',
            'model',
        ]);

    $referensi = $response->json('referensi');
    expect(count($referensi))->toBeGreaterThanOrEqual(2);
    expect($referensi[0]['source_db'])->toBe('PubMed');
});

test('skin article handles disease names with parentheses', function () {
    Http::fake([
        'eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi*' => Http::response([
            'esearchresult' => [
                'idlist' => ['11111111'],
            ],
        ]),
        'eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi*' => Http::response([
            'result' => [
                '11111111' => [
                    'title' => 'Tinea Versicolor treatment options',
                    'authors' => [['name' => 'Kumar R']],
                    'fulljournalname' => 'Mycology Journal',
                    'pubdate' => '2024 Feb',
                ],
            ],
        ]),
        'eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi*' => Http::response(
            '1. Kumar R. Tinea Versicolor treatment options. This is a detailed abstract about antifungal treatments for tinea versicolor including topical and systemic approaches.'
        ),
        'www.ebi.ac.uk/europepmc/webservices/rest/search*' => Http::response([
            'resultList' => [
                'result' => [
                    [
                        'id' => '22222222',
                        'pmid' => '22222222',
                        'title' => 'Pityriasis versicolor: diagnosis and management',
                        'authorString' => 'Johnson P',
                        'journalTitle' => 'Clinical Dermatology',
                        'firstPublicationDate' => '2023-08-10',
                        'abstractText' => 'Pityriasis versicolor is a common superficial fungal infection caused by Malassezia species with comprehensive treatment review.',
                    ],
                ],
            ],
        ]),
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => "## Tentang Penyakit\nPanu (Tinea Versicolor) adalah infeksi jamur [1].\n\n## Gejala\n- Bercak putih [1]",
                    ],
                ],
            ],
            'model' => 'llama-3.3-70b-versatile',
        ]),
    ]);

    // This query previously caused HTTP 404 because parentheses broke PubMed search
    $response = $this->getJson('/api/skin-article?q='.urlencode('Panu (Tinea Versicolor)').'&cf=80');

    $response->assertStatus(200)
        ->assertJson([
            'status' => true,
            'penyakit' => 'Panu (Tinea Versicolor)',
        ]);

    expect($response->json('jumlah_sumber'))->toBeGreaterThanOrEqual(1);
});

test('skin article returns 404 when no sources found from any provider', function () {
    Http::fake([
        'eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi*' => Http::response([
            'esearchresult' => [
                'idlist' => [],
            ],
        ]),
        'www.ebi.ac.uk/europepmc/webservices/rest/search*' => Http::response([
            'resultList' => [
                'result' => [],
            ],
        ]),
    ]);

    $response = $this->getJson('/api/skin-article?q=xyznotadisease&cf=50');

    $response->assertStatus(404)
        ->assertJson([
            'status' => false,
        ]);
});

test('skin article handles groq api failure', function () {
    Http::fake([
        'eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi*' => Http::response([
            'esearchresult' => ['idlist' => ['11111111']],
        ]),
        'eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi*' => Http::response([
            'result' => [
                '11111111' => [
                    'title' => 'Test article',
                    'authors' => [['name' => 'Test A']],
                    'fulljournalname' => 'Test Journal',
                    'pubdate' => '2024',
                ],
            ],
        ]),
        'eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi*' => Http::response('Abstract text here for test article which is long enough to pass the 100 char minimum check in the controller.'),
        'www.ebi.ac.uk/europepmc/webservices/rest/search*' => Http::response([
            'resultList' => ['result' => []],
        ]),
        'api.groq.com/*' => Http::response(['error' => 'rate limited'], 429),
    ]);

    $response = $this->getJson('/api/skin-article?q=psoriasis&cf=70');

    $response->assertStatus(502)
        ->assertJson(['status' => false]);
});

test('skin article falls back to europe pmc when pubmed returns no results', function () {
    Http::fake([
        'eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi*' => Http::response([
            'esearchresult' => [
                'idlist' => [],
            ],
        ]),
        'www.ebi.ac.uk/europepmc/webservices/rest/search*' => Http::response([
            'resultList' => [
                'result' => [
                    [
                        'id' => '33333333',
                        'pmid' => '33333333',
                        'title' => 'Treatment of fungal skin infections',
                        'authorString' => 'Silva M, Chen W, Park J',
                        'journalTitle' => 'International Dermatology',
                        'firstPublicationDate' => '2024-01-20',
                        'abstractText' => 'A comprehensive review of antifungal therapies for common dermatological infections including topical and systemic treatment approaches.',
                    ],
                    [
                        'id' => '44444444',
                        'doi' => '10.1234/test.2024',
                        'title' => 'Fungal skin disease epidemiology',
                        'authorString' => 'Brown A',
                        'journalTitle' => 'Epidemiology Today',
                        'firstPublicationDate' => '2023-11-05',
                        'abstractText' => 'This paper examines the global epidemiology of superficial fungal infections affecting the skin and their impact on public health.',
                    ],
                ],
            ],
        ]),
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => "## Tentang Penyakit\nInfeksi jamur kulit [1].\n\n## Gejala\n- Gatal [1]",
                    ],
                ],
            ],
            'model' => 'llama-3.3-70b-versatile',
        ]),
    ]);

    $response = $this->getJson('/api/skin-article?q=kurap&cf=75');

    $response->assertStatus(200)
        ->assertJson(['status' => true]);

    $referensi = $response->json('referensi');
    expect(count($referensi))->toBe(2);
    expect($referensi[0]['source_db'])->toBe('Europe PMC');
    expect($referensi[1]['source_db'])->toBe('Europe PMC');
});
