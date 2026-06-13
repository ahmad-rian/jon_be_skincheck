<?php

use Illuminate\Support\Facades\Http;

test('skin article requires q parameter', function () {
    $response = $this->getJson('/api/skin-article');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['q']);
});

test('skin article fetches pubmed and europe pmc sources and returns article from groq', function () {
    Http::fake([
        'api.groq.com/*' => Http::sequence()
            // First call: translation
            ->push([
                'choices' => [['message' => ['content' => 'Eczema']]],
            ])
            // Second call: article generation
            ->push([
                'choices' => [
                    [
                        'message' => [
                            'content' => "## Tentang Penyakit\nEczema adalah kondisi kulit [1]. Penyebabnya melibatkan faktor genetik [2].\n\n## Gejala\n- Kulit kering [1]\n- Gatal [1,2]",
                        ],
                    ],
                ],
                'model' => 'llama-3.3-70b-versatile',
            ]),
        'doaj.org/api/*' => Http::response(['results' => []]),
        'id.wikipedia.org/*' => Http::response(['query' => ['pages' => []]]),
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

test('skin article handles disease names with parentheses without translation', function () {
    Http::fake([
        'doaj.org/api/*' => Http::response(['results' => []]),
        'id.wikipedia.org/*' => Http::response(['query' => ['pages' => []]]),
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

    // Parentheses format skips translation - extracts scientific name directly
    $response = $this->getJson('/api/skin-article?q='.urlencode('Panu (Tinea Versicolor)').'&cf=80');

    $response->assertStatus(200)
        ->assertJson([
            'status' => true,
            'penyakit' => 'Panu (Tinea Versicolor)',
        ]);

    expect($response->json('jumlah_sumber'))->toBeGreaterThanOrEqual(1);
});

test('skin article translates indonesian disease name to english', function () {
    Http::fake([
        'api.groq.com/*' => Http::sequence()
            // First call: translation - "Karsinoma Sel Basal" -> "Basal Cell Carcinoma"
            ->push([
                'choices' => [['message' => ['content' => 'Basal Cell Carcinoma']]],
            ])
            // Second call: article generation
            ->push([
                'choices' => [
                    [
                        'message' => [
                            'content' => "## Tentang Penyakit\nKarsinoma sel basal adalah kanker kulit [1].\n\n## Gejala\n- Benjolan pada kulit [1]",
                        ],
                    ],
                ],
                'model' => 'llama-3.3-70b-versatile',
            ]),
        'doaj.org/api/*' => Http::response(['results' => []]),
        'id.wikipedia.org/*' => Http::response(['query' => ['pages' => []]]),
        'eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi*' => Http::response([
            'esearchresult' => [
                'idlist' => ['55555555'],
            ],
        ]),
        'eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi*' => Http::response([
            'result' => [
                '55555555' => [
                    'title' => 'Basal cell carcinoma: pathogenesis and treatment',
                    'authors' => [['name' => 'Chen W']],
                    'fulljournalname' => 'Oncology Journal',
                    'pubdate' => '2024 Mar',
                ],
            ],
        ]),
        'eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi*' => Http::response(
            '1. Chen W. Basal cell carcinoma: pathogenesis and treatment. This abstract covers the pathogenesis and modern treatment approaches for basal cell carcinoma including surgery and immunotherapy.'
        ),
        'www.ebi.ac.uk/europepmc/webservices/rest/search*' => Http::response([
            'resultList' => ['result' => []],
        ]),
    ]);

    // Indonesian name that previously would fail
    $response = $this->getJson('/api/skin-article?q='.urlencode('Karsinoma Sel Basal').'&cf=80');

    $response->assertStatus(200)
        ->assertJson([
            'status' => true,
            'penyakit' => 'Karsinoma Sel Basal',
        ]);

    expect($response->json('jumlah_sumber'))->toBeGreaterThanOrEqual(1);
});

test('skin article returns 404 when no sources found from any provider', function () {
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'xyznotadisease']]],
        ]),
        'doaj.org/api/*' => Http::response(['results' => []]),
        'id.wikipedia.org/*' => Http::response(['query' => ['pages' => []]]),
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
        'api.groq.com/*' => Http::sequence()
            // Translation call fails - should still try with original name
            ->push(['error' => 'rate limited'], 429)
            // Article generation also fails
            ->push(['error' => 'rate limited'], 429),
        'doaj.org/api/*' => Http::response(['results' => []]),
        'id.wikipedia.org/*' => Http::response(['query' => ['pages' => []]]),
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
    ]);

    $response = $this->getJson('/api/skin-article?q=psoriasis&cf=70');

    $response->assertStatus(502)
        ->assertJson(['status' => false]);
});

