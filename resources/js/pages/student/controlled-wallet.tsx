import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { createPublicClient, custom, formatUnits, toHex } from 'viem';
import type { EIP1193Provider, Hex } from 'viem';
import { toast } from 'sonner';
import {
    ArrowLeft,
    ArrowUpRight,
    Check,
    CheckCircle2,
    Coins,
    Copy,
    Cpu,
    CreditCard,
    ExternalLink,
    Flame,
    History,
    Key,
    Layers,
    Loader2,
    Lock,
    Radio,
    RefreshCw,
    Shield,
    ShieldCheck,
    Sparkles,
    TriangleAlert,
    Wallet,
    XCircle,
    Zap,
} from 'lucide-react';
import { arcTestnet } from 'viem/chains';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { loadCircleSdk } from '@/lib/circle-browser-sdk';
import {
    executeCircleChallenge,
    waitForCircleWallet,
} from '@/lib/circle-wallet-onboarding';
import { show as walletOverview } from '@/routes/student/wallet';
import * as endpoints from '@/actions/App/Http/Controllers/StudentControlledWalletController';

type Quote = {
    estimated_fee?: string | null;
    id: string;
    snapshot: {
        sender: Hex;
        recipient: Hex;
        token: Hex;
        data: Hex;
        amount_base_units: number;
    };
};

type Props = {
    enabled: boolean;
    circleAppId: string | null;
    walletAuthMethod: 'pin' | 'google' | 'facebook' | null;
    socialProviders: { google: string | null; facebook: string | null };
    pastWallets?: {
        address: string;
        provider: string;
        replaced_at: string | null;
    }[];
    wallet: {
        address: string;
        provider: string;
        ownership_verified_at: string | null;
    } | null;
    accounts: { id: number; kind: string; label: string; remaining: string }[];
    payments: {
        id: string;
        tx_hash: string | null;
        actual_fee_base_units?: string | null;
        via_circle?: boolean;
        posted: boolean;
        can_cancel?: boolean;
        amount: string;
        applied: string;
        overpayment: string;
    }[];
};

async function post<T>(url: string, data: object = {}): Promise<T> {
    const csrf = document.cookie
        .split('; ')
        .find((v) => v.startsWith('XSRF-TOKEN='))
        ?.split('=')
        .slice(1)
        .join('=');
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(csrf ?? ''),
        },
        body: JSON.stringify(data),
    });
    const body = await response.json();
    if (!response.ok)
        throw new Error(
            Object.values(body.errors ?? {})
                .flat()
                .join(' ') ||
                body.message ||
                'Request failed.',
        );
    return body as T;
}

function provider(): EIP1193Provider {
    const ethereum = (window as unknown as { ethereum?: EIP1193Provider })
        .ethereum;
    if (!ethereum)
        throw new Error(
            'No browser wallet detected. Choose Circle PIN wallet instead.',
        );
    return ethereum;
}

async function testnet() {
    const eth = provider();
    try {
        await eth.request({
            method: 'wallet_switchEthereumChain',
            params: [{ chainId: '0x4cef52' }],
        });
    } catch (error) {
        if ((error as { code?: number }).code !== 4902) throw error;
        await eth.request({
            method: 'wallet_addEthereumChain',
            params: [
                {
                    chainId: '0x4cef52',
                    chainName: 'Arc Testnet',
                    nativeCurrency: {
                        name: 'USDC',
                        symbol: 'USDC',
                        decimals: 18,
                    },
                    rpcUrls: ['[REDACTED]'],
                    blockExplorerUrls: ['https://testnet.arcscan.app'],
                },
            ],
        });
    }
    if ((await eth.request({ method: 'eth_chainId' })) !== '0x4cef52')
        throw new Error('Switch to Arc Testnet 5042002 before continuing.');
    return eth;
}

