import { Head } from '@inertiajs/react';
import {
    BookText,
    Check,
    ExternalLink,
    FileText,
    FlaskConical,
    Globe,
    Languages,
    Loader2,
    Microscope,
    Moon,
    Sparkles,
    Stethoscope,
    Sun,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Badge } from '@/components/ui/badge';
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
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';

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

const AI_STEPS = [
    { icon: Languages, label: 'Menerjemahkan istilah medis' },
    { icon: BookText, label: 'Mencari jurnal Indonesia (DOAJ)' },
    { icon: Globe, label: 'Menelusuri Wikipedia Indonesia' },
    { icon: Microscope, label: 'Mengambil PubMed & Europe PMC' },
    { icon: Sparkles, label: 'Menyusun artikel dengan Groq AI' },
];

const DATA_SOURCES = [
    { label: 'DOAJ', icon: BookText },
    { label: 'Wikipedia ID', icon: Globe },
    { label: 'PubMed', icon: Microscope },
    { label: 'Europe PMC', icon: FlaskConical },
    { label: 'Groq AI', icon: Sparkles },
];

/** Visual accent per reference source. */
function sourceStyle(sourceDb: string): {
    ring: string;
    badge: string;
    icon: typeof BookText;
} {
    if (sourceDb.startsWith('DOAJ')) {
        return {
            ring: 'border-l-rose-500',
            badge: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
            icon: BookText,
        };
    }
    if (sourceDb.startsWith('Wikipedia')) {
        return {
            ring: 'border-l-emerald-500',
            badge: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
            icon: Globe,
        };
    }
    if (sourceDb.startsWith('Europe')) {
        return {
            ring: 'border-l-violet-500',
            badge: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
            icon: FlaskConical,
        };
    }
    return {
        ring: 'border-l-blue-500',
        badge: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
        icon: Microscope,
    };
}

