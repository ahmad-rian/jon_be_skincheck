import { Head } from '@inertiajs/react';
import { Moon, Sun } from 'lucide-react';
import { useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { useAppearance } from '@/hooks/use-appearance';

type Referensi = {
    no: number;
    title: string;
    authors: string;
    journal: string;
    pubdate: string;
    url: string;
    pmid: string | null;
    issn: string | null;
    is_open_access: boolean;
    source_db: string;
};

type ApiResponse = {
    status: boolean;
    penyakit: string;
    cf: string;
    artikel: string;
    referensi: Referensi[];
    jumlah_sumber: number;
    model: string;
    message?: string;
};

export default function SkinArticleTest() {
    const [penyakit, setPenyakit] = useState('');
    const [cf, setCf] = useState('85');
    const [loading, setLoading] = useState(false);
    const [result, setResult] = useState<ApiResponse | null>(null);
    const [error, setError] = useState('');
    const [responseTime, setResponseTime] = useState(0);
    const { resolvedAppearance, updateAppearance } = useAppearance();

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!penyakit.trim()) return;

        setLoading(true);
        setResult(null);
        setError('');

        const start = performance.now();

        try {
            const params = new URLSearchParams({ q: penyakit, cf });
            const response = await fetch(
                `/api/skin-article?${params.toString()}`,
            );
            const data: ApiResponse = await response.json();

            setResponseTime(Math.round(performance.now() - start));

            if (!response.ok || !data.status) {
                setError(data.message || 'Gagal mengambil artikel');
                return;
            }

            setResult(data);
        } catch {
            setError('Network error - pastikan server berjalan');
        } finally {
            setLoading(false);
        }
    };

    return (
        <>
            <Head title="Skin Article API Test" />
            <div className="min-h-screen bg-background">
                <header className="sticky top-0 z-10 border-b bg-background/80 backdrop-blur">
                    <div className="mx-auto flex h-16 max-w-4xl items-center justify-between gap-3 px-6">
                        <div className="flex items-center gap-3">
                            <div className="flex size-9 items-center justify-center rounded-lg bg-primary text-primary-foreground">
                                <AppLogoIcon className="size-5 fill-current" />
                            </div>
                            <div className="leading-tight">
                                <p className="text-sm font-semibold">
                                    SkinCheck AI
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Artikel Penyakit Kulit
                                </p>
                            </div>
                        </div>
                        <Button
                            variant="outline"
                            size="icon"
                            aria-label="Ganti tema"
                            onClick={() =>
                                updateAppearance(
                                    resolvedAppearance === 'dark'
                                        ? 'light'
                                        : 'dark',
                                )
                            }
                        >
                            {resolvedAppearance === 'dark' ? (
                                <Sun className="size-4" />
                            ) : (
                                <Moon className="size-4" />
                            )}
                        </Button>
                    </div>
                </header>

                <div className="mx-auto max-w-4xl space-y-6 p-6">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-bold tracking-tight">
                            Skin Article API Test
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Testing endpoint: GET /api/skin-article — sumber
                            dari PubMed, Europe PMC, DOAJ & Wikipedia Indonesia
                            + kesimpulan Groq AI
                        </p>
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle>Generate Artikel</CardTitle>
                            <CardDescription>
                                Masukkan nama penyakit kulit dan certainty
                                factor
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form
                                onSubmit={handleSubmit}
                                className="flex flex-col gap-4 sm:flex-row sm:items-end"
                            >
                                <div className="flex-1 space-y-2">
                                    <Label htmlFor="penyakit">
                                        Nama Penyakit
                                    </Label>
                                    <Input
                                        id="penyakit"
                                        value={penyakit}
                                        onChange={(e) =>
                                            setPenyakit(e.target.value)
                                        }
                                        placeholder="contoh: Eczema, Psoriasis, Acne Vulgaris"
                                        disabled={loading}
                                    />
                                </div>
                                <div className="w-full space-y-2 sm:w-24">
                                    <Label htmlFor="cf">CF (%)</Label>
                                    <Input
                                        id="cf"
                                        value={cf}
                                        onChange={(e) => setCf(e.target.value)}
                                        placeholder="85"
                                        disabled={loading}
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    disabled={loading || !penyakit.trim()}
                                >
                                    {loading ? (
                                        <>
                                            <Spinner /> Generating...
                                        </>
                                    ) : (
                                        'Generate'
                                    )}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>

                    {error && (
                        <Card className="border-destructive">
                            <CardContent className="pt-6">
                                <p className="text-sm font-medium text-destructive">
                                    {error}
                                </p>
                            </CardContent>
                        </Card>
                    )}

                    {loading && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center gap-3 py-12">
                                <Spinner className="size-6" />
                                <span className="text-sm text-muted-foreground">
                                    Mengambil artikel dari jurnal & web
                                    Indonesia, lalu menyimpulkan dengan Groq
                                    AI...
                                </span>
                            </CardContent>
                        </Card>
                    )}

                    {result && (
                        <>
                            <Card>
                                <CardHeader>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <CardTitle>{result.penyakit}</CardTitle>
                                        <Badge variant="secondary">
                                            CF: {result.cf}%
                                        </Badge>
                                        <Badge variant="outline">
                                            {result.jumlah_sumber} sumber
                                        </Badge>
                                        <Badge variant="outline">
                                            {result.model}
                                        </Badge>
                                        <Badge variant="outline">
                                            {responseTime}ms
                                        </Badge>
                                    </div>
                                    <CardDescription>
                                        Artikel disimpulkan dari{' '}
                                        {result.jumlah_sumber} sumber ilmiah &
                                        web Indonesia. Nomor dalam kurung siku
                                        [1], [2] merujuk ke referensi di bawah.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <div
                                        className="prose dark:prose-invert max-w-none"
                                        dangerouslySetInnerHTML={{
                                            __html: formatMarkdown(
                                                result.artikel,
                                            ),
                                        }}
                                    />
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>Referensi</CardTitle>
                                    <CardDescription>
                                        Sumber dari jurnal ilmiah (PubMed,
                                        Europe PMC, DOAJ) & Wikipedia Indonesia.
                                        Badge hijau = bisa langsung dibuka
                                        (akses terbuka). Klik untuk membaca.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-3">
                                        {result.referensi.map((ref) => (
                                            <a
                                                key={ref.no}
                                                href={ref.url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="block rounded-lg border p-4 transition-colors hover:bg-accent"
                                            >
                                                <div className="flex items-start gap-3">
                                                    <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground">
                                                        {ref.no}
                                                    </span>
                                                    <div className="min-w-0 flex-1 space-y-1">
                                                        <p className="text-sm leading-snug font-medium">
                                                            {ref.title}
                                                        </p>
                                                        <p className="text-xs text-muted-foreground">
                                                            {ref.authors}
                                                        </p>
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            {ref.is_open_access ? (
                                                                <Badge className="border-transparent bg-green-600 text-xs text-white hover:bg-green-600">
                                                                    Akses
                                                                    Terbuka
                                                                </Badge>
                                                            ) : (
                                                                <Badge
                                                                    variant="secondary"
                                                                    className="text-xs"
                                                                >
                                                                    Abstrak
                                                                </Badge>
                                                            )}
                                                            <Badge
                                                                variant="secondary"
                                                                className="text-xs"
                                                            >
                                                                {ref.source_db}
                                                            </Badge>
                                                            {ref.journal && (
                                                                <Badge
                                                                    variant="outline"
                                                                    className="text-xs"
                                                                >
                                                                    {
                                                                        ref.journal
                                                                    }
                                                                </Badge>
                                                            )}
                                                            {ref.pubdate && (
                                                                <Badge
                                                                    variant="outline"
                                                                    className="text-xs"
                                                                >
                                                                    {
                                                                        ref.pubdate
                                                                    }
                                                                </Badge>
                                                            )}
                                                            {ref.pmid && (
                                                                <Badge
                                                                    variant="outline"
                                                                    className="text-xs"
                                                                >
                                                                    PMID:{' '}
                                                                    {ref.pmid}
                                                                </Badge>
                                                            )}
                                                            {ref.issn && (
                                                                <Badge
                                                                    variant="outline"
                                                                    className="text-xs"
                                                                >
                                                                    ISSN:{' '}
                                                                    {ref.issn}
                                                                </Badge>
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>
                                            </a>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>Raw JSON Response</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <pre className="overflow-x-auto rounded-lg bg-muted p-4 text-xs">
                                        {JSON.stringify(result, null, 2)}
                                    </pre>
                                </CardContent>
                            </Card>
                        </>
                    )}
                </div>
            </div>
        </>
    );
}

function formatMarkdown(text: string): string {
    return text
        .replace(
            /^## (.+)$/gm,
            '<h2 class="mt-6 mb-2 text-lg font-semibold">$1</h2>',
        )
        .replace(
            /^### (.+)$/gm,
            '<h3 class="mt-4 mb-1 text-base font-semibold">$1</h3>',
        )
        .replace(
            /\[(\d+(?:,\s*\d+)*)\]/g,
            '<sup class="text-primary font-bold">[$1]</sup>',
        )
        .replace(/^- (.+)$/gm, '<li class="ml-4 list-disc">$1</li>')
        .replace(/\n\n/g, '</p><p class="mb-3">')
        .replace(/\n/g, '<br/>');
}