export default function ControlledWallet({
    enabled,
    circleAppId,
    socialProviders,
    pastWallets = [],
    walletAuthMethod,
    wallet,
    accounts,
    payments,
}: Props) {
    const [connectedAddress, setConnectedAddress] = useState<string | null>(
        wallet?.ownership_verified_at ? wallet.address : null,
    );
    const connected = connectedAddress !== null;
    const circleLinked = wallet?.provider === 'circle-user';
    const [reconcileVia, setReconcileVia] = useState<
        'auto' | 'circle' | 'hash'
    >('auto');
    const [switchStage, setSwitchStage] = useState<
        'idle' | 'confirming' | 'choosing'
    >('idle');
    const switchPanel = useRef<HTMLElement>(null);

    useEffect(() => {
        if (switchStage !== 'idle') {
            switchPanel.current?.focus();
        }
    }, [switchStage]);
    const canCircle = true;
    const canBrowser = true;
    const canPin =
        canCircle && (!walletAuthMethod || walletAuthMethod === 'pin');
    const [copied, setCopied] = useState(false);

    const success = (text: string) => {
        setMessage(text);
        toast.success(text);
    };
    const failure = (error: unknown) => {
        const text =
            error instanceof Error ? error.message : 'Wallet operation failed.';
        setMessage(text);
        toast.error(text);
    };

    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const [selection, setSelection] = useState('');
    const [amount, setAmount] = useState('');
    const [feeConsent, setFeeConsent] = useState(false);
    const [quote, setQuote] = useState<Quote | null>(null);
    const [hash, setHash] = useState('');
    const [recoveryId, setRecoveryId] = useState('');
    const sdk = useRef<import('@circle-fin/w3s-pw-web-sdk').W3SSdk | null>(
        null,
    );

    const run = async (action: () => Promise<void>) => {
        setBusy(true);
        setMessage('');
        try {
            await action();
        } catch (e) {
            failure(e);
        } finally {
            setBusy(false);
        }
    };

    const execute = (challenge: string) =>
        executeCircleChallenge(sdk.current!, challenge);
    const linkCircleWallet = () =>
        waitForCircleWallet(
            () => post(endpoints.circleConnect.url()),
            setMessage,
        );

    const circleConnect = () =>
        run(async () => {
            if (!circleAppId)
                throw new Error('Circle embedded wallets are not configured.');
            const { W3SSdk } = await loadCircleSdk();
            sdk.current = new W3SSdk({ appSettings: { appId: circleAppId } });
            await sdk.current.getDeviceId();
            const auth = await post<{
                userToken: string;
                encryptionKey: string;
                challengeId: string | null;
            }>(endpoints.circleSession.url());
            sdk.current.setAuthentication({
                userToken: auth.userToken,
                encryptionKey: auth.encryptionKey,
            });
            setMessage(
                auth.challengeId
                    ? 'Approve wallet creation in the Circle window.'
                    : 'Checking your existing Circle wallet…',
            );
            if (auth.challengeId) await execute(auth.challengeId);
            setConnectedAddress(await linkCircleWallet());
            setSwitchStage('idle');
            success('Circle wallet connected. Ready for testnet payments.');
            router.reload();
        });

    const initializeSocial = async (
        providerName: 'google' | 'facebook',
        resume = false,
    ) => {
        if (!circleAppId) throw new Error('Circle wallets are not configured.');
        const redirect = new URL(endpoints.show.url(), window.location.origin);
        redirect.searchParams.set('wallet_login', providerName);
        const { W3SSdk } = await loadCircleSdk();
        let deviceId: string | undefined;
        if (!resume) {
            sdk.current ??= new W3SSdk({ appSettings: { appId: circleAppId } });
            deviceId = await sdk.current.getDeviceId();
        }
        const device = await post<{
            deviceToken: string;
            deviceEncryptionKey: string;
            nonce: string;
        }>(endpoints.socialDevice.url(), {
            provider: providerName,
            deviceId,
            resume,
        });
        const configs = {
            appSettings: { appId: circleAppId },
            loginConfigs: {
                deviceToken: device.deviceToken,
                deviceEncryptionKey: device.deviceEncryptionKey,
                ...(socialProviders.google
                    ? {
                          google: {
                              clientId: socialProviders.google,
                              redirectUri: new URL(redirect)
                                  .toString()
                                  .replace(
                                      /wallet_login=[^&]+/,
                                      'wallet_login=google',
                                  ),
                              selectAccountPrompt: true,
                          },
                      }
                    : {}),
                ...(socialProviders.facebook
                    ? {
                          facebook: {
                              appId: socialProviders.facebook,
                              redirectUri: new URL(redirect)
                                  .toString()
                                  .replace(
                                      /wallet_login=[^&]+/,
                                      'wallet_login=facebook',
                                  ),
                          },
                      }
                    : {}),
            },
        };
        const callbackTimer = resume
            ? window.setTimeout(() => {
                  setBusy(false);
                  setMessage(
                      'Circle did not return a social login callback. Check the registered redirect URI and browser cookie / popup restrictions, then reconnect the same login.',
                  );
              }, 60_000)
            : undefined;
        sdk.current = new W3SSdk(configs, async (error, result) => {
            window.clearTimeout(callbackTimer);
            if (error || !result) {
                setBusy(false);
                failure(
                    new Error(error?.message ?? 'Social wallet login failed.'),
                );
                return;
            }
            try {
                const response = await post<{ challengeId: string | null }>(
                    endpoints.socialComplete.url(),
                    {
                        userToken: result.userToken,
                        encryptionKey: result.encryptionKey,
                        provider: providerName,
                        nonce: device.nonce,
                    },
                );
                sdk.current!.setAuthentication({
                    userToken: result.userToken,
                    encryptionKey: result.encryptionKey,
                });
                window.history.replaceState(null, '', endpoints.show.url());
                if (response.challengeId)
                    await executeCircleChallenge(
                        sdk.current!,
                        response.challengeId,
                    );
                setConnectedAddress(await linkCircleWallet());
                setSwitchStage('idle');
                success('Social wallet connected. Ready for testnet payments.');
                router.reload();
            } catch (e) {
                failure(e);
            } finally {
                setBusy(false);
            }
        });
        if (!resume) {
            const { SocialLoginProvider } =
                await import('@circle-fin/w3s-pw-web-sdk/dist/src/types');
            await sdk.current.performLogin(
                providerName === 'google'
                    ? SocialLoginProvider.GOOGLE
                    : SocialLoginProvider.FACEBOOK,
            );
        }
    };

    const resumed = useRef(false);
    useEffect(() => {
        const login = new URLSearchParams(window.location.search).get(
            'wallet_login',
        );
        if (
            !circleAppId ||
            resumed.current ||
            (login !== 'google' && login !== 'facebook')
        )
            return;
        resumed.current = true;
        setBusy(true);
        void initializeSocial(login, true).catch((error: unknown) => {
            setMessage(
                error instanceof Error
                    ? error.message
                    : 'Could not resume social wallet login.',
            );
            setBusy(false);
        });
    });

    const connect = () =>
        run(async () => {
            const eth = await testnet();
            const [address] = await eth.request({
                method: 'eth_requestAccounts',
            });
            const challenge = await post<{ message: string }>(
                endpoints.challenge.url(),
                { address },
            );
            const signature = await eth.request({
                method: 'personal_sign',
                params: [toHex(challenge.message), address],
            });
            const linked = await post<{ address: string }>(
                endpoints.connect.url(),
                { signature },
            );
            setConnectedAddress(linked.address);
            setSwitchStage('idle');
            success('Browser wallet verified and connected.');
            router.reload();
        });

    const prepare = () =>
        run(async () => {
            const account = accounts.find(
                (a) => `${a.kind}:${a.id}` === selection,
            );
            if (!account) throw new Error('Choose a bill or tuition account.');
            const q = await post<Quote>(endpoints.quote.url(), {
                kind: account.kind,
                account_id: account.id,
                amount,
            });
            setQuote(q);
            setFeeConsent(false);
            setRecoveryId(q.id);
            setHash('');
        });

    const pay = () =>
        run(async () => {
            if (!quote || !feeConsent) return;
            if (wallet?.provider === 'circle-user') {
                if (!sdk.current)
                    throw new Error(
                        'Reconnect Circle session before approving payment.',
                    );
                const result = await post<{ challengeId: string }>(
                    endpoints.circlePay.url(quote.id),
                    { consent: true },
                );
                await execute(result.challengeId);
                setQuote(null);
                const resultStatus = await post<{
                    posted: boolean;
                    tx_hash?: string;
                }>(endpoints.circleStatus.url(quote.id));
                if (resultStatus.tx_hash) setHash(resultStatus.tx_hash);
                setMessage(
                    resultStatus.posted
                        ? 'Settlement verified. Testnet allocation recorded.'
                        : 'Circle authorization complete. Use Check Circle settlement below; do not submit another payment.',
                );
                return;
            }
            const eth = await testnet();
            const [sender] = await eth.request({
                method: 'eth_requestAccounts',
            });
            if (sender.toLowerCase() !== quote.snapshot.sender)
                throw new Error(
                    'Selected wallet differs from verified student wallet.',
                );
            const client = createPublicClient({
                chain: arcTestnet,
                transport: custom(eth),
            });
            const tx = {
                account: sender,
                to: quote.snapshot.token,
                data: quote.snapshot.data,
                value: 0n,
            };
            const gas = await client.estimateGas(tx);
            const gasPrice = await client.getGasPrice();
            const maxFeePerGas = gasPrice * 2n;
            setQuote(null);
            await post(endpoints.start.url(quote.id), { consent: true });
            const txHash = await eth.request({
                method: 'eth_sendTransaction',
                params: [
                    {
                        from: sender,
                        to: quote.snapshot.token,
                        data: quote.snapshot.data,
                        value: '0x0',
                        gas: toHex(gas),
                        maxFeePerGas: toHex(maxFeePerGas),
                        maxPriorityFeePerGas: '0x0',
                    },
                ],
            });
            setHash(txHash);
            localStorage.setItem(`student-payment-${quote.id}`, txHash);
            setQuote(null);
            setMessage(
                'Transaction submitted. Verify settlement below; do not pay again while outcome is unknown.',
            );
        });

    const circleStatus = () =>
        run(async () => {
            const result = await post<{
                posted: boolean;
                tx_hash?: string;
                state?: string;
            }>(endpoints.circleStatus.url(recoveryId));
            if (result.tx_hash) setHash(result.tx_hash);
            setMessage(
                result.posted
                    ? 'Settlement verified. Testnet allocation recorded.'
                    : `Circle state: ${result.state ?? 'pending'}. Do not pay again.`,
            );
            if (result.posted) router.reload();
        });

    const verify = () =>
        run(async () => {
            await post(endpoints.confirm.url(recoveryId), { tx_hash: hash });
            localStorage.removeItem(`student-payment-${recoveryId}`);
            success('Settlement verified. Testnet bill allocation recorded.');
            router.reload();
        });

    const copyConnectedAddress = async () => {
        if (!connectedAddress) return;
        try {
            await navigator.clipboard.writeText(connectedAddress);
            setCopied(true);
            toast.success('Wallet address copied to clipboard');
            setTimeout(() => setCopied(false), 2000);
        } catch {
            toast.error('Could not copy automatically.');
        }
    };

    return (
        <>
            <Head title="Student-controlled wallet" />

            <div className="bulletin clay-ambient min-h-screen w-full bg-[#0b0a09] text-[#eae4d6] antialiased selection:bg-[#e9dfc7] selection:text-[#191410]">
                {/* Background Ambient Glows */}
                <div
                    aria-hidden="true"
                    className="pointer-events-none fixed inset-0 overflow-hidden"
                >
                    <div className="absolute -top-40 right-1/4 h-96 w-96 rounded-full bg-[#e9dfc7]/5 blur-3xl" />
                    <div className="absolute bottom-10 left-10 h-80 w-80 rounded-full bg-[#d6c49a]/5 blur-3xl" />
                </div>

                <main className="relative mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-8 sm:px-6 lg:px-8">
                    {/* Top Navigation & Status Bar */}
                    <header className="border-b border-[#36312b] pb-6">
                        <Link
                            href={walletOverview.url()}
                            className="inline-flex items-center gap-1.5 text-xs font-semibold text-[#a8a192] transition hover:text-[#f6f1e6]"
                        >
                            <ArrowLeft className="size-3.5" />
                            <span>Wallet overview</span>
                        </Link>

                        <div className="mt-4 flex flex-wrap items-end justify-between gap-4">
                            <div>
                                <div className="flex items-center gap-2">
                                    <span className="flex items-center gap-1.5 rounded-full border border-[#4caf7d]/30 bg-[#4caf7d]/10 px-2.5 py-0.5 text-[11px] font-semibold text-[#4caf7d]">
                                        <Radio className="size-2.5 animate-pulse" />
                                        Arc Testnet · 5042002
                                    </span>
                                    <span className="rounded-full border border-[#36312b] bg-[#141210] px-2.5 py-0.5 font-mono text-[11px] text-[#a8a192]">
                                        Native USDC Gas
                                    </span>
                                </div>
                                <h1 className="mt-2 font-serif text-3xl font-bold tracking-tight text-[#f6f1e6] sm:text-4xl">
                                    Your keys. Your choice.
                                </h1>
                                <p className="mt-1 text-sm text-[#a8a192]">
                                    Connect once. Approve each transfer
                                    yourself.
                                </p>
                            </div>

                            <div className="flex items-center gap-2">
                                <Badge
                                    variant="outline"
                                    className="border-[#36312b] bg-[#141210] px-3 py-1 font-mono text-xs text-[#a8a192]"
                                >
                                    Test funds only. Not official tuition
                                    payment.
                                </Badge>
                            </div>
                        </div>

                        {/* Interactive Status Notice */}
                        {(message || busy) && (
                            <div className="mt-4 flex items-center gap-2.5 rounded-2xl border border-[#36312b] bg-[#141210] p-3 text-xs text-[#e9dfc7]">
                                {busy ? (
                                    <Loader2 className="size-4 animate-spin text-[#d6c49a]" />
                                ) : (
                                    <Sparkles className="size-4 text-[#4caf7d]" />
                                )}
                                <span className="font-mono">
                                    {message ||
                                        'Waiting for Circle or your wallet…'}
                                </span>
                            </div>
                        )}

                        {!enabled && (
                            <div className="mt-4 flex items-center gap-2 rounded-2xl border border-rose-500/30 bg-rose-500/10 p-3 text-xs text-rose-300">
                                <TriangleAlert className="size-4 shrink-0 text-rose-400" />
                                <span>
                                    Student-controlled testnet payments are
                                    disabled by this installation.
                                </span>
                            </div>
                        )}
                    </header>

                    {/* Switch Confirmation Modal / Dialog Banner */}
                    {connected && switchStage === 'confirming' && (
                        <section
                            ref={switchPanel}
                            tabIndex={-1}
                            role="alertdialog"
                            aria-label="Change active wallet"
                            className="rounded-3xl border border-amber-500/40 bg-amber-500/10 p-6 text-xs text-amber-200"
                        >
                            <div className="flex items-center gap-2 font-serif text-lg font-bold text-amber-100">
                                <TriangleAlert className="size-5 text-amber-400" />
                                <h2>Switch to another wallet?</h2>
                            </div>
                            <ul className="mt-3 list-disc space-y-1.5 pl-5 text-xs text-amber-200/90">
                                <li>
                                    Your current balance stays at{' '}
                                    <code className="font-mono font-bold">
                                        {connectedAddress?.slice(0, 10)}…
                                    </code>
                                    . Nothing moves automatically.
                                </li>
                                <li>
                                    Future payments and payouts will use the new
                                    address. Old receipts stay linked to the
                                    previous wallet.
                                </li>
                                <li>
                                    Moving funds requires a separate transfer
                                    you approve in your old wallet, keeping USDC
                                    for gas.
                                </li>
                            </ul>
                            <div className="mt-5 flex flex-wrap gap-3">
                                <Button
                                    size="sm"
                                    data-test="wallet-change-confirm"
                                    onClick={() => {
                                        setQuote(null);
                                        setFeeConsent(false);
                                        setSwitchStage('choosing');
                                    }}
                                    className="rounded-full bg-amber-500 px-4 text-xs font-bold text-black hover:bg-amber-400"
                                >
                                    Yes, choose another wallet
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    data-test="wallet-change-keep"
                                    onClick={() => setSwitchStage('idle')}
                                    className="rounded-full text-xs text-amber-200 hover:text-white"
                                >
                                    Keep my current wallet
                                </Button>
                            </div>
                        </section>
                    )}

                    {/* Main Two-Column Interactive Flight Deck */}
                    <div className="grid gap-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
                        {/* LEFT: Connection & Identity Station */}
                        <div className="flex flex-col gap-6">
                            {/* Connected Card or Connect Method Selector */}
                            <section className="flex flex-col justify-between overflow-hidden rounded-3xl border border-[#36312b] bg-[#141210] p-6 sm:p-7 shadow-xl">
                                {connected && switchStage !== 'choosing' ? (
                                    <div className="space-y-5">
                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                            <div className="flex items-center gap-3">
                                                <div className="flex size-10 items-center justify-center rounded-2xl bg-[#4caf7d]/10 text-[#4caf7d]">
                                                    <ShieldCheck className="size-5" />
                                                </div>
                                                <div>
                                                    <p className="text-[10px] font-bold tracking-widest text-[#a8a192] uppercase">
                                                        {circleLinked
                                                            ? 'Circle student wallet'
                                                            : 'Browser student wallet'}
                                                    </p>
                                                    <h2 className="font-serif text-xl font-bold text-[#f6f1e6]">
                                                        Connected & verified
                                                    </h2>
                                                </div>
                                            </div>

                                            <Badge
                                                variant="outline"
                                                className="border-[#4caf7d]/30 bg-[#4caf7d]/10 text-xs text-[#4caf7d]"
                                            >
                                                <CheckCircle2 className="mr-1 size-3" />
                                                Arc Testnet
                                            </Badge>
                                        </div>

                                        {/* Address Bar */}
                                        <div className="rounded-2xl border border-[#36312b] bg-[#0b0a09] p-4">
                                            <p className="text-[10px] font-semibold text-[#a8a192] uppercase">
                                                Your receiving & payment address
                                            </p>
                                            <code className="mt-1 block break-all font-mono text-xs font-bold text-[#f6f1e6]">
                                                {connectedAddress}
                                            </code>

                                            <div className="mt-4 flex flex-wrap gap-2">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={
                                                        copyConnectedAddress
                                                    }
                                                    className="h-8 rounded-full border-[#36312b] bg-[#141210] text-xs font-semibold text-[#eae4d6] hover:border-[#e9dfc7]"
                                                >
                                                    {copied ? (
                                                        <Check className="mr-1.5 size-3 text-[#4caf7d]" />
                                                    ) : (
                                                        <Copy className="mr-1.5 size-3" />
                                                    )}
                                                    {copied
                                                        ? 'Copied'
                                                        : 'Copy address'}
                                                </Button>

                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    asChild
                                                    className="h-8 rounded-full border-[#36312b] bg-[#141210] text-xs font-semibold text-[#eae4d6] hover:border-[#e9dfc7]"
                                                >
                                                    <a
                                                        href={`https://testnet.arcscan.app/address/${connectedAddress}`}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                    >
                                                        <ExternalLink className="mr-1.5 size-3" />
                                                        View on explorer
                                                    </a>
                                                </Button>
                                            </div>
                                        </div>

                                        <div className="flex items-center justify-between border-t border-[#36312b] pt-4">
                                            <p className="text-xs text-[#a8a192]">
                                                Ready for testnet settlements.
                                            </p>

                                            {switchStage === 'idle' && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    disabled={busy}
                                                    data-test="wallet-change-open"
                                                    onClick={() =>
                                                        setSwitchStage(
                                                            'confirming',
                                                        )
                                                    }
                                                    className="h-8 rounded-full border-[#36312b] text-xs font-semibold text-[#a8a192] hover:text-[#f6f1e6]"
                                                >
                                                    Change active wallet
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                ) : (
                                    <div className="space-y-4">
                                        <div className="flex items-center gap-3">
                                            <div className="flex size-10 items-center justify-center rounded-2xl bg-[#e9dfc7]/10 text-[#e9dfc7]">
                                                <Wallet className="size-5" />
                                            </div>
                                            <div>
                                                <p className="text-[10px] font-bold tracking-widest text-[#a8a192] uppercase">
                                                    Flight-Deck
                                                </p>
                                                <h2 className="font-serif text-xl font-bold text-[#f6f1e6]">
                                                    Connect your student wallet
                                                </h2>
                                            </div>
                                        </div>
                                        <p className="text-xs text-[#a8a192]">
                                            Choose a browser wallet or
                                            extension-free Circle wallet. You
                                            control every payment.
                                        </p>
                                    </div>
                                )}

                                {/* Connection Options Grid (Shown always or when connecting) */}
                                <section
                                    ref={switchStage === 'choosing' ? switchPanel : undefined}
                                    tabIndex={-1}
                                    aria-label="Wallet connection options"
                                    className="mt-6 border-t border-[#36312b] pt-6"
                                >
                                    {switchStage === 'choosing' && (
                                        <div role="status">
                                            <p>
                                                Choose your replacement wallet. Your current wallet stays active until you verify the new one.
                                            </p>
                                            <Button
                                                variant="ghost"
                                                disabled={busy}
                                                data-test="wallet-change-cancel"
                                                onClick={() => setSwitchStage('idle')}
                                            >
                                                Cancel wallet change
                                            </Button>
                                        </div>
                                    )}
                                    <h3 className="text-xs font-bold tracking-wider text-[#a8a192] uppercase">
                                        {connected && switchStage !== 'choosing'
                                            ? 'Reconnect your wallet session'
                                            : 'Choose how to connect'}
                                    </h3>
                                    <p className="mt-1 text-xs text-[#a8a192]">
                                        Use MetaMask, or create an embedded
                                        Circle PIN wallet without an extension.
                                    </p>

                                    <div className="mt-4 grid gap-3 sm:grid-cols-2">
                                        {/* Method 1: Web3 Browser Extension */}
                                        <Button
                                            disabled={
                                                !enabled || busy || !canBrowser
                                            }
                                            data-test="wallet-connect-browser"
                                            onClick={connect}
                                            className="h-10 justify-start rounded-2xl border border-[#36312b] bg-[#100e0c] px-4 text-xs font-bold text-[#f6f1e6] transition hover:border-[#e9dfc7] hover:bg-[#1b1815]"
                                        >
                                            <Cpu className="mr-2 size-4 text-[#e9dfc7]" />
                                            Connect browser wallet
                                        </Button>

                                        {/* Method 2: Circle Embedded PIN */}
                                        <Button
                                            disabled={
                                                !enabled ||
                                                !circleAppId ||
                                                busy ||
                                                !canPin
                                            }
                                            onClick={circleConnect}
                                            className="h-10 justify-start rounded-2xl border border-[#36312b] bg-[#100e0c] px-4 text-xs font-bold text-[#f6f1e6] transition hover:border-[#e9dfc7] hover:bg-[#1b1815]"
                                        >
                                            <Shield className="mr-2 size-4 text-[#d6c49a]" />
                                            Create / reconnect PIN wallet
                                        </Button>

                                        {/* Method 3: Social OAuth Passkeys */}
                                        {(['google', 'facebook'] as const).map(
                                            (name) => (
                                                <Button
                                                    key={name}
                                                    variant="outline"
                                                    disabled={
                                                        !enabled ||
                                                        busy ||
                                                        !circleAppId ||
                                                        !socialProviders[
                                                            name
                                                        ] ||
                                                        (walletAuthMethod !==
                                                            null &&
                                                            walletAuthMethod !==
                                                                name)
                                                    }
                                                    onClick={() =>
                                                        run(() =>
                                                            initializeSocial(
                                                                name,
                                                            ),
                                                        )
                                                    }
                                                    className="h-10 justify-start rounded-2xl border border-[#36312b] bg-[#100e0c] px-4 text-xs font-bold text-[#f6f1e6] transition hover:border-[#e9dfc7] hover:bg-[#1b1815]"
                                                >
                                                    <Key className="mr-2 size-3.5 text-[#a8a192]" />
                                                    Connect with{' '}
                                                    {name === 'google'
                                                        ? 'Google'
                                                        : 'Facebook'}
                                                </Button>
                                            ),
                                        )}
                                    </div>

                                    {/* Faucet Guidance Bar */}
                                    <div className="mt-5 flex items-center justify-between rounded-2xl border border-[#36312b] bg-[#0b0a09] p-3 text-xs">
                                        <div className="flex items-center gap-2">
                                            <Flame className="size-4 text-[#4caf7d]" />
                                            <span className="text-[#a8a192]">
                                                Need testnet funds?
                                            </span>
                                        </div>
                                        <a
                                            href="https://faucet.circle.com"
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex items-center gap-1 font-semibold text-[#e9dfc7] hover:underline"
                                        >
                                            Get testnet USDC from Circle faucet
                                            <ArrowUpRight className="size-3" />
                                        </a>
                                    </div>
                                </section>
                            </section>

                            {/* Safety Notice: Your balance stays with your wallet */}
                            <section className="rounded-3xl border border-[#36312b] bg-[#141210] p-6 text-xs text-[#a8a192]">
                                <h3 className="font-serif text-base font-bold text-[#f6f1e6]">
                                    Your balance stays with your wallet
                                </h3>
                                <p className="mt-2 leading-relaxed">
                                    Connecting or reconnecting never transfers
                                    USDC. Funds remain on the original address.
                                    This app protects your linked payment
                                    wallet: another address or wallet type
                                    cannot overwrite it.
                                </p>
                            </section>

                            {/* Previous Wallets List */}
                            {pastWallets.length > 0 && (
                                <section className="rounded-3xl border border-[#36312b] bg-[#141210] p-6 text-xs">
                                    <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-[#a8a192]">
                                        <History className="size-3.5 text-[#d6c49a]" />
                                        <h3>Previous wallets</h3>
                                    </div>
                                    <p className="mt-1 text-[#a8a192]">
                                        Replaced wallets keep their receipts and
                                        funding history. Their funds never moved
                                        with the account.
                                    </p>
                                    <div className="mt-3 space-y-2">
                                        {pastWallets.map((w) => (
                                            <div
                                                key={w.address}
                                                className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-[#36312b] bg-[#0b0a09] p-2.5 font-mono text-[11px]"
                                            >
                                                <span className="text-[#f6f1e6]">
                                                    {w.address}
                                                </span>
                                                <span className="text-[#a8a192]">
                                                    {w.provider ===
                                                    'circle-user'
                                                        ? 'Circle'
                                                        : 'Browser'}{' '}
                                                    ·{' '}
                                                    {w.replaced_at ??
                                                        'replaced'}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                </section>
                            )}
                        </div>

                        {/* RIGHT: Payment Terminal & Verification/Recovery Station */}
                        <div className="flex flex-col gap-6">
                            {/* 01 / Payment Terminal */}
                            <section className="flex flex-col rounded-3xl border border-[#36312b] bg-[#141210] p-6 sm:p-7 shadow-xl">
                                <div className="border-b border-[#36312b] pb-4">
                                    <div className="flex items-center justify-between">
                                        <span className="text-[10px] font-bold tracking-widest text-[#a8a192] uppercase">
                                            01 / Payment
                                        </span>
                                        <Coins className="size-4 text-[#e9dfc7]" />
                                    </div>
                                    <h2 className="mt-1 font-serif text-xl font-bold text-[#f6f1e6]">
                                        Pay a testnet bill
                                    </h2>
                                    <p className="mt-1 text-xs text-[#a8a192]">
                                        Choose an account, review the exact
                                        amount, then approve in your linked
                                        wallet.
                                    </p>
                                </div>

                                <div className="mt-5 space-y-4 text-xs">
                                    <div>
                                        <Label
                                            htmlFor="account"
                                            className="text-xs font-semibold text-[#a8a192]"
                                        >
                                            Tuition or bill
                                        </Label>
                                        <select
                                            id="account"
                                            className="mt-1.5 w-full rounded-2xl border border-[#36312b] bg-[#0b0a09] p-3 text-xs font-medium text-[#f6f1e6] focus:border-[#e9dfc7] focus:outline-none"
                                            value={selection}
                                            onChange={(e) => {
                                                setSelection(e.target.value);
                                                setQuote(null);
                                            }}
                                        >
                                            <option value="">
                                                Choose account
                                            </option>
                                            {accounts.map((a) => (
                                                <option
                                                    key={`${a.kind}:${a.id}`}
                                                    value={`${a.kind}:${a.id}`}
                                                >
                                                    {a.label} —{' '}
                                                    {formatUnits(
                                                        BigInt(a.remaining),
                                                        6,
                                                    )}{' '}
                                                    USDC
                                                </option>
                                            ))}
                                        </select>
                                    </div>

                                    <div>
                                        <Label
                                            htmlFor="amount"
                                            className="text-xs font-semibold text-[#a8a192]"
                                        >
                                            Amount (USDC)
                                        </Label>
                                        <Input
                                            id="amount"
                                            placeholder="5.00"
                                            value={amount}
                                            onChange={(e) => {
                                                setAmount(e.target.value);
                                                setQuote(null);
                                            }}
                                            className="mt-1.5 clay-field font-mono text-xs"
                                        />
                                    </div>

                                    <Button
                                        type="button"
                                        disabled={
                                            !enabled ||
                                            busy ||
                                            switchStage !== 'idle' ||
                                            !selection ||
                                            !amount
                                        }
                                        onClick={prepare}
                                        className="h-10 w-full rounded-full bg-[#36312b] text-xs font-bold text-[#f6f1e6] transition hover:bg-[#e9dfc7] hover:text-[#191410]"
                                    >
                                        Review Quote & Estimate Fee
                                    </Button>

                                    {/* Quote Breakdown & Approval Box */}
                                    {quote && (
                                        <div className="space-y-3 rounded-2xl border border-[#e9dfc7]/30 bg-[#0b0a09] p-4">
                                            <div className="flex items-center justify-between text-xs font-bold text-[#f6f1e6]">
                                                <span>Settlement Amount</span>
                                                <span className="font-mono">
                                                    {formatUnits(
                                                        BigInt(
                                                            quote.snapshot
                                                                .amount_base_units,
                                                        ),
                                                        6,
                                                    )}{' '}
                                                    USDC
                                                </span>
                                            </div>

                                            <div className="flex items-center justify-between text-[11px] text-[#a8a192]">
                                                <span>Estimated Gas Fee</span>
                                                <span className="font-mono text-[#d6c49a]">
                                                    {quote.estimated_fee
                                                        ? `${quote.estimated_fee} USDC`
                                                        : 'Free (Sponsored)'}
                                                </span>
                                            </div>

                                            <div className="flex items-center gap-2 border-t border-[#36312b] pt-3 text-[11px] text-[#a8a192]">
                                                <input
                                                    type="checkbox"
                                                    id="feeConsent"
                                                    checked={feeConsent}
                                                    onChange={(e) =>
                                                        setFeeConsent(
                                                            e.target.checked,
                                                        )
                                                    }
                                                    className="rounded border-[#36312b] bg-[#141210] accent-[#e9dfc7]"
                                                />
                                                <label
                                                    htmlFor="feeConsent"
                                                    className="cursor-pointer"
                                                >
                                                    I approve this payment on
                                                    Arc Testnet.
                                                </label>
                                            </div>

                                            <Button
                                                disabled={!feeConsent || busy}
                                                onClick={pay}
                                                className="mt-2 h-10 w-full rounded-full bg-[#e9dfc7] text-xs font-bold text-[#191410] shadow-md hover:bg-[#f5edd9]"
                                            >
                                                Approve in my wallet
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            </section>

                            {/* 02 / Recovery & Verification Station */}
                            <section className="flex flex-col rounded-3xl border border-[#36312b] bg-[#141210] p-6 sm:p-7 shadow-xl">
                                <div className="border-b border-[#36312b] pb-4">
                                    <div className="flex items-center justify-between">
                                        <span className="text-[10px] font-bold tracking-widest text-[#a8a192] uppercase">
                                            02 / Recovery & receipts
                                        </span>
                                        <Sparkles className="size-4 text-[#4caf7d]" />
                                    </div>
                                    <h2 className="mt-1 font-serif text-xl font-bold text-[#f6f1e6]">
                                        Verify existing transfer
                                    </h2>
                                    <p className="mt-1 text-xs text-[#a8a192]">
                                        Keep payment reference and hash after
                                        sending. An unresolved transfer must not
                                        be resent.
                                    </p>
                                </div>

                                <div className="mt-5 space-y-4 text-xs">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Button
                                            type="button"
                                            size="sm"
                                            disabled={busy || !recoveryId}
                                            variant={
                                                reconcileVia === 'hash'
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            onClick={() => {
                                                setReconcileVia('hash');
                                                void verify();
                                            }}
                                            className="h-8 rounded-full border-[#36312b] text-xs font-semibold"
                                        >
                                            Verify with transaction hash
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            disabled={busy || !recoveryId}
                                            variant={
                                                reconcileVia === 'circle'
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            onClick={() => {
                                                setReconcileVia('circle');
                                                void circleStatus();
                                            }}
                                            className="h-8 rounded-full border-[#36312b] text-xs font-semibold"
                                        >
                                            Check Circle settlement
                                        </Button>
                                    </div>

                                    <div>
                                        <Label
                                            htmlFor="reference"
                                            className="text-xs text-[#a8a192]"
                                        >
                                            Payment reference
                                        </Label>
                                        <Input
                                            id="reference"
                                            value={recoveryId}
                                            onChange={(e) =>
                                                setRecoveryId(e.target.value)
                                            }
                                            className="mt-1 clay-field font-mono text-xs"
                                        />
                                    </div>

                                    <div>
                                        <Label
                                            htmlFor="hash"
                                            className="text-xs text-[#a8a192]"
                                        >
                                            Transaction hash
                                        </Label>
                                        <Input
                                            id="hash"
                                            value={hash}
                                            onChange={(e) =>
                                                setHash(e.target.value)
                                            }
                                            className="mt-1 clay-field font-mono text-xs"
                                        />
                                    </div>

                                    <Button
                                        type="button"
                                        disabled={
                                            !enabled ||
                                            busy ||
                                            !hash ||
                                            !recoveryId
                                        }
                                        onClick={verify}
                                        className="h-9 w-full rounded-full bg-[#36312b] text-xs font-bold text-[#f6f1e6] hover:bg-[#e9dfc7] hover:text-[#191410]"
                                    >
                                        Verify on-chain settlement
                                    </Button>

                                    {/* Attempt Cards List */}
                                    <div className="mt-4 space-y-2.5 border-t border-[#36312b] pt-4">
                                        {payments.map((p) => (
                                            <div
                                                key={p.id}
                                                className="flex flex-col justify-between gap-2 rounded-2xl border border-[#36312b]/60 bg-[#0b0a09] p-3 text-xs"
                                            >
                                                <div className="flex items-center justify-between">
                                                    <span className="font-mono font-bold text-[#f6f1e6]">
                                                        {formatUnits(
                                                            BigInt(p.amount),
                                                            6,
                                                        )}{' '}
                                                        USDC
                                                    </span>
                                                    <Badge
                                                        variant="outline"
                                                        className={`text-[10px] ${
                                                            p.posted
                                                                ? 'border-[#4caf7d]/30 text-[#4caf7d]'
                                                                : 'border-amber-500/30 text-amber-300'
                                                        }`}
                                                    >
                                                        {p.posted
                                                            ? 'Verified testnet allocation'
                                                            : 'Unresolved — do not pay again'}
                                                    </Badge>
                                                </div>

                                                <code className="break-all font-mono text-[10px] text-[#a8a192]">
                                                    {p.id}
                                                </code>

                                                {p.posted && (
                                                    <p className="text-[10px] text-[#a8a192]">
                                                        Gas fee:{' '}
                                                        {p.actual_fee_base_units !=
                                                        null
                                                            ? `${formatUnits(BigInt(p.actual_fee_base_units), 6)} USDC`
                                                            : 'Unavailable'}
                                                    </p>
                                                )}

                                                <div className="mt-2 flex flex-wrap gap-2">
                                                    {!p.posted && (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() => {
                                                                setRecoveryId(
                                                                    p.id,
                                                                );
                                                                setReconcileVia(
                                                                    p.via_circle
                                                                        ? 'circle'
                                                                        : 'hash',
                                                                );
                                                                setHash(
                                                                    p.tx_hash ??
                                                                        localStorage.getItem(
                                                                            `student-payment-${p.id}`,
                                                                        ) ??
                                                                        '',
                                                                );
                                                            }}
                                                            className="h-7 rounded-full border-[#36312b] text-[10px] text-[#eae4d6]"
                                                        >
                                                            {p.via_circle
                                                                ? 'Check Circle settlement'
                                                                : 'Verify with hash'}
                                                        </Button>
                                                    )}

                                                    {!p.posted &&
                                                        p.can_cancel && (
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                disabled={busy}
                                                                onClick={() =>
                                                                    run(
                                                                        async () => {
                                                                            await post(
                                                                                endpoints.cancel.url(
                                                                                    p.id,
                                                                                ),
                                                                            );
                                                                            toast.success(
                                                                                'Unbroadcast attempt cleared.',
                                                                            );
                                                                            router.reload();
                                                                        },
                                                                    )
                                                                }
                                                                className="h-7 rounded-full border-amber-500/30 text-[10px] text-amber-300 hover:bg-amber-500/10"
                                                            >
                                                                Clear
                                                                unbroadcast
                                                                attempt
                                                            </Button>
                                                        )}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </section>
                        </div>
                    </div>
                </main>
            </div>
        </>
    );
}
