import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowUpRight,
    Check,
    CheckCircle2,
    Coins,
    Copy,
    CreditCard,
    ExternalLink,
    Eye,
    EyeOff,
    Flame,
    Layers,
    QrCode,
    Radio,
    ReceiptText,
    RefreshCw,
    Scan,
    ShieldCheck,
    Sparkles,
    TriangleAlert,
    Wallet,
    Zap,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { show as controlledWallet } from '@/actions/App/Http/Controllers/StudentControlledWalletController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as paymentsIndex } from '@/routes/payments';
import { refresh as walletRefresh } from '@/routes/student/wallet';
import { update as walletUpdate } from '@/routes/wallet';
import type { TuitionAttempt } from '@/types/financial-aid';

type WalletState = 'ok' | 'missing' | 'invalid' | 'unavailable';

interface WalletSnapshot {
    address: string | null;
    balance: string | null;
    chain: string;
    explorer_url: string | null;
    is_fake: boolean;
    is_available: boolean;
    state: WalletState;
    reason: string | null;
    cached_at: string | null;
}

interface WalletProps {
    snapshot: WalletSnapshot;
    tuitionAttempts?: TuitionAttempt[];
    walletLocked?: boolean;
}

function formatBaseUnits(baseUnits: string | number): string {
    const units = BigInt(baseUnits);
    return `${(units / 1_000_000n).toLocaleString('en-US')}.${(units % 1_000_000n).toString().padStart(6, '0')}`;
}

function shortAddress(address: string): string {
    return `${address.slice(0, 6)}…${address.slice(-4)}`;
}

function formatBalance(snapshot: WalletSnapshot): string {
    if (!snapshot.is_available || snapshot.balance === null) {
        return '—';
    }

    const [whole, fraction = ''] = snapshot.balance.split('.');
    const trimmed = fraction.replace(/0+$/, '').slice(0, 2).padEnd(2, '0');

    return `${Number(whole).toLocaleString('en-US')}.${trimmed}`;
}