test('skin article falls back to europe pmc when pubmed returns no results', function () {
    Http::fake([
        'api.groq.com/*' => Http::sequence()
            // Translation call
            ->push([
                'choices' => [['message' => ['content' => 'Ringworm']]],
            ])
            // Article generation
            ->push([
                'choices' => [
                    [
                        'message' => [
                            'content' => "## Tentang Penyakit\nInfeksi jamur kulit [1].\n\n## Gejala\n- Gatal [1]",
                        ],
                    ],
                ],
                'model' => 'llama-3.3-70b-versatile',
            ]),
        'doaj.org/api/*' => Http::response(['results' => []]),
        'id.wikipedia.org/*' => Http::response(['query' => ['pages' => []]]),
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
    ]);

    $response = $this->getJson('/api/skin-article?q=kurap&cf=75');

    $response->assertStatus(200)
        ->assertJson(['status' => true]);

    $referensi = $response->json('referensi');
    expect(count($referensi))->toBe(2);
    expect($referensi[0]['source_db'])->toBe('Europe PMC');
    expect($referensi[1]['source_db'])->toBe('Europe PMC');
});

test('skin article includes indonesian doaj source mixed with international', function () {
    Http::fake([
        'api.groq.com/*' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => 'Tinea Versicolor']]]])
            ->push([
                'choices' => [['message' => ['content' => "## Tentang Penyakit\nPanu adalah infeksi jamur [1][2].\n\n## Gejala\n- Bercak [1]"]]],
                'model' => 'llama-3.3-70b-versatile',
            ]),
        'doaj.org/api/*' => Http::response([
            'results' => [
                [
                    'id' => 'abc123',
                    'bibjson' => [
                        'title' => 'Pengobatan Panu dengan Antijamur Topikal',
                        'author' => [['name' => 'Budi Santoso'], ['name' => 'Siti Aminah']],
                        'journal' => ['title' => 'Jurnal Kedokteran Indonesia'],
                        'year' => '2023',
                        'month' => '06',
                        'abstract' => 'Studi mengenai efektivitas pengobatan panu menggunakan terapi antijamur topikal pada pasien Indonesia.',
                        'identifier' => [
                            ['type' => 'pissn', 'id' => '1234-5678'],
                            ['type' => 'doi', 'id' => '10.1234/jki.2023.001'],
                        ],
                        'link' => [
                            ['type' => 'fulltext', 'url' => 'https://jki.example.id/article/1'],
                        ],
                    ],
                ],
            ],
        ]),
        'id.wikipedia.org/*' => Http::response(['query' => ['pages' => []]]),
        'eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi*' => Http::response([
            'esearchresult' => ['idlist' => ['77777777']],
        ]),
        'eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi*' => Http::response([
            'result' => [
                '77777777' => [
                    'title' => 'Tinea versicolor management review',
                    'authors' => [['name' => 'Smith J']],
                    'fulljournalname' => 'International Journal of Dermatology',
                    'pubdate' => '2024 Jan',
                ],
            ],
        ]),
        'eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi*' => Http::response(
            '1. Smith J. Tinea versicolor management review. A detailed abstract about antifungal treatment options for tinea versicolor including topical and systemic therapies for managing the condition.'
        ),
        'www.ebi.ac.uk/europepmc/webservices/rest/search*' => Http::response([
            'resultList' => ['result' => []],
        ]),
    ]);

    $response = $this->getJson('/api/skin-article?q='.urlencode('Panu (Tinea Versicolor)').'&cf=80');

    $response->assertStatus(200)
        ->assertJson(['status' => true]);

    $sources = collect($response->json('referensi'));

    // References must mix Indonesian (DOAJ) + international sources, never fully English
    expect($sources->pluck('source_db'))->toContain('DOAJ (Jurnal Indonesia)');
    expect($sources->whereNotIn('source_db', ['DOAJ (Jurnal Indonesia)'])->count())->toBeGreaterThanOrEqual(1);

    $doaj = $sources->firstWhere('source_db', 'DOAJ (Jurnal Indonesia)');
    expect($doaj['issn'])->toBe('1234-5678');
    expect($doaj['url'])->toBe('https://jki.example.id/article/1');
});

test('skin article still works when no indonesian source found', function () {
    Http::fake([
        'api.groq.com/*' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => 'Psoriasis']]]])
            ->push([
                'choices' => [['message' => ['content' => "## Tentang Penyakit\nPsoriasis [1]."]]],
                'model' => 'llama-3.3-70b-versatile',
            ]),
        'doaj.org/api/*' => Http::response(['results' => []]),
        'id.wikipedia.org/*' => Http::response(['query' => ['pages' => []]]),
        'eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi*' => Http::response([
            'esearchresult' => ['idlist' => ['88888888']],
        ]),
        'eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi*' => Http::response([
            'result' => [
                '88888888' => [
                    'title' => 'Psoriasis treatment review',
                    'authors' => [['name' => 'Doe A']],
                    'fulljournalname' => 'Dermatology Journal',
                    'pubdate' => '2024',
                ],
            ],
        ]),
        'eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi*' => Http::response(
            '1. Doe A. Psoriasis treatment review. A comprehensive abstract about psoriasis treatment including biologics and topical therapies for managing this chronic skin condition effectively.'
        ),
        'www.ebi.ac.uk/europepmc/webservices/rest/search*' => Http::response([
            'resultList' => ['result' => []],
        ]),
    ]);

    $response = $this->getJson('/api/skin-article?q=psoriasis&cf=70');

    $response->assertStatus(200)
        ->assertJson(['status' => true]);

    expect($response->json('jumlah_sumber'))->toBeGreaterThanOrEqual(1);
});

