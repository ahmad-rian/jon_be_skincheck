<?php

use Illuminate\Support\Facades\Http;

test('skin article requires q parameter', function () {
    $response = $this->getJson('/api/skin-article');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['q']);
});

test('skin article fetches pubmed sources and returns article from groq', function () {
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
            'jumlah_sumber' => 2,
        ])
        ->assertJsonStructure([
            'status',
            'penyakit',
            'cf',
            'artikel',
            'referensi' => [
                '*' => ['no', 'title', 'authors', 'journal', 'pubdate', 'url', 'pmid'],
            ],
            'jumlah_sumber',
            'model',
        ]);

    $referensi = $response->json('referensi');
    expect($referensi[0]['url'])->toContain('pubmed.ncbi.nlm.nih.gov/12345678');
    expect($referensi[0]['pmid'])->toBe('12345678');
});

test('skin article returns 404 when no pubmed results found', function () {
    Http::fake([
        'eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi*' => Http::response([
            'esearchresult' => [
                'idlist' => [],
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
        'api.groq.com/*' => Http::response(['error' => 'rate limited'], 429),
    ]);

    $response = $this->getJson('/api/skin-article?q=psoriasis&cf=70');

    $response->assertStatus(502)
        ->assertJson(['status' => false]);
});