export default function StudentWallet({
    snapshot: initialSnapshot,
    walletLocked = false,
    tuitionAttempts = [],
}: WalletProps) {
    const [snapshot, setSnapshot] = useState(initialSnapshot);
    const [refreshing, setRefreshing] = useState(false);
    const [copied, setCopied] = useState(false);
    const [showPrivacy, setShowPrivacy] = useState(false);
    const [activeTab, setActiveTab] = useState<'card' | 'qr' | 'specs'>('card');
    const [filterAttempts, setFilterAttempts] = useState<
        'all' | 'settled' | 'pending'
    >('all');
    const [showFiatEstimate, setShowFiatEstimate] = useState(false);

    useEffect(() => {
        setSnapshot(initialSnapshot);
    }, [initialSnapshot]);

    const hasAddress = Boolean(snapshot.address);

    const { data, setData, patch, processing, errors, reset } = useForm({
        wallet_address: snapshot.address ?? '',
    });

    const isEditing = data.wallet_address !== (snapshot.address ?? '');

    const copyAddress = async () => {
        if (!snapshot.address) return;
        try {
            await navigator.clipboard.writeText(snapshot.address);
            setCopied(true);
            toast.success('Wallet address copied to clipboard');
            setTimeout(() => setCopied(false), 2000);
        } catch {
            toast.error(
                'Could not copy automatically. Please select and copy manually.',
            );
        }
    };

    const pasteFromClipboard = async () => {
        try {
            const text = await navigator.clipboard.readText();
            if (text.startsWith('0x') && text.length === 42) {
                setData('wallet_address', text.trim());
                toast.success('Address pasted from clipboard');
            } else {
                toast.error(
                    'Clipboard content does not match a 42-character 0x address.',
                );
            }
        } catch {
            toast.error('Clipboard read permission denied.');
        }
    };

    const refreshBalance = async () => {
        setRefreshing(true);
        try {
            const csrf = document.cookie
                .split('; ')
                .find((c) => c.startsWith('XSRF-TOKEN='))
                ?.split('=')
                .slice(1)
                .join('=');

            const response = await fetch(walletRefresh.url(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(csrf ?? ''),
                },
            });

            if (!response.ok)
                throw new Error('Could not refresh wallet balance.');

            const result = (await response.json()) as {
                snapshot: WalletSnapshot;
            };
            setSnapshot(result.snapshot);

            if (result.snapshot.is_available) {
                toast.success(
                    'Live on-chain balance synchronized from Arc Testnet.',
                );
            } else {
                toast.error(
                    result.snapshot.reason ??
                        'Live balance is currently unavailable.',
                );
            }
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Could not refresh wallet balance.',
            );
        } finally {
            setRefreshing(false);
        }
    };

    const saveWallet = (e: React.FormEvent) => {
        e.preventDefault();
        patch(walletUpdate.url(), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Payout destination updated', {
                    description:
                        'Funds sent to this address cannot be recovered if incorrect.',
                });
            },
            onError: () => {
                toast.error('That wallet address was not accepted.');
            },
        });
    };

    // Derived values
    const balanceNum = parseFloat(snapshot.balance ?? '0');
    const fiatEstimate = (balanceNum * 58.5).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    const filteredAttempts = useMemo(() => {
        if (filterAttempts === 'settled')
            return tuitionAttempts.filter((a) => a.posted);
        if (filterAttempts === 'pending')
            return tuitionAttempts.filter((a) => !a.posted);
        return tuitionAttempts;
    }, [tuitionAttempts, filterAttempts]);

    return (
        <>
            <Head title="My Wallet" />

            <div className="bulletin clay-ambient min-h-screen w-full bg-[#0b0a09] text-[#eae4d6] antialiased selection:bg-[#e9dfc7] selection:text-[#191410]">
                {/* Background Ambient Glows */}
                <div
                    aria-hidden="true"
                    className="pointer-events-none fixed inset-0 overflow-hidden"
                >
                    <div className="absolute -top-40 left-1/4 h-96 w-96 rounded-full bg-[#e9dfc7]/5 blur-3xl" />
                    <div className="absolute top-1/2 -right-32 h-80 w-80 rounded-full bg-[#4caf7d]/5 blur-3xl" />
                </div>

                <div className="relative mx-auto flex w-full max-w-5xl flex-col gap-8 px-4 py-8 sm:px-6 lg:px-8">
                    {/* Top App Header */}
                    <header className="flex flex-col gap-4 border-b border-[#36312b] pb-6 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div className="flex items-center gap-2">
                                <span className="flex items-center gap-1.5 rounded-full border border-[#4caf7d]/30 bg-[#4caf7d]/10 px-2.5 py-0.5 text-[11px] font-semibold tracking-wide text-[#4caf7d]">
                                    <Radio className="size-2.5 animate-pulse" />
                                    Arc Testnet Active
                                </span>
                                <span className="rounded-full border border-[#36312b] bg-[#141210] px-2.5 py-0.5 font-mono text-[11px] text-[#a8a192]">
                                    5042002
                                </span>
                            </div>
                            <h1 className="mt-2 font-serif text-3xl font-bold tracking-tight text-[#f6f1e6] sm:text-4xl">
                                Your wallet.
                            </h1>
                            <p className="mt-1 text-sm text-[#a8a192]">
                                Real-time sovereign USDC reserves and
                                verification telemetry.
                            </p>
                        </div>

                        <div className="flex items-center gap-2 self-start sm:self-auto">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={refreshBalance}
                                disabled={!hasAddress || refreshing}
                                className="h-9 rounded-full border-[#36312b] bg-[#141210] px-3.5 text-xs font-semibold text-[#eae4d6] hover:border-[#e9dfc7] hover:bg-[#1b1815]"
                            >
                                <RefreshCw
                                    className={`mr-1.5 size-3.5 text-[#e9dfc7] ${refreshing ? 'animate-spin' : ''}`}
                                />
                                {refreshing ? 'Syncing…' : 'Sync Chain'}
                            </Button>

                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setShowPrivacy(!showPrivacy)}
                                className="h-9 rounded-full border-[#36312b] bg-[#141210] px-3 text-xs font-semibold text-[#a8a192] hover:text-[#f6f1e6]"
                                title={
                                    showPrivacy
                                        ? 'Reveal balance'
                                        : 'Mask balance'
                                }
                            >
                                {showPrivacy ? (
                                    <EyeOff className="size-3.5" />
                                ) : (
                                    <Eye className="size-3.5" />
                                )}
                            </Button>
                        </div>
                    </header>

                    {/* Interactive 3D Cyber-Pass Card & Controls Section */}
                    <div className="grid gap-6 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,0.9fr)]">
                        {/* The Tactile Smart Card */}
                        <div className="group relative flex flex-col justify-between overflow-hidden rounded-3xl border border-[#36312b] bg-gradient-to-br from-[#1b1815] via-[#141210] to-[#0d0b0a] p-6 shadow-2xl transition-all duration-300 hover:border-[#e9dfc7]/40 sm:p-8">
                            {/* Card Decorative Sheen & Geometry */}
                            <div
                                aria-hidden="true"
                                className="pointer-events-none absolute -right-20 -top-20 size-72 rounded-full bg-radial from-[#e9dfc7]/10 via-transparent to-transparent opacity-60 transition-transform duration-700 group-hover:scale-125"
                            />
                            <div
                                aria-hidden="true"
                                className="pointer-events-none absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r from-transparent via-[#e9dfc7]/30 to-transparent"
                            />

                            {/* Card Top: Mode Switcher + Chip */}
                            <div className="relative flex items-center justify-between border-b border-[#36312b]/60 pb-5">
                                <div className="flex items-center gap-3">
                                    {/* Holographic Chip Icon */}
                                    <div className="flex size-9 items-center justify-center rounded-xl border border-[#d6c49a]/40 bg-gradient-to-br from-[#d6c49a]/30 to-[#2b261c] shadow-inner">
                                        <Zap className="size-4 text-[#e9dfc7]" />
                                    </div>
                                    <div>
                                        <p className="text-[10px] font-bold tracking-[0.2em] text-[#a8a192] uppercase">
                                            Student Smart Pass
                                        </p>
                                        <p className="font-mono text-xs font-semibold text-[#f6f1e6]">
                                            Arc Native USDC
                                        </p>
                                    </div>
                                </div>

                                {/* Card Tabs */}
                                <div className="flex items-center gap-1 rounded-full border border-[#36312b] bg-[#0b0a09]/80 p-1">
                                    <button
                                        type="button"
                                        onClick={() => setActiveTab('card')}
                                        className={`flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold transition-all ${
                                            activeTab === 'card'
                                                ? 'bg-[#e9dfc7] text-[#191410] shadow-sm'
                                                : 'text-[#a8a192] hover:text-[#eae4d6]'
                                        }`}
                                    >
                                        <CreditCard className="size-3" />
                                        Card
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setActiveTab('qr')}
                                        className={`flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold transition-all ${
                                            activeTab === 'qr'
                                                ? 'bg-[#e9dfc7] text-[#191410] shadow-sm'
                                                : 'text-[#a8a192] hover:text-[#eae4d6]'
                                        }`}
                                    >
                                        <QrCode className="size-3" />
                                        QR
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setActiveTab('specs')}
                                        className={`flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold transition-all ${
                                            activeTab === 'specs'
                                                ? 'bg-[#e9dfc7] text-[#191410] shadow-sm'
                                                : 'text-[#a8a192] hover:text-[#eae4d6]'
                                        }`}
                                    >
                                        <Scan className="size-3" />
                                        Node
                                    </button>
                                </div>
                            </div>

                            {/* Card Body - Content changes with interactive tab */}
                            {activeTab === 'card' && (
                                <div className="relative py-6">
                                    <div className="flex items-baseline justify-between">
                                        <span className="text-xs font-semibold tracking-wider text-[#a8a192] uppercase">
                                            Liquid Vault
                                        </span>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setShowFiatEstimate(
                                                    !showFiatEstimate,
                                                )
                                            }
                                            className="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-[11px] text-[#e9dfc7] transition hover:bg-[#36312b]/40"
                                            title="Click to toggle between USDC and PHP"
                                        >
                                            <Coins className="size-3 text-[#d6c49a]" />
                                            {showFiatEstimate
                                                ? 'Show USDC'
                                                : '≈ PHP Estimate'}
                                        </button>
                                    </div>

                                    {/* Giant Numbers with Flip Indicator */}
                                    <div className="mt-2">
                                        {showPrivacy ? (
                                            <p className="font-mono text-5xl font-extrabold tracking-tight text-[#f6f1e6] sm:text-6xl">
                                                ••••••••
                                            </p>
                                        ) : showFiatEstimate ? (
                                            <div>
                                                <p className="font-mono text-5xl font-extrabold tracking-tight text-[#f6f1e6] tabular-nums sm:text-6xl">
                                                    ₱{fiatEstimate}
                                                </p>
                                                <p className="mt-1 font-mono text-xs text-[#a8a192]">
                                                    Estimated at 1 USDC ≈ 58.50
                                                    PHP
                                                </p>
                                            </div>
                                        ) : (
                                            <div>
                                                <p className="font-mono text-5xl font-extrabold tracking-tight text-[#f6f1e6] tabular-nums sm:text-6xl">
                                                    {formatBalance(snapshot)}
                                                </p>
                                                <p className="mt-1 flex items-center gap-2 font-mono text-xs text-[#a8a192]">
                                                    <span className="font-bold text-[#e9dfc7]">
                                                        USDC
                                                    </span>
                                                    <span>&middot;</span>
                                                    <span>
                                                        On-chain testnet
                                                        allocation
                                                    </span>
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}

                            {activeTab === 'qr' && (
                                <div className="relative flex flex-col items-center justify-center py-4 text-center">
                                    {hasAddress ? (
                                        <div className="flex flex-col items-center gap-3">
                                            {/* Stylized QR Matrix Frame */}
                                            <div className="relative rounded-2xl border-2 border-[#e9dfc7]/40 bg-[#f6f1e6] p-3 shadow-lg">
                                                <div className="flex size-36 items-center justify-center rounded-lg border border-black/10 bg-white p-2">
                                                    <svg
                                                        viewBox="0 0 100 100"
                                                        className="size-full text-[#191410]"
                                                    >
                                                        {/* Decorative QR-like visual SVG */}
                                                        <rect
                                                            width="100"
                                                            height="100"
                                                            fill="white"
                                                        />
                                                        <rect
                                                            x="10"
                                                            y="10"
                                                            width="25"
                                                            height="25"
                                                            fill="black"
                                                        />
                                                        <rect
                                                            x="15"
                                                            y="15"
                                                            width="15"
                                                            height="15"
                                                            fill="white"
                                                        />
                                                        <rect
                                                            x="18"
                                                            y="18"
                                                            width="9"
                                                            height="9"
                                                            fill="black"
                                                        />
                                                        <rect
                                                            x="65"
                                                            y="10"
                                                            width="25"
                                                            height="25"
                                                            fill="black"
                                                        />
                                                        <rect
                                                            x="70"
                                                            y="15"
                                                            width="15"
                                                            height="15"
                                                            fill="white"
                                                        />
                                                        <rect
                                                            x="73"
                                                            y="18"
                                                            width="9"
                                                            height="9"
                                                            fill="black"
                                                        />
                                                        <rect
                                                            x="10"
                                                            y="65"
                                                            width="25"
                                                            height="25"
                                                            fill="black"
                                                        />
                                                        <rect
                                                            x="15"
                                                            y="70"
                                                            width="15"
                                                            height="15"
                                                            fill="white"
                                                        />
                                                        <rect
                                                            x="18"
                                                            y="73"
                                                            width="9"
                                                            height="9"
                                                            fill="black"
                                                        />
                                                        <rect
                                                            x="45"
                                                            y="15"
                                                            width="10"
                                                            height="10"
                                                            fill="black"
                                                        />
                                                        <rect
                                                            x="40"
                                                            y="35"
                                                            width="20"
                                                            height="10"
                                                            fill="black"
                                                        />
                                                        <rect
                                                            x="65"
                                                            y="45"
                                                            width="15"
                                                            height="10"
                                                            fill="black"
                                                        />
                                                        <rect
                                                            x="45"
                                                            y="65"
                                                            width="10"
                                                            height="20"
                                                            fill="black"
                                                        />
                                                        <rect
                                                            x="65"
                                                            y="70"
                                                            width="20"
                                                            height="15"
                                                            fill="black"
                                                        />
                                                    </svg>
                                                </div>
                                            </div>
                                            <p className="font-mono text-xs text-[#f6f1e6]">
                                                {shortAddress(
                                                    snapshot.address!,
                                                )}
                                            </p>
                                        </div>
                                    ) : (
                                        <div className="py-8 text-sm text-[#a8a192]">
                                            Connect wallet to generate QR
                                            receiving code.
                                        </div>
                                    )}
                                </div>
                            )}

                            {activeTab === 'specs' && (
                                <div className="relative space-y-2.5 py-4 text-xs font-mono">
                                    <div className="flex items-center justify-between rounded-lg border border-[#36312b] bg-[#0b0a09]/50 p-2.5">
                                        <span className="text-[#a8a192]">
                                            Network / Code
                                        </span>
                                        <span className="font-bold text-[#f6f1e6]">
                                            {snapshot.chain}
                                        </span>
                                    </div>
                                    <div className="flex items-center justify-between rounded-lg border border-[#36312b] bg-[#0b0a09]/50 p-2.5">
                                        <span className="text-[#a8a192]">
                                            Settlement Standard
                                        </span>
                                        <span className="text-[#4caf7d]">
                                            Instant Finality (&lt;500ms)
                                        </span>
                                    </div>
                                    <div className="flex items-center justify-between rounded-lg border border-[#36312b] bg-[#0b0a09]/50 p-2.5">
                                        <span className="text-[#a8a192]">
                                            Gas Settlement
                                        </span>
                                        <span className="text-[#d6c49a]">
                                            Native USDC Base
                                        </span>
                                    </div>
                                </div>
                            )}

                            {/* Card Bottom: Address Bar with Instant Tactile Copy */}
                            <div className="relative border-t border-[#36312b]/60 pt-4">
                                {hasAddress ? (
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div className="min-w-0">
                                            <p className="text-[10px] font-semibold text-[#a8a192] uppercase">
                                                Active Address
                                            </p>
                                            <code className="break-all font-mono text-xs font-bold text-[#f6f1e6]">
                                                {snapshot.address}
                                            </code>
                                        </div>
                                        <div className="flex items-center gap-1.5 shrink-0">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={copyAddress}
                                                className={`h-8 rounded-full border-[#36312b] px-3 text-xs font-semibold transition-all ${
                                                    copied
                                                        ? 'border-[#4caf7d] bg-[#4caf7d]/20 text-[#4caf7d]'
                                                        : 'bg-[#141210] text-[#eae4d6] hover:border-[#e9dfc7]'
                                                }`}
                                            >
                                                {copied ? (
                                                    <>
                                                        <Check className="mr-1 size-3" />
                                                        Copied
                                                    </>
                                                ) : (
                                                    <>
                                                        <Copy className="mr-1 size-3" />
                                                        Copy
                                                    </>
                                                )}
                                            </Button>

                                            {snapshot.explorer_url && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    asChild
                                                    className="h-8 rounded-full px-2.5 text-xs text-[#a8a192] hover:text-[#f6f1e6]"
                                                >
                                                    <a
                                                        href={
                                                            snapshot.explorer_url
                                                        }
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        title="View on ArcScan Explorer"
                                                    >
                                                        <ExternalLink className="size-3.5" />
                                                    </a>
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                ) : (
                                    <div className="flex items-center justify-between">
                                        <p className="text-xs text-[#a8a192]">
                                            No wallet connected
                                        </p>
                                        <Button
                                            size="sm"
                                            asChild
                                            className="rounded-full bg-[#e9dfc7] text-xs font-bold text-[#191410]"
                                        >
                                            <Link href={controlledWallet.url()}>
                                                Connect now
                                            </Link>
                                        </Button>
                                    </div>
                                )}
                            </div>

                            {/* Error Warning banner if chain unreadable */}
                            {snapshot.reason && snapshot.state !== 'ok' && (
                                <div className="mt-4 flex items-center gap-2 rounded-xl border border-amber-500/30 bg-amber-500/10 p-2.5 text-xs text-amber-300">
                                    <TriangleAlert className="size-4 shrink-0" />
                                    <span>{snapshot.reason}</span>
                                </div>
                            )}
                        </div>

                        {/* Interactive Launchpad / Action Console */}
                        <div className="flex flex-col gap-4">
                            {/* Mission Tile: Connect or Switch */}
                            <div className="relative flex flex-col justify-between overflow-hidden rounded-3xl border border-[#36312b] bg-[#141210] p-6 transition-all hover:border-[#e9dfc7]/40">
                                <div>
                                    <div className="flex items-center justify-between">
                                        <Badge
                                            variant="outline"
                                            className={`text-[11px] font-semibold ${
                                                walletLocked
                                                    ? 'border-[#4caf7d]/30 bg-[#4caf7d]/10 text-[#4caf7d]'
                                                    : 'border-[#e9dfc7]/30 bg-[#e9dfc7]/10 text-[#e9dfc7]'
                                            }`}
                                        >
                                            {walletLocked
                                                ? 'Session Secured'
                                                : 'Setup Required'}
                                        </Badge>
                                        <Sparkles className="size-4 text-[#e9dfc7]" />
                                    </div>
                                    <h2 className="mt-3 font-serif text-2xl font-bold text-[#f6f1e6]">
                                        {walletLocked
                                            ? 'Connected & Verified.'
                                            : 'Make it yours.'}
                                    </h2>
                                    <p className="mt-2 text-xs leading-relaxed text-[#a8a192]">
                                        {walletLocked
                                            ? 'Your key controls this account. Approve tuition and bill payments on-demand.'
                                            : 'Connect a browser wallet or use Circle. Verify ownership before paying.'}
                                    </p>
                                </div>

                                <div className="mt-6">
                                    <Button
                                        asChild
                                        data-test="wallet-connection-entry"
                                        className="h-10 w-full rounded-full bg-[#e9dfc7] font-semibold text-[#191410] shadow-md transition hover:bg-[#f5edd9]"
                                    >
                                        <Link href={controlledWallet.url()}>
                                            {walletLocked
                                                ? 'Manage connection'
                                                : 'Connect wallet'}
                                            <ArrowUpRight className="ml-1.5 size-4" />
                                        </Link>
                                    </Button>
                                </div>
                            </div>

                            {/* Secondary Action Launchers */}
                            <div className="grid grid-cols-2 gap-3">
                                {/* Faucet Shortcut */}
                                <a
                                    href="https://faucet.circle.com"
                                    target="_blank"
                                    rel="noreferrer"
                                    className="group flex flex-col justify-between rounded-2xl border border-[#36312b] bg-[#100e0c] p-4 transition-all hover:border-[#4caf7d]/40 hover:bg-[#141210]"
                                >
                                    <div className="flex items-center justify-between">
                                        <span className="flex size-7 items-center justify-center rounded-lg bg-[#4caf7d]/10 text-[#4caf7d]">
                                            <Flame className="size-3.5" />
                                        </span>
                                        <ExternalLink className="size-3 text-[#a8a192] transition group-hover:text-[#f6f1e6]" />
                                    </div>
                                    <div className="mt-3">
                                        <p className="text-xs font-bold text-[#f6f1e6]">
                                            Circle Faucet
                                        </p>
                                        <p className="text-[10px] text-[#a8a192]">
                                            Claim 20 testnet USDC
                                        </p>
                                    </div>
                                </a>

                                {/* Pay Tuition / Bills Shortcut */}
                                <Link
                                    href="/student/pay"
                                    className="group flex flex-col justify-between rounded-2xl border border-[#36312b] bg-[#100e0c] p-4 transition-all hover:border-[#e9dfc7]/40 hover:bg-[#141210]"
                                >
                                    <div className="flex items-center justify-between">
                                        <span className="flex size-7 items-center justify-center rounded-lg bg-[#e9dfc7]/10 text-[#e9dfc7]">
                                            <ArrowUpRight className="size-3.5" />
                                        </span>
                                        <Layers className="size-3 text-[#a8a192] transition group-hover:text-[#f6f1e6]" />
                                    </div>
                                    <div className="mt-3">
                                        <p className="text-xs font-bold text-[#f6f1e6]">
                                            Pay Bills
                                        </p>
                                        <p className="text-[10px] text-[#a8a192]">
                                            Settle tuition online
                                        </p>
                                    </div>
                                </Link>
                            </div>

                            {/* Receipts / History Link */}
                            <Link
                                href={paymentsIndex.url()}
                                className="flex items-center justify-between rounded-2xl border border-[#36312b] bg-[#100e0c] p-4 text-xs font-semibold text-[#eae4d6] transition hover:border-[#e9dfc7] hover:bg-[#141210]"
                            >
                                <span className="flex items-center gap-2">
                                    <ReceiptText className="size-4 text-[#d6c49a]" />
                                    <span>
                                        View Official Receipts & History
                                    </span>
                                </span>
                                <ArrowUpRight className="size-3.5 text-[#a8a192]" />
                            </Link>
                        </div>
                    </div>

                    {/* Lower Section: Transfer Journal & Payout Editor */}
                    <div className="grid gap-6 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,0.9fr)]">
                        {/* Transfer Journal with Interactive Filters */}
                        <section
                            className="flex flex-col rounded-3xl border border-[#36312b] bg-[#141210] p-6 sm:p-7"
                            aria-labelledby="tuition-attempts-heading"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[#36312b] pb-4">
                                <div>
                                    <p className="bulletin-eyebrow">
                                        Audit Journal
                                    </p>
                                    <h2
                                        id="tuition-attempts-heading"
                                        className="mt-1 font-serif text-xl font-bold text-[#f6f1e6]"
                                    >
                                        Transfer journal
                                    </h2>
                                </div>

                                <div className="flex items-center gap-1 rounded-full border border-[#36312b] bg-[#0b0a09] p-1">
                                    {(
                                        ['all', 'settled', 'pending'] as const
                                    ).map((filter) => (
                                        <button
                                            key={filter}
                                            type="button"
                                            onClick={() =>
                                                setFilterAttempts(filter)
                                            }
                                            className={`rounded-full px-2.5 py-1 text-[11px] font-semibold capitalize transition ${
                                                filterAttempts === filter
                                                    ? 'bg-[#36312b] text-[#f6f1e6]'
                                                    : 'text-[#a8a192] hover:text-[#eae4d6]'
                                            }`}
                                        >
                                            {filter}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            <div className="mt-4 flex flex-1 flex-col gap-3">
                                {filteredAttempts.length === 0 ? (
                                    <div className="flex flex-1 flex-col items-center justify-center py-8 text-center text-[#a8a192]">
                                        <Wallet className="size-8 opacity-40" />
                                        <p className="mt-2 text-xs">
                                            No matching attempts in transfer
                                            journal.
                                        </p>
                                    </div>
                                ) : (
                                    filteredAttempts.map((attempt) => (
                                        <div
                                            key={attempt.id}
                                            className="group flex flex-col justify-between gap-2 rounded-2xl border border-[#36312b]/60 bg-[#100e0c] p-3.5 text-xs transition hover:border-[#36312b] hover:bg-[#141210] sm:flex-row sm:items-center"
                                        >
                                            <div className="min-w-0">
                                                <div className="flex items-center gap-2">
                                                    <span className="font-bold text-[#f6f1e6]">
                                                        #{attempt.id}
                                                    </span>
                                                    <Badge
                                                        variant="outline"
                                                        className={`text-[10px] ${
                                                            attempt.posted
                                                                ? 'border-[#4caf7d]/30 text-[#4caf7d]'
                                                                : 'border-amber-500/30 text-amber-300'
                                                        }`}
                                                    >
                                                        {attempt.state_label}
                                                    </Badge>
                                                </div>
                                                <p className="mt-1 line-clamp-1 text-[11px] text-[#a8a192]">
                                                    {attempt.guidance}
                                                </p>
                                            </div>

                                            <div className="text-right sm:shrink-0">
                                                <p className="font-mono font-bold text-[#f6f1e6]">
                                                    {formatBaseUnits(
                                                        attempt.amount_base_units,
                                                    )}{' '}
                                                    USDC
                                                </p>
                                                <p className="text-[10px] text-[#a8a192]">
                                                    {attempt.posted
                                                        ? `${formatBaseUnits(attempt.applied_base_units)} applied`
                                                        : 'Unallocated'}
                                                </p>
                                            </div>
                                        </div>
                                    ))
                                )}
                            </div>

                            <div className="mt-4 border-t border-[#36312b] pt-3 text-right">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        router.reload({
                                            only: ['tuitionAttempts'],
                                        })
                                    }
                                    className="h-8 rounded-full text-xs text-[#a8a192] hover:text-[#f6f1e6]"
                                >
                                    <RefreshCw className="mr-1.5 size-3" />
                                    Reload Journal
                                </Button>
                            </div>
                        </section>

                        {/* Payout Destination Editor Card */}
                        <section className="flex flex-col justify-between rounded-3xl border border-[#36312b] bg-[#141210] p-6 sm:p-7">
                            <div>
                                <div className="flex items-center gap-2">
                                    <ShieldCheck className="size-4 text-[#4caf7d]" />
                                    <p className="bulletin-eyebrow">
                                        {hasAddress
                                            ? 'Replace wallet'
                                            : 'Link wallet'}
                                    </p>
                                </div>
                                <h2 className="mt-1 font-serif text-xl font-bold text-[#f6f1e6]">
                                    {walletLocked
                                        ? 'Protected Key'
                                        : 'Payout destination'}
                                </h2>
                                <p className="mt-2 text-xs leading-relaxed text-[#a8a192]">
                                    {walletLocked
                                        ? 'Your verified payout address is permanently secured against unauthorized rewrites.'
                                        : 'Direct financial assistance grants are delivered to this address.'}
                                </p>
                            </div>

                            {walletLocked ? (
                                <div className="mt-6 rounded-2xl border border-[#4caf7d]/20 bg-[#4caf7d]/5 p-4 text-xs text-[#a8a192]">
                                    <div className="flex items-center gap-2 font-semibold text-[#f6f1e6]">
                                        <CheckCircle2 className="size-4 text-[#4caf7d]" />
                                        <span>Cryptographic Lock Active</span>
                                    </div>
                                    <p className="mt-2 text-[11px] leading-relaxed">
                                        To change your primary wallet, visit the
                                        connection terminal to sign an
                                        authorization challenge.
                                    </p>
                                </div>
                            ) : (
                                <form
                                    onSubmit={saveWallet}
                                    className="mt-5 space-y-4"
                                >
                                    <div className="space-y-1.5">
                                        <div className="flex items-center justify-between">
                                            <Label
                                                htmlFor="wallet_address"
                                                className="text-xs text-[#a8a192]"
                                            >
                                                EVM Address
                                            </Label>
                                            <button
                                                type="button"
                                                onClick={pasteFromClipboard}
                                                className="text-[10px] font-semibold text-[#e9dfc7] hover:underline"
                                            >
                                                Paste clipboard
                                            </button>
                                        </div>
                                        <Input
                                            id="wallet_address"
                                            placeholder="0x…"
                                            autoComplete="off"
                                            spellCheck={false}
                                            value={data.wallet_address}
                                            onChange={(e) =>
                                                setData(
                                                    'wallet_address',
                                                    e.target.value.trim(),
                                                )
                                            }
                                            className="clay-field font-mono text-xs"
                                            required
                                        />
                                        <InputError
                                            message={errors.wallet_address}
                                        />
                                    </div>

                                    <div className="flex items-center gap-2">
                                        <Button
                                            type="submit"
                                            disabled={processing || !isEditing}
                                            className="h-9 flex-1 rounded-full bg-[#e9dfc7] text-xs font-bold text-[#191410] hover:bg-[#f5edd9]"
                                        >
                                            <Check className="mr-1.5 size-3.5" />
                                            {hasAddress
                                                ? 'Replace wallet'
                                                : 'Link wallet'}
                                        </Button>

                                        {isEditing && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    reset('wallet_address')
                                                }
                                                className="h-9 rounded-full text-xs text-[#a8a192] hover:text-[#eae4d6]"
                                            >
                                                Cancel
                                            </Button>
                                        )}
                                    </div>
                                </form>
                            )}

                            <p className="mt-6 border-t border-[#36312b] pt-3 text-[11px] text-[#a8a192]">
                                Arc settlement requires a 0x-prefixed
                                40-character hexadecimal address.
                            </p>
                        </section>
                    </div>
                </div>
            </div>
        </>
    );
}