export default function SkinArticleTest() {
    const [penyakit, setPenyakit] = useState('');
    const [cf, setCf] = useState('85');
    const [loading, setLoading] = useState(false);
    const [result, setResult] = useState<ApiResponse | null>(null);
    const [error, setError] = useState('');
    const [responseTime, setResponseTime] = useState(0);
    const [step, setStep] = useState(0);
    const { resolvedAppearance, updateAppearance } = useAppearance();

    useEffect(() => {
        if (!loading) {
            setStep(0);
            return;
        }
        const id = setInterval(() => {
            setStep((s) => Math.min(s + 1, AI_STEPS.length - 1));
        }, 1600);
        return () => clearInterval(id);
    }, [loading]);

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

    const indonesianCount = result?.referensi.filter((r) =>
        ['DOAJ (Jurnal Indonesia)', 'Wikipedia Indonesia'].includes(
            r.source_db,
        ),
    ).length;

    return (
        <>
            <Head title="SkinCheck AI — Artikel Penyakit Kulit" />
            <div className="min-h-screen bg-background">
                <header className="sticky top-0 z-20 border-b bg-background/80 backdrop-blur">
                    <div className="mx-auto flex h-16 max-w-5xl items-center justify-between gap-3 px-4 sm:px-6">
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

                <main className="mx-auto max-w-5xl space-y-8 px-4 py-10 sm:px-6">
                    {/* Hero */}
                    <section className="relative overflow-hidden rounded-2xl border bg-gradient-to-br from-primary/10 via-background to-background p-8 sm:p-10">
                        <div className="pointer-events-none absolute -top-16 -right-16 size-56 rounded-full bg-primary/10 blur-3xl" />
                        <div className="relative space-y-4">
                            <Badge variant="secondary" className="gap-1.5">
                                <Stethoscope className="size-3.5" /> Edukasi
                                Kesehatan Kulit
                            </Badge>
                            <h1 className="max-w-2xl text-3xl font-bold tracking-tight sm:text-4xl">
                                Artikel penyakit kulit, dirangkum AI dari sumber
                                ilmiah tepercaya
                            </h1>
                            <p className="max-w-2xl text-muted-foreground">
                                Masukkan nama penyakit, sistem menarik referensi
                                dari jurnal Indonesia & internasional lalu
                                menyimpulkannya dengan Groq AI — lengkap dengan
                                sitasi yang bisa dibuka.
                            </p>
                            <div className="flex flex-wrap gap-2 pt-1">
                                {DATA_SOURCES.map((s) => (
                                    <span
                                        key={s.label}
                                        className="inline-flex items-center gap-1.5 rounded-full border bg-background/60 px-3 py-1 text-xs font-medium text-muted-foreground"
                                    >
                                        <s.icon className="size-3.5" />{' '}
                                        {s.label}
                                    </span>
                                ))}
                            </div>
                        </div>
                    </section>

                    {/* Form */}
                    <Card className="shadow-sm">
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
                                        placeholder="contoh: Skabies, Psoriasis, Acne Vulgaris"
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
                                    className="gap-2"
                                >
                                    {loading ? (
                                        <Loader2 className="size-4 animate-spin" />
                                    ) : (
                                        <Sparkles className="size-4" />
                                    )}
                                    {loading ? 'Memproses…' : 'Generate'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>

                    {error && (
                        <Card className="border-destructive/50 bg-destructive/5">
                            <CardContent className="pt-6">
                                <p className="text-sm font-medium text-destructive">
                                    {error}
                                </p>
                            </CardContent>
                        </Card>
                    )}

                    {loading && <AiThinking step={step} penyakit={penyakit} />}

                    {result && (
                        <div className="space-y-6">
                            {/* Article */}
                            <Card className="overflow-hidden shadow-sm">
                                <CardHeader className="border-b bg-muted/30">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <div className="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                            <Sparkles className="size-5" />
                                        </div>
                                        <CardTitle className="capitalize">
                                            {result.penyakit}
                                        </CardTitle>
                                        <Badge variant="secondary">
                                            CF {result.cf}%
                                        </Badge>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-x-4 gap-y-1 pt-1 text-xs text-muted-foreground">
                                        <span className="inline-flex items-center gap-1">
                                            <FileText className="size-3.5" />{' '}
                                            {result.jumlah_sumber} sumber
                                            {typeof indonesianCount ===
                                                'number' &&
                                                indonesianCount > 0 && (
                                                    <span className="text-emerald-600 dark:text-emerald-400">
                                                        {' '}
                                                        ({indonesianCount}{' '}
                                                        Indonesia)
                                                    </span>
                                                )}
                                        </span>
                                        <span className="inline-flex items-center gap-1">
                                            <Sparkles className="size-3.5" />{' '}
                                            {result.model}
                                        </span>
                                        <span>
                                            {(responseTime / 1000).toFixed(1)}s
                                        </span>
                                    </div>
                                </CardHeader>
                                <CardContent className="pt-6">
                                    <div
                                        className="prose prose-sm dark:prose-invert prose-headings:scroll-mt-20 max-w-none"
                                        dangerouslySetInnerHTML={{
                                            __html: formatMarkdown(
                                                result.artikel,
                                            ),
                                        }}
                                    />
                                </CardContent>
                            </Card>

                            {/* References */}
                            <Card className="shadow-sm">
                                <CardHeader>
                                    <CardTitle>Referensi</CardTitle>
                                    <CardDescription>
                                        Sumber jurnal Indonesia & internasional.
                                        Badge hijau = akses terbuka (langsung
                                        bisa dibuka). Klik untuk membaca.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        {result.referensi.map((ref) => {
                                            const style = sourceStyle(
                                                ref.source_db,
                                            );
                                            const Icon = style.icon;
                                            return (
                                                <a
                                                    key={ref.no}
                                                    href={ref.url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className={cn(
                                                        'group block rounded-lg border border-l-4 bg-card p-4 transition-all hover:shadow-md',
                                                        style.ring,
                                                    )}
                                                >
                                                    <div className="flex items-start gap-3">
                                                        <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-bold">
                                                            {ref.no}
                                                        </span>
                                                        <div className="min-w-0 flex-1 space-y-1.5">
                                                            <p className="text-sm leading-snug font-medium group-hover:underline">
                                                                {ref.title}
                                                                <ExternalLink className="ml-1 inline size-3 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
                                                            </p>
                                                            <p className="line-clamp-1 text-xs text-muted-foreground">
                                                                {ref.authors}
                                                            </p>
                                                            <div className="flex flex-wrap items-center gap-1.5 pt-0.5">
                                                                <span
                                                                    className={cn(
                                                                        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium',
                                                                        style.badge,
                                                                    )}
                                                                >
                                                                    <Icon className="size-3" />{' '}
                                                                    {
                                                                        ref.source_db
                                                                    }
                                                                </span>
                                                                {ref.is_open_access ? (
                                                                    <Badge className="border-transparent bg-green-600 text-[11px] text-white hover:bg-green-600">
                                                                        Akses
                                                                        Terbuka
                                                                    </Badge>
                                                                ) : (
                                                                    <Badge
                                                                        variant="secondary"
                                                                        className="text-[11px]"
                                                                    >
                                                                        Abstrak
                                                                    </Badge>
                                                                )}
                                                                {ref.pubdate && (
                                                                    <span className="text-[11px] text-muted-foreground">
                                                                        {
                                                                            ref.pubdate
                                                                        }
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </div>
                                                    </div>
                                                </a>
                                            );
                                        })}
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Raw JSON */}
                            <details className="rounded-lg border bg-muted/30">
                                <summary className="cursor-pointer px-4 py-3 text-sm font-medium text-muted-foreground">
                                    Lihat Raw JSON Response
                                </summary>
                                <pre className="overflow-x-auto border-t bg-muted p-4 text-xs">
                                    {JSON.stringify(result, null, 2)}
                                </pre>
                            </details>
                        </div>
                    )}
                </main>
            </div>
        </>
    );
}

/** AI-style staged loading: shows what the AI is doing step by step. */
function AiThinking({ step, penyakit }: { step: number; penyakit: string }) {
    const progress = ((step + 1) / AI_STEPS.length) * 100;

    return (
        <Card className="overflow-hidden shadow-sm">
            <CardHeader className="border-b bg-muted/30">
                <div className="flex items-center gap-3">
                    <span className="relative flex size-10 items-center justify-center rounded-full bg-primary/10">
                        <span className="absolute inline-flex size-full animate-ping rounded-full bg-primary/20" />
                        <Sparkles className="size-5 text-primary" />
                    </span>
                    <div>
                        <CardTitle className="text-base">
                            AI sedang menganalisis…
                        </CardTitle>
                        <CardDescription>
                            Merangkum artikel
                            {penyakit ? ` untuk "${penyakit}"` : ''} dari sumber
                            tepercaya
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent className="space-y-4 pt-6">
                {/* progress bar */}
                <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                    <div
                        className="h-full rounded-full bg-primary transition-all duration-700 ease-out"
                        style={{ width: `${progress}%` }}
                    />
                </div>

                {/* steps */}
                <ul className="space-y-2.5">
                    {AI_STEPS.map((s, i) => {
                        const done = i < step;
                        const active = i === step;
                        const Icon = s.icon;
                        return (
                            <li
                                key={s.label}
                                className={cn(
                                    'flex items-center gap-3 rounded-lg px-3 py-2 transition-colors',
                                    active && 'bg-primary/5',
                                )}
                            >
                                <span
                                    className={cn(
                                        'flex size-7 shrink-0 items-center justify-center rounded-full border',
                                        done &&
                                            'border-green-600 bg-green-600 text-white',
                                        active && 'border-primary text-primary',
                                        !done &&
                                            !active &&
                                            'border-muted-foreground/20 text-muted-foreground/40',
                                    )}
                                >
                                    {done ? (
                                        <Check className="size-4" />
                                    ) : active ? (
                                        <Loader2 className="size-4 animate-spin" />
                                    ) : (
                                        <Icon className="size-4" />
                                    )}
                                </span>
                                <span
                                    className={cn(
                                        'text-sm',
                                        done &&
                                            'text-muted-foreground line-through decoration-muted-foreground/40',
                                        active && 'font-medium text-foreground',
                                        !done &&
                                            !active &&
                                            'text-muted-foreground/50',
                                    )}
                                >
                                    {s.label}
                                </span>
                                {active && (
                                    <span className="ml-auto flex gap-1">
                                        <span className="size-1.5 animate-bounce rounded-full bg-primary [animation-delay:-0.3s]" />
                                        <span className="size-1.5 animate-bounce rounded-full bg-primary [animation-delay:-0.15s]" />
                                        <span className="size-1.5 animate-bounce rounded-full bg-primary" />
                                    </span>
                                )}
                            </li>
                        );
                    })}
                </ul>
            </CardContent>
        </Card>
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