test('skin article includes wikipedia indonesia as openable source', function () {
    Http::fake([
        'api.groq.com/*' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => 'Scabies']]]])
            ->push([
                'choices' => [['message' => ['content' => "## Tentang Penyakit\nKudis [1]."]]],
                'model' => 'llama-3.3-70b-versatile',
            ]),
        'doaj.org/api/*' => Http::response(['results' => []]),
        'id.wikipedia.org/*' => Http::response([
            'query' => [
                'pages' => [
                    '4567' => [
                        'pageid' => 4567,
                        'title' => 'Skabies',
                        'extract' => 'Skabies atau kudis adalah penyakit kulit menular yang disebabkan oleh tungau Sarcoptes scabiei. Penyakit ini menimbulkan rasa gatal terutama pada malam hari.',
                        'fullurl' => 'https://id.wikipedia.org/wiki/Skabies',
                    ],
                ],
            ],
        ]),
        'eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi*' => Http::response([
            'esearchresult' => ['idlist' => ['66666666']],
        ]),
        'eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi*' => Http::response([
            'result' => [
                '66666666' => [
                    'title' => 'Scabies treatment review',
                    'authors' => [['name' => 'Lee K']],
                    'fulljournalname' => 'Dermatology Journal',
                    'pubdate' => '2024',
                ],
            ],
        ]),
        'eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi*' => Http::response(
            '1. Lee K. Scabies treatment review. A detailed abstract about scabies treatment options including topical permethrin and oral ivermectin for managing the parasitic infestation effectively.'
        ),
        'www.ebi.ac.uk/europepmc/webservices/rest/search*' => Http::response([
            'resultList' => ['result' => []],
        ]),
    ]);

    $response = $this->getJson('/api/skin-article?q=kudis&cf=80');

    $response->assertStatus(200)
        ->assertJson(['status' => true]);

    $wiki = collect($response->json('referensi'))->firstWhere('source_db', 'Wikipedia Indonesia');

    expect($wiki)->not->toBeNull();
    expect($wiki['is_open_access'])->toBeTrue();
    expect($wiki['url'])->toBe('https://id.wikipedia.org/wiki/Skabies');
});

test('skin article prefers europe pmc open access fulltext url', function () {
    Http::fake([
        'api.groq.com/*' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => 'Vitiligo']]]])
            ->push([
                'choices' => [['message' => ['content' => "## Tentang Penyakit\nVitiligo [1]."]]],
                'model' => 'llama-3.3-70b-versatile',
            ]),
        'doaj.org/api/*' => Http::response(['results' => []]),
        'id.wikipedia.org/*' => Http::response(['query' => ['pages' => []]]),
        'eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi*' => Http::response([
            'esearchresult' => ['idlist' => []],
        ]),
        'www.ebi.ac.uk/europepmc/webservices/rest/search*' => Http::response([
            'resultList' => [
                'result' => [
                    [
                        'id' => '12121212',
                        'pmid' => '12121212',
                        'title' => 'Vitiligo pathogenesis and treatment',
                        'authorString' => 'Tan A',
                        'journalTitle' => 'Pigment Journal',
                        'firstPublicationDate' => '2024-02-01',
                        'abstractText' => 'A comprehensive review of vitiligo pathogenesis and modern treatment approaches including phototherapy and topical agents for repigmentation.',
                        'isOpenAccess' => 'Y',
                        'fullTextUrlList' => [
                            'fullTextUrl' => [
                                ['availability' => 'Open access', 'availabilityCode' => 'OA', 'documentStyle' => 'pdf', 'url' => 'https://europepmc.org/articles/PMC12121212?pdf=render'],
                                ['availability' => 'Open access', 'availabilityCode' => 'OA', 'documentStyle' => 'html', 'url' => 'https://europepmc.org/articles/PMC12121212'],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $response = $this->getJson('/api/skin-article?q=vitiligo&cf=90');

    $response->assertStatus(200)
        ->assertJson(['status' => true]);

    $ref = collect($response->json('referensi'))->firstWhere('source_db', 'Europe PMC');

    // Open-access HTML full text is preferred over the paywalled PubMed page
    expect($ref['url'])->toBe('https://europepmc.org/articles/PMC12121212');
    expect($ref['is_open_access'])->toBeTrue();
});
