# API Skin Article — Dokumentasi

## Endpoint

```
GET /api/skin-article
```

## Deskripsi

API untuk menghasilkan artikel edukasi kesehatan kulit berdasarkan jurnal ilmiah dari **PubMed (National Library of Medicine)**. Artikel disimpulkan oleh **Groq AI** dari abstrak jurnal asli, bukan hasil generate murni AI.

### Flow

```
Request → PubMed E-utilities API → Ambil 5 jurnal ilmiah → Groq AI menyimpulkan → Response
```

1. Cari artikel relevan di PubMed berdasarkan nama penyakit
2. Ambil detail jurnal (judul, penulis, abstrak, journal name)
3. Kirim semua abstrak ke Groq AI sebagai konteks
4. AI menyimpulkan artikel dalam Bahasa Indonesia dengan nomor rujukan `[1]`, `[2]`
5. Return artikel + referensi jurnal asli dengan link PubMed valid

---

## Parameter

| Parameter | Tipe   | Wajib | Deskripsi                          |
|-----------|--------|-------|------------------------------------|
| `q`       | string | Ya    | Nama penyakit kulit (maks 255 karakter) |
| `cf`      | string | Tidak | Nilai Certainty Factor dalam persen |

## Contoh Request

```bash
# Dasar
curl "https://yourdomain.com/api/skin-article?q=Eczema&cf=85"

# Dengan penyakit spesifik
curl "https://yourdomain.com/api/skin-article?q=Psoriasis&cf=70"

# Dengan penyakit Indonesia
curl "https://yourdomain.com/api/skin-article?q=Dermatitis%20Atopik&cf=90"
```

## Response Sukses (200)

```json
{
    "status": true,
    "penyakit": "Eczema",
    "cf": "85",
    "artikel": "## Tentang Penyakit\nEczema adalah sebuah penyakit kulit... [1]. ... [2,3]\n\n## Gejala\n- Gatal [1, 2]\n- Nyeri [1]\n...",
    "referensi": [
        {
            "no": 1,
            "title": "Hand eczema.",
            "authors": "Weidinger S, Novak N",
            "journal": "Lancet (London, England)",
            "pubdate": "2024 Dec 14",
            "url": "https://pubmed.ncbi.nlm.nih.gov/39615508/",
            "pmid": "39615508"
        },
        {
            "no": 2,
            "title": "ETFAD/EADV Eczema task force 2020 position paper...",
            "authors": "Wollenberg A, Christen-Zäch S, Taieb A, et al.",
            "journal": "Journal of the European Academy of Dermatology and Venereology",
            "pubdate": "2020 Dec",
            "url": "https://pubmed.ncbi.nlm.nih.gov/33205485/",
            "pmid": "33205485"
        }
    ],
    "jumlah_sumber": 5,
    "model": "llama-3.3-70b-versatile"
}
```

## Response Error

### Validasi gagal (422)

```json
{
    "message": "The q field is required.",
    "errors": {
        "q": ["The q field is required."]
    }
}
```

### Tidak ada sumber ditemukan (404)

```json
{
    "status": false,
    "message": "Tidak ada sumber medis ditemukan di PubMed untuk: xyznotadisease"
}
```

### Groq AI gagal (502)

```json
{
    "status": false,
    "message": "Gagal mengambil artikel dari AI",
    "debug": { "error": "rate limited" }
}
```

---

## Format Artikel

Artikel menggunakan format **Markdown** dengan struktur:

```markdown
## Tentang Penyakit
(paragraf dengan rujukan [1], [2])

## Gejala
- Gejala 1 [1, 2]
- Gejala 2 [3]

## Penyebab
- Penyebab 1 [1]
- Penyebab 2 [2, 4]

## Cara Mengatasi
- Pengobatan 1 [1, 5]
- Pengobatan 2 [3]

## Kapan Harus ke Dokter
(paragraf dengan rujukan)
```

Nomor dalam kurung siku `[1]`, `[2]` merujuk ke array `referensi` dalam response.

---

## Konfigurasi Environment

Tambahkan ke file `.env`:

```env
GROQ_API_KEY=gsk_xxxxxxxxxxxxxxxxxxxxx
GROQ_MODEL=llama-3.3-70b-versatile
```

| Variable       | Deskripsi                           | Default                    |
|----------------|-------------------------------------|----------------------------|
| `GROQ_API_KEY` | API key dari https://console.groq.com | (wajib diisi)            |
| `GROQ_MODEL`   | Model Groq yang digunakan           | `llama-3.3-70b-versatile` |

---

## Sumber Data

- **PubMed E-utilities API** — gratis, tanpa API key, dari National Library of Medicine (NIH)
- **Groq AI** — untuk menyimpulkan abstrak jurnal menjadi artikel Bahasa Indonesia
- Model: `llama-3.3-70b-versatile` (gratis, cepat)

## Rate Limits

- **PubMed**: 3 requests/detik tanpa API key, 10 requests/detik dengan API key (opsional)
- **Groq Free Tier**: 30 requests/menit, 14,400 requests/hari
