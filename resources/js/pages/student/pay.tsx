import { Head, Link, router, usePoll, useRemember } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowLeft,
    ArrowUpRight,
    BrainCircuit,
    Check,
    CheckCircle2,
    Clock,
    Copy,
    ExternalLink,
    Fingerprint,
    Layers,
    Loader2,
    Lock,
    QrCode,
    Radio,
    ReceiptText,
    RefreshCw,
    Send,
    ShieldCheck,
    Sparkles,
    Trash2,
    TriangleAlert,
    Wallet,
    XCircle,
    Zap,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { arcTestnet } from 'viem/chains';
import {
    createPublicClient,
    custom,
    toHex,
    parseUnits,
    formatUnits,
} from 'viem';
import type { EIP1193Provider } from 'viem';
import { loadCircleSdk } from '@/lib/circle-browser-sdk';
import {
    executeCircleChallenge,
    watchCircleSettlement,
} from '@/lib/circle-wallet-onboarding';

import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { OfficialPaymentReceiptModal } from '@/components/official-payment-receipt-modal';
import { StudentPaymentTracker } from '@/components/student-payment-tracker';
import {
    cancel as cancelControlled,
    circlePay,
    circleSession,
    circleStatus,
    confirm as confirmControlled,
    quote as studentQuote,
    estimate as estimateStudentFee,
    start as startControlled,
} from '@/actions/App/Http/Controllers/StudentControlledWalletController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatUsdc } from '@/lib/financial-aid';
import {
    dashboard as studentDashboard,
    bills as studentBills,
} from '@/routes/student';
import {
    classify as billClassify,
    consent as billConsent,
    quote as billQuote,
} from '@/routes/student/bills';
import {
    quote as tuitionPayQuote,
    pay as tuitionPayStore,
} from '@/routes/student/tuition';
import { clear as clearTuitionHold } from '@/routes/student/tuition/attempts';
import {
    refresh as walletRefresh,
    show as walletShow,
} from '@/routes/student/wallet';
import type {
    OfficialPaymentReceipt,
    StudentDashboardProps,
    TuitionAttempt,
} from '@/types/financial-aid';

type BillItem = {
    id: number;
    category: string;
    category_label: string;
    description: string;
    amount_base_units: string;
    paid_base_units: string;
    remaining_base_units: string;
    due_date: string | null;
    status: string;
    is_pilot: boolean;
};

type BillClassification = {
    category: string | null;
    beneficiary_alias: string | null;
    confidence: number;
    narrative: string | null;
    anomaly_flags: string[];
};

type BillPaymentItem = {
    id: number;
    request_key: string;
    student_bill_id: number;
    amount_base_units: string;
    applied_base_units: string;
    overpayment_base_units: string;
    quoted_network_fee: string | null;
    fee_paid_by: string | null;
    tx_hash: string | null;
    provider_handle: string | null;
    submission_state: string;
    submission_label: string;
    policy_decision: string;
    policy_code: string;
    requires_human_approval: boolean;
    authorizes_outgoing_spend: boolean;
    is_rehearsal: boolean;
    is_allocated: boolean;
    verified: boolean;
    last_error: string | null;
    created_at?: string | null;
    receipt?: OfficialPaymentReceipt | null;
};

type TuitionQuote = {
    estimated_student_fee?: string | null;
    network_fee: string;
    total: string;
    payer_debit?: string;
    fee_paid_by_payer?: boolean;
    max_amount_base_units: number;
    amount_base_units?: number;
    balance: string | null;
    chain: string;
    request_digest: string;
    consent_text: string;
    expires_at: string;
    fee_ceiling_base_units: number;
    wallet_sender?: `0x${string}`;
    wallet_recipient?: `0x${string}`;
    wallet_token?: `0x${string}`;
    wallet_data?: `0x${string}`;
    request_key?: string;
};

type BillMountedQuote = {
    request_key: string;
    request_digest: string;
    amount_base_units: number;
    quoted_network_fee: string;
    fee_ceiling_base_units: number;
    wallet_sender?: `0x${string}`;
    wallet_recipient?: `0x${string}`;
    wallet_token?: `0x${string}`;
    wallet_data?: `0x${string}`;
    available_base_units: number;
    total: string;
    recipient: string;
    chain: string;
    expires_at: string;
    consent_text: string;
    category_label: string;
    policy_code: string;
    requires_human_approval: boolean;
    authorizes_outgoing_spend: boolean;
    eligible_for_unattended_submission: boolean;
};

interface StudentPayPageProps {
    initialMode: 'tuition' | 'bill';
    initialBillId: number | null;
    initialAmount?: string | null;
    initialAccountId?: number | null;
    student: StudentDashboardProps['student'];
    wallet: NonNullable<StudentDashboardProps['wallet']> & {
        provider?: string | null;
        controlled_by_student?: boolean;
        qr_code?: string | null;
    };
    treasury: {
        address: string | null;
        qr_code?: string | null;
        explorer_url: string | null;
        chain: string;
        chain_id: number;
    };
    tuitionAccount: StudentDashboardProps['tuitionAccount'];
    tuitionAttempts: TuitionAttempt[];
    bills: BillItem[];
    billPayments: BillPaymentItem[];
    pilot_enabled: boolean;
    tuition_payment_mode?: 'operator_pilot' | 'student_controlled';
    student_wallets_enabled?: boolean;
    circleAppId?: string | null;
    controlledPayments?: {
        id: string;
        tx_hash: string | null;
        progress?: {
            stage: string;
            message: string;
            status: string;
            at: string | null;
        }[];
        via_circle: boolean;
        approval_started: boolean;
        is_expired: boolean;
        posted: boolean;
        can_cancel: boolean;
        amount_base_units: string;
        applied_base_units: string;
    }[];
    tuition_enabled: boolean;
    currency: {
        display: string;
        symbol: string;
        units_per_usdc: number;
        rate_description: string;
    };
}

const EXPLORER = 'https://testnet.arcscan.app';

function shortAddress(address: string): string {
    if (address.length <= 12) {
        return address;
    }

    return `${address.slice(0, 6)}…${address.slice(-4)}`;
}

function formatBaseUnits(baseUnits: string | number): string {
    try {
        const units = BigInt(baseUnits);

        return `${(units / 1_000_000n).toLocaleString('en-US')}.${(units % 1_000_000n).toString().padStart(6, '0')}`;
    } catch {
        return String(baseUnits);
    }
}

function formatBaseUnitsShort(baseUnits: string | number): string {
    try {
        const units = BigInt(baseUnits);
        const whole = (units / 1_000_000n).toLocaleString('en-US');
        const cents = (units % 1_000_000n)
            .toString()
            .padStart(6, '0')
            .slice(0, 2);

        return `${whole}.${cents}`;
    } catch {
        return String(baseUnits);
    }
}

function formatBalance(balance: string): string {
    const [whole = '0', fraction = ''] = balance.split('.');
    if (!/^\d+$/.test(whole)) {
        return `${balance} USDC`;
    }

    const grouped = BigInt(whole).toLocaleString('en-US');
    const cents = fraction.replace(/0+$/, '').slice(0, 2).padEnd(2, '0');

    return `${grouped}.${cents} USDC`;
}

function parseBaseUnits(amount: string): number | null {
    const cleaned = amount.trim();
    if (!/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/.test(cleaned)) {
        return null;
    }
    const [whole, fraction = ''] = cleaned.split('.');
    const exact = BigInt(whole) * 1_000_000n + BigInt(fraction.padEnd(6, '0'));
    const baseUnits = Number(exact);

    if (!Number.isSafeInteger(baseUnits) || baseUnits <= 0) {
        return null;
    }

    return baseUnits;
}

function readCsrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

export default function StudentPayPage({
    initialMode,
    initialBillId,
    initialAmount,
    initialAccountId: _initialAccountId,
    student,
    wallet,
    treasury,
    tuitionAccount,
    tuitionAttempts = [],
    bills = [],
    billPayments = [],
    pilot_enabled,
    tuition_payment_mode = 'operator_pilot',
    student_wallets_enabled: _student_wallets_enabled = false,
    circleAppId: circleAppIdProp = null,
    controlledPayments = [],
    currency,
}: StudentPayPageProps) {
    const [mode, setMode] = useState<'tuition' | 'bill'>(
        initialMode === 'bill' || (!tuitionAccount && bills.length > 0)
            ? 'bill'
            : 'tuition',
    );

    const payableBills = useMemo(
        () =>
            bills.filter(
                (b) =>
                    BigInt(b.remaining_base_units) > 0n && b.status !== 'void',
            ),
        [bills],
    );

    const [selectedBillId, setSelectedBillId] = useState<number | null>(() => {
        if (initialBillId && bills.some((b) => b.id === initialBillId)) {
            return initialBillId;
        }

        return payableBills[0]?.id ?? bills[0]?.id ?? null;
    });

    const selectedBill = useMemo(
        () => bills.find((b) => b.id === selectedBillId) ?? null,
        [bills, selectedBillId],
    );

    const snapshot = wallet?.snapshot;
    const walletAddress = wallet?.address ?? null;
    const hasWallet = Boolean(walletAddress);
    const studentRail = tuition_payment_mode === 'student_controlled';
    const circleWallet = wallet?.provider === 'circle-user';
    const circleAppId = circleAppIdProp;
    const sdk = useRef<import('@circle-fin/w3s-pw-web-sdk').W3SSdk | null>(
        null,
    );

    const postJson = async <T,>(url: string, data: object = {}): Promise<T> => {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': readCsrfToken(),
                Accept: 'application/json',
            },
            body: JSON.stringify(data),
        });
        const payload = await response.json();
        if (!response.ok) {
            throw new Error(
                (payload?.errors
                    ? Object.values(payload.errors as Record<string, string[]>)
                          .flat()
                          .join(' ')
                    : null) ??
                    payload?.message ??
                    'Request failed.',
            );
        }

        return payload as T;
    };

    const arcTestnetProvider = async (): Promise<EIP1193Provider> => {
        const ethereum = (window as unknown as { ethereum?: EIP1193Provider })
            .ethereum;
        if (!ethereum)
            throw new Error(
                'No browser wallet detected. Use your Circle PIN wallet instead.',
            );
        try {
            await ethereum.request({
                method: 'wallet_switchEthereumChain',
                params: [{ chainId: '0x4cef52' }],
            });
        } catch (error) {
            if ((error as { code?: number }).code !== 4902) throw error;
            await ethereum.request({
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
                        rpcUrls: ['https://rpc.testnet.arc.io'],
                        blockExplorerUrls: ['https://testnet.arcscan.app'],
                    },
                ],
            });
        }
        if ((await ethereum.request({ method: 'eth_chainId' })) !== '0x4cef52')
            throw new Error('Switch to Arc Testnet 5042002 first.');

        return ethereum;
    };

    const sendControlledTransfer = async (
        eth: EIP1193Provider,
        sender: `0x${string}`,
        quote: TuitionQuote,
    ): Promise<string> => {
        const client = createPublicClient({
            chain: arcTestnet,
            transport: custom(eth),
        });
        if (!quote.wallet_token || !quote.wallet_data)
            throw new Error('Quote is missing transfer details. Review again.');
        const request = {
            account: sender,
            to: quote.wallet_token,
            data: quote.wallet_data,
            value: 0n,
        };
        const gas = await client.estimateGas(request);
        const gasPrice = await client.getGasPrice();
        const maxFeePerGas = gasPrice * 2n;

        return eth.request({
            method: 'eth_sendTransaction',
            params: [
                {
                    from: sender,
                    to: quote.wallet_token,
                    data: quote.wallet_data,
                    value: '0x0',
                    gas: toHex(gas),
                    maxFeePerGas: toHex(maxFeePerGas),
                    maxPriorityFeePerGas: '0x0',
                },
            ],
        });
    };

    const walletBalanceBaseUnits = useMemo(() => {
        if (!snapshot?.balance) {
            return 0;
        }
        const [whole = '0', fraction = ''] = snapshot.balance.split('.');
        if (!/^\d+$/.test(whole)) {
            return 0;
        }
        const padded = fraction.slice(0, 6).padEnd(6, '0');

        return Number(BigInt(whole) * 1_000_000n + BigInt(padded));
    }, [snapshot?.balance]);

    const tuitionRemainingBase = Number(tuitionAccount?.remaining_amount ?? 0);
    const tuitionRemainingUsdc = tuitionRemainingBase / 1_000_000;

    const defaultTuitionPayBaseUnits = Math.min(
        tuitionRemainingBase,
        walletBalanceBaseUnits > 0
            ? walletBalanceBaseUnits
            : tuitionRemainingBase,
    );

    const [payAmount, setPayAmount] = useState<string>(() => {
        if (initialMode === 'tuition' && initialAmount) {
            return initialAmount;
        }
        if (defaultTuitionPayBaseUnits > 0) {
            return (defaultTuitionPayBaseUnits / 1_000_000).toFixed(2);
        }

        return '';
    });

    const [payQuote, setPayQuote] = useState<TuitionQuote | null>(null);
    const [payError, setPayError] = useState<string | null>(null);
    const [quoting, setQuoting] = useState(false);
    const [clearingControlledId, setClearingControlledId] = useState<
        string | null
    >(null);
    const [checkingCircleId, setCheckingCircleId] = useState<string | null>(
        null,
    );
    const [manualControlledHash, setManualControlledHash] =
        useState<string>('');

    const unpostedControlled = controlledPayments.find((p) => !p.posted);

    const handleCancelControlled = async (paymentId: string) => {
        setClearingControlledId(paymentId);
        try {
            const response = await fetch(cancelControlled.url(paymentId), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': readCsrfToken(),
                    Accept: 'application/json',
                },
            });
            if (response.ok) {
                toast.success('Unbroadcast attempt cleared.');
                setPayError(null);
                router.reload({
                    only: ['controlledPayments', 'tuitionAccount', 'wallet'],
                });
            } else {
                const data = await response.json();
                toast.error(data.message ?? 'Could not clear attempt.');
            }
        } catch {
            toast.error('Could not reach the server to clear attempt.');
        } finally {
            setClearingControlledId(null);
        }
    };

    const handleResumeCircle = async (paymentId: string) => {
        setPaying(true);
        setPayError(null);
        try {
            toast.loading('Connecting to your Circle wallet…', {
                id: 'circle-approval',
            });
            if (!circleAppId)
                throw new Error('Circle wallets are not configured.');
            const { W3SSdk } = await loadCircleSdk();
            sdk.current ??= new W3SSdk({ appSettings: { appId: circleAppId } });
            await sdk.current.getDeviceId();
            const session = await postJson<{
                userToken: string;
                encryptionKey: string;
            }>(circleSession.url());
            sdk.current.setAuthentication(session);
            toast.loading('Requesting existing Circle approval…', {
                id: 'circle-approval',
            });
            const challenge = await postJson<{ challengeId: string }>(
                circlePay.url(paymentId),
                { consent: true },
            );
            toast.loading('Enter your PIN in the Circle approval window.', {
                id: 'circle-approval',
            });
            await executeCircleChallenge(sdk.current, challenge.challengeId);
            toast.success('Circle approval completed. Checking settlement…', {
                id: 'circle-approval',
            });
            await handleCheckCircleSettlement(paymentId);
        } catch (error) {
            const message =
                error instanceof Error
                    ? error.message
                    : 'Could not resume Circle approval.';
            setPayError(message);
            toast.error(message, { id: 'circle-approval', duration: 12000 });
        } finally {
            setPaying(false);
            router.reload({
                only: ['controlledPayments', 'tuitionAccount', 'wallet'],
            });
        }
    };

    const handleVerifyControlledHash = async (
        paymentId: string,
        txHash: string,
    ) => {
        if (!txHash) return;
        setPaying(true);
        setPayError(null);
        try {
            await postJson(confirmControlled.url(paymentId), {
                tx_hash: txHash,
            });
            toast.success(
                'Settlement verified. Tuition credited on Arc Testnet.',
                { id: 'circle-approval' },
            );
            setPayQuote(null);
            setManualControlledHash('');
            router.reload({
                only: [
                    'controlledPayments',
                    'tuitionAttempts',
                    'tuitionAccount',
                    'wallet',
                ],
            });
        } catch (error) {
            setPayError(
                error instanceof Error
                    ? error.message
                    : 'Could not verify transaction.',
            );
        } finally {
            setPaying(false);
        }
    };

    const observeCirclePayment = (paymentId: string) =>
        watchCircleSettlement(
            () =>
                postJson<{ posted: boolean; tx_hash?: string; state?: string }>(
                    circleStatus.url(paymentId),
                ),
            () =>
                toast.loading(
                    'Payment approved. Waiting for Arc confirmation…',
                    { id: 'circle-approval' },
                ),
        );

    const handleCheckCircleSettlement = async (paymentId: string) => {
        setCheckingCircleId(paymentId);
        try {
            const status = await observeCirclePayment(paymentId);
            if (status.posted) {
                toast.success(
                    'Settlement verified. Tuition credited on Arc Testnet.',
                    { id: 'circle-approval' },
                );
                router.reload({
                    only: [
                        'controlledPayments',
                        'tuitionAttempts',
                        'tuitionAccount',
                        'wallet',
                    ],
                });
            } else {
                toast.info(
                    'Payment is still processing. Your existing payment is saved; do not send again.',
                    { id: 'circle-approval' },
                );
            }
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Could not check Circle status.',
            );
        } finally {
            setCheckingCircleId(null);
        }
    };
    const [paying, setPaying] = useState(false);
    const [consent, setConsent] = useState(false);
    const [receipt, setReceipt] = useState<TuitionAttempt | null>(null);
    const [officialReceipt, setOfficialReceipt] =
        useState<OfficialPaymentReceipt | null>(null);
    const [qrModal, setQrModal] = useState<{
        title: string;
        address: string;
        qr: string;
    } | null>(null);
    const [showTechnicalDetails, setShowTechnicalDetails] = useState(false);
    const [refreshingWallet, setRefreshingWallet] = useState(false);
    const [clearingHoldId, setClearingHoldId] = useState<number | null>(null);

    const [requestKey, setRequestKey] = useRemember<string | null>(
        null,
        `tuition-pay-page-request:${tuitionAccount?.id}`,
    );

    // Bill transfer state
    const [billAmount, setBillAmount] = useState<string>(() => {
        if (initialMode === 'bill' && initialAmount) {
            return initialAmount;
        }
        if (selectedBill) {
            return (
                Number(selectedBill.remaining_base_units) / 1_000_000
            ).toFixed(2);
        }

        return '';
    });
    const [billClassification, setBillClassification] =
        useState<BillClassification | null>(null);
    const [billUnavailableReason, setBillUnavailableReason] = useState<
        string | null
    >(null);
    const [billMountedQuote, setBillMountedQuote] =
        useState<BillMountedQuote | null>(null);
    const [billBusy, setBillBusy] = useState(false);
    const [billError, setBillError] = useState<string | null>(null);

    // Quote countdown clock
    const [nowMs, setNowMs] = useState(() => Date.now());
    useEffect(() => {
        const interval = setInterval(() => {
            setNowMs(Date.now());
        }, 1000);

        return () => clearInterval(interval);
    }, []);

    const activeExpiryIso =
        mode === 'tuition'
            ? payQuote?.expires_at
            : billMountedQuote?.expires_at;

    const quoteSecondsRemaining = useMemo(() => {
        if (!activeExpiryIso) {
            return null;
        }
        const target = new Date(activeExpiryIso).getTime();
        if (Number.isNaN(target)) {
            return null;
        }

        return Math.max(0, Math.floor((target - nowMs) / 1000));
    }, [activeExpiryIso, nowMs]);

    const quoteRequestIdRef = useRef(0);
    const abortControllerRef = useRef<AbortController | null>(null);
    const inFlightQuotePromiseRef = useRef<Promise<TuitionQuote | null> | null>(
        null,
    );

    const activeReceipt = receipt
        ? (tuitionAttempts.find(
              (a) =>
                  a.id === receipt.id ||
                  Boolean(
                      a.request_key && a.request_key === receipt.request_key,
                  ),
          ) ?? receipt)
        : null;

    const inFlightAttempt =
        tuitionAttempts?.find(
            (a) =>
                ([
                    'prepared',
                    'submitting',
                    'accepted',
                    'awaiting_verification',
                    'unknown',
                ].includes(a.state) &&
                    a.stage !== 'needs_operator_review' &&
                    a.stage !== 'preflight_blocked' &&
                    a.stage !== 'quote_expired_unsubmitted') ||
                (a.state === 'verified' && !a.posted),
        ) ??
        (activeReceipt &&
        (([
            'prepared',
            'submitting',
            'accepted',
            'awaiting_verification',
            'unknown',
        ].includes(activeReceipt.state) &&
            activeReceipt.stage !== 'needs_operator_review' &&
            activeReceipt.stage !== 'preflight_blocked' &&
            activeReceipt.stage !== 'quote_expired_unsubmitted') ||
            (activeReceipt.state === 'verified' && !activeReceipt.posted))
            ? activeReceipt
            : null);

    const hasTuitionInFlight = Boolean(inFlightAttempt);

    const hasBillInFlight = billPayments.some(
        (p) =>
            !p.is_allocated &&
            !p.verified &&
            [
                'consented',
                'prepared',
                'submitting',
                'accepted',
                'awaiting_verification',
            ].includes(p.submission_state),
    );

    const trackerAttempts = useMemo(() => {
        if (!receipt) {
            return tuitionAttempts;
        }
        const alreadyPresent = tuitionAttempts.some(
            (a) =>
                a.id === receipt.id ||
                Boolean(a.request_key && a.request_key === receipt.request_key),
        );
        if (alreadyPresent) {
            return tuitionAttempts;
        }

        return [receipt, ...tuitionAttempts];
    }, [receipt, tuitionAttempts]);

    const shouldFastPoll =
        hasTuitionInFlight ||
        hasBillInFlight ||
        Boolean(
            activeReceipt &&
            !activeReceipt.posted &&
            activeReceipt.state !== 'failed' &&
            !activeReceipt.simulated,
        );

    usePoll(shouldFastPoll ? 2500 : 8000, {
        only: [
            'controlledPayments',
            'tuitionAttempts',
            'tuitionAccount',
            'wallet',
            'bills',
            'billPayments',
        ],
    });

    const prevPostedRef = useRef(Boolean(activeReceipt?.posted));
    useEffect(() => {
        if (!prevPostedRef.current && activeReceipt?.posted) {
            toast.success('Tuition transfer settled & posted!', {
                description: `${formatBaseUnits(activeReceipt.applied_base_units)} USDC credited to your tuition ledger on Arc Testnet.`,
            });
        }
        prevPostedRef.current = Boolean(activeReceipt?.posted);
    }, [activeReceipt?.posted, activeReceipt?.applied_base_units]);

    const buildInstantQuote = useCallback(
        (baseUnits: number): TuitionQuote => ({
            network_fee: '0.000000',
            total: (baseUnits / 1_000_000).toFixed(2),
            payer_debit: (baseUnits / 1_000_000).toFixed(2),
            fee_paid_by_payer: false,
            max_amount_base_units: tuitionRemainingBase,
            amount_base_units: baseUnits,
            balance: snapshot?.balance ?? null,
            chain: treasury.chain || 'ARC-TESTNET',
            request_digest: '',
            consent_text:
                'I consent to the transfer of USDC on Arc Testnet for tuition.',
            expires_at: '',
            fee_ceiling_base_units: 0,
        }),
        [tuitionRemainingBase, snapshot?.balance, treasury.chain],
    );

    const requestTuitionQuote = useCallback(
        async (baseUnits: number): Promise<TuitionQuote | null> => {
            if (!tuitionAccount?.id) {
                return null;
            }
            if (studentRail) {
                abortControllerRef.current?.abort();
                const controller = new AbortController();
                abortControllerRef.current = controller;
                const requestId = ++quoteRequestIdRef.current;
                setQuoting(true);
                setConsent(false);
                setPayError(null);
                try {
                    const response = await fetch(estimateStudentFee.url(), {
                        method: 'POST',
                        credentials: 'same-origin',
                        signal: controller.signal,
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': readCsrfToken(),
                            Accept: 'application/json',
                        },
                        body: JSON.stringify({
                            kind: 'tuition',
                            account_id: tuitionAccount.id,
                            amount: (baseUnits / 1_000_000).toFixed(6),
                        }),
                    });
                    const payload = await response.json();
                    if (requestId !== quoteRequestIdRef.current) return null;
                    if (!response.ok)
                        throw new Error(
                            Object.values(payload.errors ?? {})
                                .flat()
                                .join(' ') || 'Gas estimate unavailable.',
                        );
                    const quote = {
                        ...buildInstantQuote(baseUnits),
                        estimated_student_fee: payload.estimated_fee ?? null,
                    };
                    setPayQuote(quote);
                    return quote;
                } catch (error) {
                    if (
                        error instanceof DOMException &&
                        error.name === 'AbortError'
                    )
                        return null;
                    if (requestId === quoteRequestIdRef.current) {
                        setPayQuote({
                            ...buildInstantQuote(baseUnits),
                            estimated_student_fee: null,
                        });
                        setPayError(
                            'Gas estimate unavailable. Your wallet will show final fees before approval.',
                        );
                    }
                    return null;
                } finally {
                    if (requestId === quoteRequestIdRef.current)
                        setQuoting(false);
                }
            }
            if (abortControllerRef.current) {
                abortControllerRef.current.abort();
            }
            const controller = new AbortController();
            abortControllerRef.current = controller;

            const requestId = ++quoteRequestIdRef.current;
            setQuoting(true);
            setPayError(null);

            const promise = (async () => {
                try {
                    const response = await fetch(tuitionPayQuote.url(), {
                        method: 'POST',
                        credentials: 'same-origin',
                        signal: controller.signal,
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': readCsrfToken(),
                            Accept: 'application/json',
                        },
                        body: JSON.stringify({
                            tuition_account_id: tuitionAccount.id,
                            amount_base_units: baseUnits,
                        }),
                    });

                    const payload = (await response.json()) as
                        | (TuitionQuote & {
                              message?: string;
                              errors?: Record<string, string[]>;
                          })
                        | undefined;

                    if (requestId !== quoteRequestIdRef.current) {
                        return null;
                    }

                    if (!response.ok) {
                        const message =
                            payload?.message ??
                            (payload?.errors
                                ? Object.values(payload.errors).flat().join(' ')
                                : null) ??
                            'Could not quote this payment on the chain.';
                        setPayError(message);
                        setPayQuote(null);
                        setRequestKey(null);

                        return null;
                    }

                    const quote = payload as TuitionQuote;
                    setPayQuote(quote);
                    setRequestKey(quote.request_key ?? null);

                    return quote;
                } catch (err: unknown) {
                    if (
                        err instanceof DOMException &&
                        err.name === 'AbortError'
                    ) {
                        return null;
                    }
                    if (requestId === quoteRequestIdRef.current) {
                        setPayError('Could not reach the payment network.');
                        setPayQuote(null);
                        setRequestKey(null);
                    }

                    return null;
                } finally {
                    if (requestId === quoteRequestIdRef.current) {
                        setQuoting(false);
                        inFlightQuotePromiseRef.current = null;
                    }
                }
            })();

            inFlightQuotePromiseRef.current = promise;

            return promise;
        },
        [tuitionAccount?.id, studentRail, setRequestKey],
    );

    // Automatically quote when payAmount changes in tuition mode
    useEffect(() => {
        if (mode !== 'tuition' || !tuitionAccount?.id || !hasWallet) {
            return;
        }
        const baseUnits = parseBaseUnits(payAmount);
        if (baseUnits === null || baseUnits <= 0) {
            if (abortControllerRef.current) {
                abortControllerRef.current.abort();
            }
            setQuoting(false);

            return;
        }

        const timer = setTimeout(() => {
            void requestTuitionQuote(baseUnits);
        }, 220);

        return () => clearTimeout(timer);
    }, [mode, payAmount, tuitionAccount?.id, hasWallet, requestTuitionQuote]);

    useEffect(() => {
        return () => {
            if (abortControllerRef.current) {
                abortControllerRef.current.abort();
            }
        };
    }, []);

    const selectQuickTuitionAmount = (baseUnits: number) => {
        const clamped = Math.max(
            1,
            Math.min(
                baseUnits,
                tuitionRemainingBase > 0 ? tuitionRemainingBase : baseUnits,
            ),
        );
        const formatted = (clamped / 1_000_000).toFixed(2);
        setPayAmount(formatted);
        setConsent(false);
        setPayError(null);
        setPayQuote(buildInstantQuote(clamped));
        void requestTuitionQuote(clamped);
    };

    /**
     * Student-owned wallet: we never sign. The student approves inside their own
     * wallet, then the server verifies settlement on Arc Testnet.
     */
    const submitStudentTransfer = async (
        baseUnits: number,
        paymentId: string | null,
        quoteOverride?: TuitionQuote,
    ) => {
        if (!paymentId) {
            setPaying(false);
            setPayError('Lock a fresh network quote before approving.');

            return;
        }

        try {
            if (circleWallet) {
                toast.loading('Connecting to your Circle wallet…', {
                    id: 'circle-approval',
                });
                if (!sdk.current) {
                    if (!circleAppId)
                        throw new Error(
                            'Circle wallets are not configured. Reconnect on the wallet screen.',
                        );
                    const { W3SSdk } = await loadCircleSdk();
                    sdk.current = new W3SSdk({
                        appSettings: { appId: circleAppId },
                    });
                    await sdk.current.getDeviceId();
                }
                const session = await postJson<{
                    userToken: string;
                    encryptionKey: string;
                    challengeId: string | null;
                }>(circleSession.url());
                sdk.current?.setAuthentication({
                    userToken: session.userToken,
                    encryptionKey: session.encryptionKey,
                });
                if (session.challengeId)
                    await executeCircleChallenge(
                        sdk.current!,
                        session.challengeId,
                    );
                toast.loading('Requesting Circle payment approval…', {
                    id: 'circle-approval',
                });
                const challenge = await postJson<{ challengeId: string }>(
                    circlePay.url(paymentId),
                    { consent: true },
                );
                toast.loading('Enter your PIN in the Circle approval window.', {
                    id: 'circle-approval',
                });
                await executeCircleChallenge(
                    sdk.current!,
                    challenge.challengeId,
                );
                toast.dismiss('circle-approval');
                toast.success('Approved in your Circle wallet.', {
                    description: 'Checking Arc Testnet settlement…',
                });
                const status = await observeCirclePayment(paymentId);
                if (status.tx_hash)
                    localStorage.setItem(
                        `student-payment-${paymentId}`,
                        status.tx_hash,
                    );
                if (!status.posted) {
                    toast.info(
                        'Payment is still processing. Progress is saved automatically; do not send again.',
                        { id: 'circle-approval' },
                    );
                    router.reload({
                        only: ['tuitionAttempts', 'controlledPayments'],
                    });
                    return;
                }
            } else {
                const eth = await arcTestnetProvider();
                const [sender] = await eth.request({
                    method: 'eth_requestAccounts',
                });
                const quote = quoteOverride ?? payQuote;
                if (
                    !quote?.wallet_sender ||
                    (sender as string).toLowerCase() !==
                        quote.wallet_sender.toLowerCase()
                ) {
                    throw new Error(
                        'Selected wallet differs from your verified student wallet.',
                    );
                }
                await postJson(startControlled.url(paymentId), {
                    consent: true,
                });
                const hash = await sendControlledTransfer(
                    eth,
                    sender as `0x${string}`,
                    quote,
                );
                localStorage.setItem(`student-payment-${paymentId}`, hash);
                toast.success('Transfer submitted to Arc Testnet.', {
                    description: 'Verifying settlement…',
                });
                await postJson(confirmControlled.url(paymentId), {
                    tx_hash: hash,
                });
            }

            toast.success(
                'Settlement verified. Tuition credited on Arc Testnet.',
                { id: 'circle-approval' },
            );
            setPayQuote(null);
            await postJson(walletRefresh.url()).catch(() => undefined);
            router.reload({
                only: [
                    'tuitionAttempts',
                    'controlledPayments',
                    'tuitionAccount',
                    'wallet',
                ],
            });
        } catch (error: unknown) {
            const message =
                error instanceof Error
                    ? error.message
                    : 'Payment could not be completed.';
            setPayError(message);
            toast.error(message, { id: 'circle-approval', duration: 12000 });
            router.reload({ only: ['tuitionAttempts', 'controlledPayments'] });
        } finally {
            setPaying(false);
        }
    };

    const submitTuitionTransfer = async () => {
        const baseUnits = parseBaseUnits(payAmount);
        if (baseUnits === null || baseUnits <= 0) {
            setPayError(
                'Enter a valid USDC amount with at most 6 decimal places.',
            );

            return;
        }

        if (!consent) {
            setPayError(
                'Check the cryptographic consent box before broadcasting.',
            );

            return;
        }

        if (studentRail) {
            setPaying(true);
            setPayError(null);
            try {
                const response = await fetch(studentQuote.url(), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': readCsrfToken(),
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({
                        kind: 'tuition',
                        account_id: tuitionAccount?.id,
                        amount: (baseUnits / 1_000_000).toFixed(6),
                    }),
                });
                const payload = (await response.json()) as
                    | {
                          id: string;
                          estimated_fee?: string | null;
                          snapshot: {
                              amount_base_units: number;
                              sender: `0x${string}`;
                              recipient: `0x${string}`;
                              token: `0x${string}`;
                              data: `0x${string}`;
                          };
                          message?: string;
                          errors?: Record<string, string[]>;
                      }
                    | undefined;

                if (!response.ok) {
                    const message =
                        payload?.message ??
                        (payload?.errors
                            ? Object.values(payload.errors).flat().join(' ')
                            : null) ??
                        'Could not prepare the payment quote.';
                    setPayError(message);

                    return;
                }

                const scoped = payload!;
                const quote: TuitionQuote = {
                    ...buildInstantQuote(scoped.snapshot.amount_base_units),
                    estimated_student_fee: scoped.estimated_fee ?? null,
                    wallet_sender: scoped.snapshot.sender,
                    wallet_recipient: scoped.snapshot.recipient,
                    wallet_token: scoped.snapshot.token,
                    wallet_data: scoped.snapshot.data,
                    request_key: scoped.id,
                };
                setPayQuote(quote);
                await submitStudentTransfer(baseUnits, scoped.id, quote);
            } catch (err: unknown) {
                setPayError(
                    err instanceof Error
                        ? err.message
                        : 'Could not prepare the payment quote.',
                );
            } finally {
                setPaying(false);
            }

            return;
        }

        let currentQuote = payQuote;
        let currentKey = requestKey;

        const isCurrentQuoteMatching =
            currentQuote &&
            currentQuote.amount_base_units === baseUnits &&
            currentKey &&
            (!currentQuote.expires_at ||
                new Date(currentQuote.expires_at).getTime() > Date.now());

        if (!isCurrentQuoteMatching) {
            setPaying(true);
            setPayError(null);

            let quotePromise = inFlightQuotePromiseRef.current;
            if (!quotePromise) {
                quotePromise = requestTuitionQuote(baseUnits);
            }

            try {
                const quoteResult = await quotePromise;
                if (!quoteResult) {
                    setPaying(false);

                    return;
                }
                currentQuote = quoteResult;
                currentKey = quoteResult.request_key ?? null;
            } catch {
                setPaying(false);

                return;
            }
        }

        if (!currentQuote || !currentKey) {
            setPaying(false);
            setPayError('Lock a fresh network quote before signing.');

            return;
        }

        setPaying(true);
        setPayError(null);

        try {
            const response = await fetch(tuitionPayStore.url(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': readCsrfToken(),
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    tuition_account_id: tuitionAccount?.id,
                    amount_base_units: baseUnits,
                    request_key: currentKey,
                    request_digest: currentQuote.request_digest,
                    consent: true,
                }),
            });

            const payload = await response.json();

            if (!response.ok) {
                const message =
                    (payload?.errors
                        ? Object.values(
                              payload.errors as Record<string, string[]>,
                          )
                              .flat()
                              .join(' ')
                        : null) ?? 'The transfer could not be broadcast.';
                setPayError(message);

                return;
            }

            setReceipt(payload as TuitionAttempt);
            toast.success('Transfer signed & dispatched to Arc Testnet', {
                description:
                    'Watching live block confirmation and ledger settlement…',
            });

            router.reload({
                only: [
                    'tuitionAttempts',
                    'controlledPayments',
                    'tuitionAccount',
                    'wallet',
                ],
            });
        } catch {
            setPayError(
                'Connection interrupted while broadcasting. Check live settlement telemetry on the right before retrying.',
            );
            router.reload({ only: ['tuitionAttempts', 'controlledPayments'] });
        } finally {
            setPaying(false);
        }
    };

    const handleClearExpiredHold = async (attemptId: number) => {
        setClearingHoldId(attemptId);
        try {
            const response = await fetch(clearTuitionHold.url(attemptId), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': readCsrfToken(),
                    Accept: 'application/json',
                },
            });
            if (response.ok) {
                toast.success('Unsubmitted hold cleared.');
                router.reload({
                    only: ['tuitionAttempts', 'tuitionAccount'],
                });
            }
        } catch {
            toast.error('Could not clear hold right now.');
        } finally {
            setClearingHoldId(null);
        }
    };

    // Bill helpers
    const selectBill = (bill: BillItem) => {
        setSelectedBillId(bill.id);
        setBillAmount(
            (Number(bill.remaining_base_units) / 1_000_000).toFixed(2),
        );
        setBillClassification(null);
        setBillUnavailableReason(null);
        setBillMountedQuote(null);
        setBillError(null);
    };

    const runBillClassification = async () => {
        if (!selectedBill) {
            return;
        }
        setBillBusy(true);
        setBillError(null);
        try {
            const response = await fetch(billClassify.url(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': readCsrfToken(),
                    Accept: 'application/json',
                },
                body: JSON.stringify({ student_bill_id: selectedBill.id }),
            });
            if (!response.ok) {
                throw new Error('Classification request failed');
            }
            const data = await response.json();
            setBillClassification(data.classification);
            setBillUnavailableReason(data.unavailable_reason);
        } catch {
            setBillClassification(null);
            setBillUnavailableReason(
                'Advisory classification is unavailable. Nothing about this bill or payment depends on it.',
            );
        } finally {
            setBillBusy(false);
        }
    };

    const mountBillQuote = async () => {
        if (!selectedBill) {
            return;
        }
        const units = parseBaseUnits(billAmount);
        if (units === null) {
            setBillError(
                'Enter a valid USDC amount with at most 6 decimal places.',
            );

            return;
        }

        setBillBusy(true);
        setBillError(null);
        try {
            const response = await fetch(billQuote.url(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': readCsrfToken(),
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    student_bill_id: selectedBill.id,
                    amount_base_units: units,
                    classification: billClassification,
                }),
            });

            const body = await response.json().catch(() => ({}));
            if (!response.ok) {
                const msg =
                    body?.message ??
                    (body?.errors
                        ? Object.values(body.errors as Record<string, string[]>)
                              .flat()
                              .join(' ')
                        : null) ??
                    'Could not lock a quote for this bill.';
                setBillError(msg);

                return;
            }

            setBillMountedQuote(body as BillMountedQuote);
            toast.success('Bill transfer quote locked for 120 seconds.');
        } catch (err) {
            setBillError(
                err instanceof Error ? err.message : 'Could not quote bill.',
            );
        } finally {
            setBillBusy(false);
        }
    };

    const acceptBillQuote = async () => {
        if (!selectedBill || !billMountedQuote) {
            return;
        }
        setBillBusy(true);
        setBillError(null);
        try {
            const response = await fetch(billConsent.url(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': readCsrfToken(),
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    student_bill_id: selectedBill.id,
                    amount_base_units: billMountedQuote.amount_base_units,
                    request_key: billMountedQuote.request_key,
                    request_digest: billMountedQuote.request_digest,
                    consent: true,
                }),
            });

            const body = await response.json().catch(() => ({}));
            if (!response.ok) {
                const msg =
                    body?.message ??
                    (body?.errors
                        ? Object.values(body.errors as Record<string, string[]>)
                              .flat()
                              .join(' ')
                        : null) ??
                    'Consent could not be recorded.';
                setBillError(msg);

                return;
            }

            setBillMountedQuote(null);
            toast.success('Bill payment signed & queued for Arc settlement.');
            router.reload({ only: ['bills', 'billPayments', 'wallet'] });
        } catch (err) {
            setBillError(
                err instanceof Error
                    ? err.message
                    : 'Consent could not be recorded.',
            );
        } finally {
            setBillBusy(false);
        }
    };

    const copyToClipboard = (value: string, label: string) => {
        navigator.clipboard.writeText(value);
        toast.success(`${label} copied to clipboard`);
    };

    const handleRefreshWallet = () => {
        setRefreshingWallet(true);
        router.post(
            walletRefresh.url(),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    const refreshed = page.props
                        .wallet as StudentPayPageProps['wallet'];
                    if (refreshed?.snapshot?.is_available) {
                        toast.success('On-chain balance synced from Arc RPC.');
                    } else {
                        toast.error(
                            refreshed?.snapshot?.reason ??
                                'Live wallet balance is unavailable.',
                        );
                    }
                },
                onError: () => toast.error('Could not refresh wallet balance.'),
                onFinish: () => setRefreshingWallet(false),
            },
        );
    };

    // Compute live fiat approximation for the typed amount
    const activeAmountString = mode === 'tuition' ? payAmount : billAmount;
    const activeBaseUnits = parseBaseUnits(activeAmountString);
    const liveFiatPreview = useMemo(() => {
        if (
            activeBaseUnits === null ||
            activeBaseUnits <= 0 ||
            !currency?.units_per_usdc
        ) {
            return null;
        }
        // units_per_usdc is minor fiat units per 1 USDC (1_000_000 base units)
        const minorFiat =
            (BigInt(activeBaseUnits) * BigInt(currency.units_per_usdc)) /
            1_000_000n;
        const whole = (minorFiat / 100n).toLocaleString('en-US');
        const cents = (minorFiat % 100n).toString().padStart(2, '0');

        return `${currency.symbol}${whole}.${cents} ${currency.display}`;
    }, [activeBaseUnits, currency]);

    const tuitionCompletionPercent = useMemo(() => {
        if (!tuitionAccount) {
            return 0;
        }
        const total = Number(tuitionAccount.total_amount);
        const paid = Number(tuitionAccount.paid_amount);
        if (total <= 0) {
            return 100;
        }

        return Math.min(100, Math.round((paid / total) * 100));
    }, [tuitionAccount]);

    return (
        <>
            <Head title="Send USDC Transfer · Arc Wallet" />

            <div className="bulletin clay-ambient min-h-full w-full">
                <div className="mx-auto flex w-full max-w-[1380px] flex-col gap-6 px-4 py-6 sm:px-6 sm:py-8 lg:px-8 lg:py-10">
                    {controlledPayments[0]?.progress && (
                        <section
                            className="clay-card p-5 sm:p-6"
                            aria-labelledby="payment-progress-heading"
                        >
                            <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p className="bulletin-eyebrow">
                                        Payment activity
                                    </p>
                                    <h2
                                        id="payment-progress-heading"
                                        className="clay-h2"
                                    >
                                        Receipt and policy progress
                                    </h2>
                                </div>
                                <span className="clay-meta text-xs">
                                    Recorded events · refreshes automatically
                                </span>
                            </div>
                            <ol
                                className="space-y-4 border-l border-[var(--clay-border)] pl-5"
                                aria-live="polite"
                                aria-relevant="additions text"
                            >
                                {controlledPayments[0].progress.map(
                                    (event, index) => (
                                        <li
                                            key={`${event.stage}-${index}`}
                                            className="relative space-y-1"
                                        >
                                            <span
                                                aria-hidden="true"
                                                className="absolute -left-[25px] top-1 size-2 rounded-full bg-[var(--clay-primary)]"
                                            />
                                            <div className="flex flex-wrap items-center gap-2 text-xs">
                                                <span className="font-semibold capitalize">
                                                    {event.stage.replaceAll(
                                                        '_',
                                                        ' ',
                                                    )}
                                                </span>
                                                <span className="clay-meta">
                                                    {event.status}
                                                </span>
                                                {event.at && (
                                                    <time
                                                        className="clay-meta"
                                                        dateTime={event.at}
                                                    >
                                                        {new Date(
                                                            event.at,
                                                        ).toLocaleString()}
                                                    </time>
                                                )}
                                            </div>
                                            <p className="clay-meta max-w-3xl text-sm">
                                                {event.message}
                                            </p>
                                        </li>
                                    ),
                                )}
                            </ol>
                            <p className="clay-meta mt-5 border-t border-[var(--clay-border)] pt-3 text-xs">
                                Tuition credit and institution spending are
                                separate. Classification does not authorize a
                                transfer.
                            </p>
                        </section>
                    )}
                    {/* -- Top Bar: Back Link + Network Telemetry + Mode Switcher -- */}
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex flex-wrap items-center gap-3">
                            <Link
                                href={
                                    mode === 'bill'
                                        ? studentBills().url
                                        : studentDashboard().url
                                }
                                className="clay-focus inline-flex h-9 items-center gap-2 rounded-full border border-[var(--clay-border)] bg-[var(--clay-surface)] px-3.5 text-xs font-semibold text-[var(--clay-text)] transition-all hover:-translate-y-0.5 hover:border-[var(--clay-primary-bright)]/50"
                            >
                                <ArrowLeft className="size-3.5" />
                                {mode === 'bill'
                                    ? 'Back to Bills'
                                    : 'Back to Dashboard'}
                            </Link>

                            <div className="inline-flex items-center gap-2 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3.5 py-1.5 text-xs font-semibold text-emerald-400">
                                <span className="relative flex size-2">
                                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                                    <span className="relative inline-flex size-2 rounded-full bg-emerald-400" />
                                </span>
                                <span>{treasury.chain}</span>
                                <span className="text-emerald-500/60">·</span>
                                <span className="font-mono text-[11px]">
                                    Chain {treasury.chain_id}
                                </span>
                            </div>

                            <span className="hidden items-center gap-1.5 rounded-full border border-[var(--clay-border)] bg-[var(--clay-surface-soft)] px-3 py-1 text-[11px] font-medium text-[var(--clay-text-muted)] md:inline-flex">
                                <Zap className="size-3 text-[var(--clay-primary-bright)]" />
                                {studentRail
                                    ? 'Student wallet · USDC gas'
                                    : 'Circle agent payment rail'}
                            </span>
                        </div>

                        {/* Segmented Transfer Mode Switcher */}
                        <div className="inline-flex items-center self-start rounded-full border border-[var(--clay-border)] bg-[var(--clay-surface-soft)] p-1 sm:self-auto">
                            <button
                                type="button"
                                onClick={() => setMode('tuition')}
                                className={`clay-focus inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-xs font-semibold transition-all ${
                                    mode === 'tuition'
                                        ? 'bg-[var(--clay-primary)] text-[var(--clay-primary-foreground)] shadow-xs'
                                        : 'text-[var(--clay-text-muted)] hover:text-[var(--clay-text)]'
                                }`}
                            >
                                <Wallet className="size-3.5" />
                                Tuition Transfer
                                {tuitionRemainingBase > 0 && (
                                    <span
                                        className={`rounded-full px-1.5 py-0.2 text-[10px] font-mono ${
                                            mode === 'tuition'
                                                ? 'bg-black/20 text-white'
                                                : 'bg-[var(--clay-surface)] text-[var(--clay-text-muted)]'
                                        }`}
                                    >
                                        {tuitionRemainingUsdc.toFixed(0)} USDC
                                    </span>
                                )}
                            </button>
                            <button
                                type="button"
                                onClick={() => setMode('bill')}
                                className={`clay-focus inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-xs font-semibold transition-all ${
                                    mode === 'bill'
                                        ? 'bg-[var(--clay-primary)] text-[var(--clay-primary-foreground)] shadow-xs'
                                        : 'text-[var(--clay-text-muted)] hover:text-[var(--clay-text)]'
                                }`}
                            >
                                <ReceiptText className="size-3.5" />
                                Campus Bills
                                {payableBills.length > 0 && (
                                    <span
                                        className={`rounded-full px-1.5 py-0.2 text-[10px] font-mono ${
                                            mode === 'bill'
                                                ? 'bg-black/20 text-white'
                                                : 'bg-[var(--clay-surface)] text-[var(--clay-text-muted)]'
                                        }`}
                                    >
                                        {payableBills.length}
                                    </span>
                                )}
                            </button>
                        </div>
                    </div>

                    {/* -- Main Two-Column Crypto Wallet Transfer Layout -- */}
                    <div className="grid gap-6 lg:grid-cols-12 lg:items-start">
                        {/* =========================================================
                            LEFT COLUMN: MODERN CRYPTO WALLET SEND TERMINAL (7 cols)
                           ========================================================= */}
                        <div className="flex flex-col gap-5 lg:col-span-7">
                            <div className="clay-card relative overflow-hidden border-[var(--clay-border)] p-5 sm:p-7">
                                {/* Header: Wallet Identity & Token Selector Pill */}
                                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--clay-border)] pb-5">
                                    <div className="flex items-center gap-3">
                                        <span className="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-[var(--clay-primary)]/15 text-[var(--clay-primary-bright)] ring-1 ring-[var(--clay-primary-bright)]/30">
                                            <Send className="size-5" />
                                        </span>
                                        <div>
                                            <p className="bulletin-eyebrow">
                                                Circle Agent Wallet &middot;
                                                Send
                                            </p>
                                            <h1 className="clay-h2 mt-0.5">
                                                {mode === 'tuition'
                                                    ? 'Transfer Tuition USDC'
                                                    : 'Transfer Campus Bill USDC'}
                                            </h1>
                                        </div>
                                    </div>

                                    {/* Token Badge & Wallet Balance */}
                                    <div className="flex items-center gap-2 rounded-2xl border border-[var(--clay-border)] bg-[var(--clay-surface-soft)] px-3.5 py-2">
                                        <span className="flex size-7 items-center justify-center rounded-full bg-sky-500/20 font-mono text-xs font-bold text-sky-400 ring-1 ring-sky-400/30">
                                            $
                                        </span>
                                        <div className="text-left">
                                            <div className="flex items-center gap-1.5">
                                                <span className="text-xs font-bold text-[var(--clay-text)]">
                                                    USDC
                                                </span>
                                                <span className="rounded bg-[var(--clay-surface)] px-1.5 py-0.2 font-mono text-[10px] text-[var(--clay-text-muted)]">
                                                    Native
                                                </span>
                                            </div>
                                            <p className="font-mono text-[11px] text-[var(--clay-text-muted)]">
                                                Bal:{' '}
                                                {snapshot?.balance
                                                    ? formatBalance(
                                                          snapshot.balance,
                                                      )
                                                    : '—'}
                                            </p>
                                        </div>
                                        {hasWallet && (
                                            <button
                                                type="button"
                                                onClick={handleRefreshWallet}
                                                disabled={refreshingWallet}
                                                title="Sync live balance from Arc RPC"
                                                className="clay-focus ml-1 inline-flex size-7 items-center justify-center rounded-full text-[var(--clay-text-muted)] hover:bg-[var(--clay-surface)] hover:text-[var(--clay-text)]"
                                            >
                                                <RefreshCw
                                                    className={`size-3.5 ${refreshingWallet ? 'animate-spin' : ''}`}
                                                />
                                            </button>
                                        )}
                                    </div>
                                </div>

                                {!hasWallet ? (
                                    <div className="my-8 flex flex-col items-center gap-4 rounded-2xl border border-dashed border-[var(--clay-border)] p-8 text-center">
                                        <span className="clay-icon-chip size-12">
                                            <Wallet className="size-6" />
                                        </span>
                                        <div className="max-w-md space-y-1.5">
                                            <h2 className="clay-h2">
                                                No Active Student Wallet Linked
                                            </h2>
                                            <p className="clay-body">
                                                Link or generate your Circle
                                                Agent Wallet on Arc Testnet
                                                before initiating an on-chain
                                                transfer.
                                            </p>
                                        </div>
                                        <Link
                                            href={walletShow().url}
                                            className="clay-focus inline-flex h-10 items-center gap-2 rounded-full bg-[var(--clay-primary)] px-6 text-xs font-semibold text-[var(--clay-primary-foreground)]"
                                        >
                                            <Wallet className="size-4" />
                                            Open Wallet Settings
                                        </Link>
                                    </div>
                                ) : mode === 'tuition' ? (
                                    /* =================================================
                                       TUITION CRYPTO TRANSFER FORM
                                       ================================================= */
                                    <form
                                        className="mt-5 flex flex-col gap-5"
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            void submitTuitionTransfer();
                                        }}
                                    >
                                        {/* 1. OVERSIZED CRYPTO AMOUNT INPUT BOX */}
                                        <div className="clay-inset relative rounded-2xl border border-[var(--clay-border)] p-5 transition-colors focus-within:border-[var(--clay-primary-bright)]">
                                            <div className="flex items-center justify-between gap-2">
                                                <label
                                                    htmlFor="crypto_tuition_amount"
                                                    className="bulletin-eyebrow text-xs"
                                                >
                                                    You Send (Exact Principal)
                                                </label>
                                                <span className="font-mono text-xs text-[var(--clay-text-muted)]">
                                                    Owed:{' '}
                                                    <strong className="text-[var(--clay-text)]">
                                                        {tuitionRemainingUsdc.toFixed(
                                                            2,
                                                        )}{' '}
                                                        USDC
                                                    </strong>
                                                </span>
                                            </div>

                                            <div className="mt-3 flex items-baseline justify-between gap-3">
                                                <input
                                                    id="crypto_tuition_amount"
                                                    type="text"
                                                    inputMode="decimal"
                                                    placeholder="0.00"
                                                    value={payAmount}
                                                    onChange={(e) => {
                                                        const val =
                                                            e.target.value;
                                                        setPayAmount(val);
                                                        setConsent(false);
                                                        setPayError(null);
                                                        const units =
                                                            parseBaseUnits(val);
                                                        if (
                                                            units !== null &&
                                                            units > 0
                                                        ) {
                                                            setPayQuote(
                                                                buildInstantQuote(
                                                                    units,
                                                                ),
                                                            );
                                                        } else if (
                                                            val.trim() === ''
                                                        ) {
                                                            setPayQuote(null);
                                                        }
                                                    }}
                                                    className="w-full bg-transparent font-mono text-4xl font-bold tracking-tight text-[var(--clay-text)] tabular-nums focus:outline-none sm:text-5xl"
                                                    required
                                                />
                                                <span className="shrink-0 rounded-xl border border-[var(--clay-border)] bg-[var(--clay-surface)] px-3 py-1.5 font-mono text-sm font-bold text-[var(--clay-primary-bright)]">
                                                    USDC
                                                </span>
                                            </div>

                                            <div className="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-[var(--clay-text-muted)]">
                                                <span>
                                                    {liveFiatPreview
                                                        ? `≈ ${liveFiatPreview}`
                                                        : currency?.rate_description}
                                                </span>
                                                <span>
                                                    6-decimal base units:{' '}
                                                    <code className="font-mono text-[var(--clay-text)]">
                                                        {activeBaseUnits !==
                                                        null
                                                            ? activeBaseUnits.toLocaleString(
                                                                  'en-US',
                                                              )
                                                            : '0'}
                                                    </code>
                                                </span>
                                            </div>

                                            {/* Quick Percentage & Preset Amount Chips */}
                                            <div className="mt-4 flex flex-wrap items-center gap-1.5 border-t border-[var(--clay-border)]/60 pt-3.5">
                                                {[5, 10, 25, 50]
                                                    .filter(
                                                        (amt) =>
                                                            amt <=
                                                            tuitionRemainingUsdc,
                                                    )
                                                    .map((amt) => {
                                                        const units =
                                                            amt * 1_000_000;
                                                        const isSelected =
                                                            payAmount ===
                                                            amt.toFixed(2);
                                                        return (
                                                            <button
                                                                key={amt}
                                                                type="button"
                                                                onClick={() =>
                                                                    selectQuickTuitionAmount(
                                                                        units,
                                                                    )
                                                                }
                                                                className={`clay-focus rounded-full border px-3 py-1 font-mono text-xs font-semibold transition ${
                                                                    isSelected
                                                                        ? 'border-[var(--clay-primary-bright)] bg-[var(--clay-primary)]/20 text-[var(--clay-primary-bright)]'
                                                                        : 'border-[var(--clay-border)] bg-[var(--clay-surface)] text-[var(--clay-text-muted)] hover:border-[var(--clay-primary-bright)]/40 hover:text-[var(--clay-text)]'
                                                                }`}
                                                            >
                                                                {amt} USDC
                                                            </button>
                                                        );
                                                    })}

                                                {[
                                                    {
                                                        label: '25%',
                                                        ratio: 0.25,
                                                    },
                                                    {
                                                        label: '50%',
                                                        ratio: 0.5,
                                                    },
                                                    {
                                                        label: 'MAX / Full',
                                                        ratio: 1,
                                                    },
                                                ]
                                                    .filter(
                                                        () =>
                                                            tuitionRemainingBase >
                                                            0,
                                                    )
                                                    .map(({ label, ratio }) => {
                                                        const rawUnits =
                                                            Math.floor(
                                                                tuitionRemainingBase *
                                                                    ratio,
                                                            );
                                                        const cappedUnits =
                                                            walletBalanceBaseUnits >
                                                            0
                                                                ? Math.min(
                                                                      rawUnits,
                                                                      walletBalanceBaseUnits,
                                                                  )
                                                                : rawUnits;
                                                        if (cappedUnits <= 0) {
                                                            return null;
                                                        }
                                                        return (
                                                            <button
                                                                key={label}
                                                                type="button"
                                                                onClick={() =>
                                                                    selectQuickTuitionAmount(
                                                                        cappedUnits,
                                                                    )
                                                                }
                                                                className="clay-focus rounded-full border border-[var(--clay-border)] bg-[var(--clay-surface)] px-3 py-1 text-xs font-semibold text-[var(--clay-text)] transition hover:border-[var(--clay-primary-bright)]/50 hover:text-[var(--clay-primary-bright)]"
                                                            >
                                                                {label}
                                                            </button>
                                                        );
                                                    })}
                                            </div>

                                            <InputError
                                                message={payError ?? undefined}
                                                className="mt-2"
                                            />
                                            {unpostedControlled && (
                                                <div className="mt-3 flex flex-col gap-2 rounded-xl border border-amber-500/40 bg-amber-500/10 p-3.5 text-xs">
                                                    <div className="flex items-center justify-between gap-2 font-semibold text-amber-300">
                                                        <span>
                                                            In-progress payment
                                                            attempt:{' '}
                                                            {(
                                                                Number(
                                                                    unpostedControlled.amount_base_units,
                                                                ) / 1_000_000
                                                            ).toFixed(2)}{' '}
                                                            USDC
                                                        </span>
                                                        <span className="font-mono text-[10px]">
                                                            #
                                                            {unpostedControlled.id.slice(
                                                                0,
                                                                8,
                                                            )}
                                                        </span>
                                                    </div>
                                                    <p className="text-[var(--clay-text-muted)]">
                                                        {unpostedControlled.can_cancel
                                                            ? 'Wallet approval never started. Clear this unused quote to review a new amount.'
                                                            : circleWallet &&
                                                                !unpostedControlled.via_circle
                                                              ? 'Circle approval was requested, but no challenge was saved. No PIN window can open yet. Resume the existing request below; the app retrieves the hash automatically after settlement.'
                                                              : unpostedControlled.via_circle
                                                                ? 'Circle authorization was submitted. Check status below.'
                                                                : 'Wallet approval started, but its outcome is unknown. Paste the transaction hash from your wallet history. Do not pay again.'}
                                                    </p>
                                                    <div className="mt-1 flex flex-wrap items-center gap-2">
                                                        {unpostedControlled.can_cancel && (
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="outline"
                                                                className="h-8 rounded-full border-amber-500/40 text-xs text-amber-300 hover:bg-amber-500/20"
                                                                onClick={() =>
                                                                    handleCancelControlled(
                                                                        unpostedControlled.id,
                                                                    )
                                                                }
                                                                disabled={
                                                                    clearingControlledId ===
                                                                    unpostedControlled.id
                                                                }
                                                            >
                                                                <Trash2 className="mr-1.5 size-3.5" />
                                                                Clear
                                                                unbroadcast
                                                                attempt
                                                            </Button>
                                                        )}
                                                        {circleWallet &&
                                                            !unpostedControlled.posted && (
                                                                <Button
                                                                    type="button"
                                                                    size="sm"
                                                                    variant="outline"
                                                                    disabled={
                                                                        paying
                                                                    }
                                                                    onClick={() =>
                                                                        handleResumeCircle(
                                                                            unpostedControlled.id,
                                                                        )
                                                                    }
                                                                >
                                                                    Resume
                                                                    existing
                                                                    Circle
                                                                    approval
                                                                </Button>
                                                            )}
                                                        {unpostedControlled.via_circle && (
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="outline"
                                                                className="h-8 rounded-full text-xs"
                                                                onClick={() =>
                                                                    handleCheckCircleSettlement(
                                                                        unpostedControlled.id,
                                                                    )
                                                                }
                                                                disabled={
                                                                    checkingCircleId ===
                                                                    unpostedControlled.id
                                                                }
                                                            >
                                                                <RefreshCw className="mr-1.5 size-3.5" />
                                                                Check Circle
                                                                settlement
                                                            </Button>
                                                        )}
                                                        {!circleWallet &&
                                                            !unpostedControlled.can_cancel && (
                                                                <div className="flex w-full flex-wrap items-center gap-2 pt-1">
                                                                    <Input
                                                                        placeholder="0x… transaction hash"
                                                                        value={
                                                                            manualControlledHash
                                                                        }
                                                                        onChange={(
                                                                            e: React.ChangeEvent<HTMLInputElement>,
                                                                        ) =>
                                                                            setManualControlledHash(
                                                                                e.target.value.trim(),
                                                                            )
                                                                        }
                                                                        className="h-8 flex-1 font-mono text-xs"
                                                                    />
                                                                    <Button
                                                                        type="button"
                                                                        size="sm"
                                                                        disabled={
                                                                            !manualControlledHash
                                                                        }
                                                                        onClick={() =>
                                                                            handleVerifyControlledHash(
                                                                                unpostedControlled.id,
                                                                                manualControlledHash,
                                                                            )
                                                                        }
                                                                        className="h-8 rounded-full text-xs"
                                                                    >
                                                                        Verify
                                                                        settlement
                                                                    </Button>
                                                                </div>
                                                            )}
                                                    </div>
                                                </div>
                                            )}
                                            {studentRail && (
                                                <div className="mt-3 flex items-center gap-2 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3.5 text-xs text-emerald-400">
                                                    <ShieldCheck className="size-4 shrink-0 text-emerald-400" />
                                                    <span>
                                                        {circleWallet
                                                            ? 'Your student-owned Circle wallet will prompt for your PIN to authorize this transfer.'
                                                            : 'Your browser wallet (MetaMask) will prompt for your signature on Arc Testnet.'}
                                                    </span>
                                                </div>
                                            )}
                                        </div>

                                        {/* 2. WALLET-TO-TREASURY ROUTE VISUALIZER */}
                                        <div className="rounded-2xl border border-[var(--clay-border)] bg-[var(--clay-surface-soft)]/70 p-4">
                                            <div className="flex items-center justify-between gap-2 pb-3">
                                                <span className="bulletin-eyebrow text-[11px]">
                                                    On-Chain Transfer Route
                                                </span>
                                                <span className="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-400">
                                                    <ShieldCheck className="size-3.5" />
                                                    Server-Pinned Treasury
                                                </span>
                                            </div>

                                            {/* From Wallet */}
                                            <div className="flex items-center justify-between gap-3 rounded-xl border border-[var(--clay-border)] bg-[var(--clay-surface)] p-3">
                                                <div className="flex min-w-0 items-center gap-3">
                                                    <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[var(--clay-primary)]/15 text-[var(--clay-primary-bright)]">
                                                        <Wallet className="size-4" />
                                                    </span>
                                                    <div className="min-w-0">
                                                        <div className="flex items-center gap-2">
                                                            <span className="text-xs font-semibold text-[var(--clay-text)]">
                                                                From: Your
                                                                Student Wallet
                                                            </span>
                                                            <span className="rounded bg-[var(--clay-surface-soft)] px-1.5 py-0.5 font-mono text-[10px] text-[var(--clay-text-muted)]">
                                                                Circle SCA
                                                            </span>
                                                        </div>
                                                        <p className="truncate font-mono text-xs text-[var(--clay-text-muted)]">
                                                            {walletAddress}
                                                        </p>
                                                    </div>
                                                </div>
                                                <div className="flex shrink-0 items-center gap-1">
                                                    {walletAddress && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                copyToClipboard(
                                                                    walletAddress,
                                                                    'Sender wallet address',
                                                                )
                                                            }
                                                            className="clay-focus inline-flex size-7 items-center justify-center rounded-md text-[var(--clay-text-muted)] hover:bg-[var(--clay-surface-soft)] hover:text-[var(--clay-text)]"
                                                            title="Copy sender address"
                                                        >
                                                            <Copy className="size-3.5" />
                                                        </button>
                                                    )}
                                                    {wallet.qr_code &&
                                                        walletAddress && (
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    setQrModal({
                                                                        title: 'Student Wallet QR',
                                                                        address:
                                                                            walletAddress,
                                                                        qr: wallet.qr_code!,
                                                                    })
                                                                }
                                                                className="clay-focus inline-flex size-7 items-center justify-center rounded-md text-[var(--clay-text-muted)] hover:bg-[var(--clay-surface-soft)] hover:text-[var(--clay-text)]"
                                                                title="Show wallet QR code"
                                                            >
                                                                <QrCode className="size-3.5" />
                                                            </button>
                                                        )}
                                                    {snapshot?.explorer_url && (
                                                        <a
                                                            href={
                                                                snapshot.explorer_url
                                                            }
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="clay-focus inline-flex size-7 items-center justify-center rounded-md text-[var(--clay-text-muted)] hover:bg-[var(--clay-surface-soft)] hover:text-[var(--clay-text)]"
                                                            title="View sender on Arcscan"
                                                        >
                                                            <ExternalLink className="size-3.5" />
                                                        </a>
                                                    )}
                                                </div>
                                            </div>

                                            {/* Connector Pill */}
                                            <div className="my-1.5 flex items-center justify-center">
                                                <div className="inline-flex items-center gap-1.5 rounded-full border border-[var(--clay-border)] bg-[var(--clay-surface)] px-3 py-0.5 text-[10px] font-semibold text-[var(--clay-text-muted)]">
                                                    <ArrowDown className="size-3 text-[var(--clay-primary-bright)]" />
                                                    <span>
                                                        {studentRail
                                                            ? 'Your approval · gas paid in USDC'
                                                            : 'Circle agent signer'}
                                                    </span>
                                                </div>
                                            </div>

                                            {/* To Treasury */}
                                            <div className="flex items-center justify-between gap-3 rounded-xl border border-[var(--clay-border)] bg-[var(--clay-surface)] p-3">
                                                <div className="flex min-w-0 items-center gap-3">
                                                    <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-emerald-500/15 text-emerald-400">
                                                        <ShieldCheck className="size-4" />
                                                    </span>
                                                    <div className="min-w-0">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="text-xs font-semibold text-[var(--clay-text)]">
                                                                To: Institution
                                                                Treasury
                                                            </span>
                                                            {tuitionAccount?.term && (
                                                                <span className="rounded bg-emerald-500/10 px-1.5 py-0.5 text-[10px] font-medium text-emerald-400">
                                                                    {
                                                                        tuitionAccount.term
                                                                    }
                                                                </span>
                                                            )}
                                                        </div>
                                                        <p className="truncate font-mono text-xs text-[var(--clay-text-muted)]">
                                                            {treasury.address ??
                                                                'Configured on server'}
                                                        </p>
                                                    </div>
                                                </div>
                                                <div className="flex shrink-0 items-center gap-1">
                                                    {treasury.address && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                copyToClipboard(
                                                                    treasury.address!,
                                                                    'Treasury address',
                                                                )
                                                            }
                                                            className="clay-focus inline-flex size-7 items-center justify-center rounded-md text-[var(--clay-text-muted)] hover:bg-[var(--clay-surface-soft)] hover:text-[var(--clay-text)]"
                                                            title="Copy treasury address"
                                                        >
                                                            <Copy className="size-3.5" />
                                                        </button>
                                                    )}
                                                    {treasury.qr_code &&
                                                        treasury.address && (
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    setQrModal({
                                                                        title: 'Institution Treasury QR',
                                                                        address:
                                                                            treasury.address!,
                                                                        qr: treasury.qr_code!,
                                                                    })
                                                                }
                                                                className="clay-focus inline-flex size-7 items-center justify-center rounded-md text-[var(--clay-text-muted)] hover:bg-[var(--clay-surface-soft)] hover:text-[var(--clay-text)]"
                                                                title="Show treasury QR code"
                                                            >
                                                                <QrCode className="size-3.5" />
                                                            </button>
                                                        )}
                                                    {treasury.explorer_url && (
                                                        <a
                                                            href={
                                                                treasury.explorer_url
                                                            }
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="clay-focus inline-flex size-7 items-center justify-center rounded-md text-[var(--clay-text-muted)] hover:bg-[var(--clay-surface-soft)] hover:text-[var(--clay-text)]"
                                                            title="View treasury on Arcscan"
                                                        >
                                                            <ExternalLink className="size-3.5" />
                                                        </a>
                                                    )}
                                                </div>
                                            </div>
                                        </div>

                                        {/* 3. FEE TELEMETRY & QUOTE LOCK BAR */}
                                        <div className="rounded-2xl border border-[var(--clay-border)] bg-[var(--clay-surface-soft)]/50 p-4 text-sm">
                                            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--clay-border)]/70 pb-3">
                                                <div className="flex items-center gap-2">
                                                    <Lock className="size-3.5 text-[var(--clay-primary-bright)]" />
                                                    <span className="text-xs font-semibold text-[var(--clay-text)]">
                                                        Network fee estimate
                                                    </span>
                                                    {quoting && (
                                                        <span className="inline-flex items-center gap-1 text-[11px] text-[var(--clay-primary-bright)]">
                                                            <Loader2 className="size-3 animate-spin" />
                                                            locking…
                                                        </span>
                                                    )}
                                                </div>

                                                {payQuote?.expires_at &&
                                                    quoteSecondsRemaining !==
                                                        null && (
                                                        <div className="flex items-center gap-2">
                                                            <span
                                                                className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 font-mono text-[11px] font-semibold ${
                                                                    quoteSecondsRemaining >
                                                                    20
                                                                        ? 'bg-emerald-500/10 text-emerald-400'
                                                                        : quoteSecondsRemaining >
                                                                            0
                                                                          ? 'bg-amber-500/15 text-amber-300'
                                                                          : 'bg-rose-500/15 text-rose-300'
                                                                }`}
                                                            >
                                                                <Clock className="size-3" />
                                                                {quoteSecondsRemaining >
                                                                0
                                                                    ? `${Math.floor(quoteSecondsRemaining / 60)}:${String(quoteSecondsRemaining % 60).padStart(2, '0')}`
                                                                    : 'Expired'}
                                                            </span>
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    const u =
                                                                        parseBaseUnits(
                                                                            payAmount,
                                                                        );
                                                                    if (u) {
                                                                        void requestTuitionQuote(
                                                                            u,
                                                                        );
                                                                    }
                                                                }}
                                                                disabled={
                                                                    quoting
                                                                }
                                                                className="clay-focus inline-flex items-center gap-1 text-xs font-semibold text-[var(--clay-primary-bright)] hover:underline"
                                                            >
                                                                <RefreshCw
                                                                    className={`size-3 ${quoting ? 'animate-spin' : ''}`}
                                                                />
                                                                Re-quote
                                                            </button>
                                                        </div>
                                                    )}
                                            </div>

                                            <dl className="mt-3 grid gap-2.5 text-xs sm:text-sm">
                                                <div className="flex items-center justify-between gap-4">
                                                    <dt className="clay-meta">
                                                        Payer gas fee
                                                    </dt>
                                                    <dd className="font-mono text-xs text-[var(--clay-text)]">
                                                        {studentRail
                                                            ? payQuote?.estimated_student_fee !=
                                                              null
                                                                ? `Estimated ${payQuote.estimated_student_fee} USDC · paid by you`
                                                                : quoting
                                                                  ? 'Estimating gas…'
                                                                  : 'Unavailable · review fees in your wallet'
                                                            : payQuote?.fee_paid_by_payer ===
                                                                false
                                                              ? 'Sponsored by operator rail'
                                                              : 'See network quote'}
                                                    </dd>
                                                </div>

                                                {studentRail &&
                                                    payQuote?.estimated_student_fee !=
                                                        null && (
                                                        <div className="flex items-center justify-between gap-4 border-t border-[var(--clay-border)] pt-2">
                                                            <dt>
                                                                Estimated total
                                                                (principal +
                                                                gas)
                                                            </dt>
                                                            <dd className="font-mono">
                                                                {formatUnits(
                                                                    BigInt(
                                                                        payQuote.amount_base_units ??
                                                                            0,
                                                                    ) *
                                                                        1_000_000_000_000n +
                                                                        parseUnits(
                                                                            payQuote.estimated_student_fee,
                                                                            18,
                                                                        ),
                                                                    18,
                                                                )}{' '}
                                                                USDC
                                                            </dd>
                                                        </div>
                                                    )}
                                                {payQuote && !studentRail && (
                                                    <>
                                                        <div className="flex items-center justify-between gap-4">
                                                            <dt className="clay-meta">
                                                                Estimated Arc
                                                                network gas
                                                                (relayer-paid)
                                                            </dt>
                                                            <dd className="font-mono text-xs text-[var(--clay-text-muted)]">
                                                                {
                                                                    payQuote.network_fee
                                                                }{' '}
                                                                USDC (ceiling:{' '}
                                                                {formatBaseUnits(
                                                                    payQuote.fee_ceiling_base_units,
                                                                )}{' '}
                                                                USDC)
                                                            </dd>
                                                        </div>

                                                        <div className="flex items-baseline justify-between gap-4 border-t border-[var(--clay-border)]/60 pt-2.5">
                                                            <dt className="font-semibold text-[var(--clay-text)]">
                                                                Total leaving
                                                                your wallet
                                                            </dt>
                                                            <dd className="font-mono text-base font-bold text-[var(--clay-primary-bright)] tabular-nums">
                                                                {payQuote.payer_debit ??
                                                                    payQuote.total}{' '}
                                                                USDC
                                                            </dd>
                                                        </div>
                                                    </>
                                                )}
                                            </dl>

                                            {/* Expandable Cryptographic Payload Inspector */}
                                            {payQuote?.request_digest && (
                                                <div className="mt-3 border-t border-[var(--clay-border)]/60 pt-2.5">
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            setShowTechnicalDetails(
                                                                (v) => !v,
                                                            )
                                                        }
                                                        className="clay-focus flex w-full items-center justify-between text-left text-[11px] font-semibold text-[var(--clay-text-muted)] hover:text-[var(--clay-text)]"
                                                    >
                                                        <span className="inline-flex items-center gap-1.5">
                                                            <Fingerprint className="size-3.5 text-[var(--clay-primary-bright)]" />
                                                            Cryptographic Intent
                                                            Digest & Idempotency
                                                            Key
                                                        </span>
                                                        <span>
                                                            {showTechnicalDetails
                                                                ? 'Hide'
                                                                : 'Inspect'}
                                                        </span>
                                                    </button>

                                                    {showTechnicalDetails && (
                                                        <div className="mt-2.5 grid gap-2 rounded-xl border border-[var(--clay-border)] bg-[var(--clay-surface)] p-3 font-mono text-[11px]">
                                                            <div className="flex items-center justify-between gap-2">
                                                                <span className="text-[var(--clay-text-muted)]">
                                                                    Request Key:
                                                                </span>
                                                                <span className="truncate text-[var(--clay-text)]">
                                                                    {requestKey ??
                                                                        payQuote.request_key ??
                                                                        '—'}
                                                                </span>
                                                            </div>
                                                            <div className="flex flex-col gap-1">
                                                                <span className="text-[var(--clay-text-muted)]">
                                                                    SHA-256
                                                                    Intent
                                                                    Digest:
                                                                </span>
                                                                <div className="flex items-center justify-between gap-2 break-all text-[var(--clay-primary-bright)]">
                                                                    <span>
                                                                        {
                                                                            payQuote.request_digest
                                                                        }
                                                                    </span>
                                                                    <button
                                                                        type="button"
                                                                        onClick={() =>
                                                                            copyToClipboard(
                                                                                payQuote.request_digest,
                                                                                'Intent digest',
                                                                            )
                                                                        }
                                                                        className="clay-focus shrink-0 text-[var(--clay-text-muted)] hover:text-[var(--clay-text)]"
                                                                    >
                                                                        <Copy className="size-3" />
                                                                    </button>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    )}
                                                </div>
                                            )}
                                        </div>

                                        {/* 4. EXPLICIT CRYPTOGRAPHIC CONSENT */}
                                        {payQuote && (
                                            <label
                                                htmlFor="wallet_transfer_consent"
                                                className={`group flex cursor-pointer items-start gap-3.5 rounded-2xl border p-4 transition-all select-none ${
                                                    consent
                                                        ? 'border-[var(--clay-primary-bright)] bg-[var(--clay-primary)]/10 shadow-xs'
                                                        : 'border-[var(--clay-border)] bg-[var(--clay-surface-soft)]/50 hover:border-[var(--clay-primary-bright)]/50'
                                                }`}
                                            >
                                                <Checkbox
                                                    id="wallet_transfer_consent"
                                                    checked={consent}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        setConsent(
                                                            checked === true,
                                                        )
                                                    }
                                                    className="mt-0.5 shrink-0 border-[var(--clay-border)] data-[state=checked]:border-[var(--clay-primary)] data-[state=checked]:bg-[var(--clay-primary)] data-[state=checked]:text-[var(--clay-primary-foreground)]"
                                                />
                                                <div className="min-w-0 flex-1 space-y-1">
                                                    <p className="text-xs font-semibold text-[var(--clay-text)]">
                                                        Authorize Immutable
                                                        On-Chain Transfer
                                                    </p>
                                                    <p className="text-xs leading-relaxed text-[var(--clay-text-muted)]">
                                                        {payQuote.consent_text}
                                                    </p>
                                                </div>
                                            </label>
                                        )}

                                        {/* 5. SIGN & BROADCAST PRIMARY BUTTON */}
                                        <div className="flex flex-col gap-3 pt-1">
                                            <Button
                                                type="submit"
                                                disabled={
                                                    paying ||
                                                    hasTuitionInFlight ||
                                                    Boolean(
                                                        unpostedControlled &&
                                                        !unpostedControlled.can_cancel,
                                                    ) ||
                                                    !consent ||
                                                    !payAmount ||
                                                    parseBaseUnits(
                                                        payAmount,
                                                    ) === null ||
                                                    parseBaseUnits(
                                                        payAmount,
                                                    )! <= 0
                                                }
                                                className="clay-focus h-12 w-full rounded-2xl bg-[var(--clay-primary)] text-sm font-bold text-[var(--clay-primary-foreground)] shadow-md transition-all hover:-translate-y-0.5 hover:bg-[var(--clay-primary-bright)] disabled:opacity-50"
                                            >
                                                {paying ? (
                                                    <>
                                                        <Loader2 className="mr-2 size-4 animate-spin" />
                                                        {studentRail
                                                            ? circleWallet
                                                                ? 'Authorizing in Circle PIN…'
                                                                : 'Awaiting MetaMask approval…'
                                                            : 'Signing & Broadcasting via Circle W3S…'}
                                                    </>
                                                ) : hasTuitionInFlight ? (
                                                    <>
                                                        <Loader2 className="mr-2 size-4 animate-spin" />
                                                        Transfer In Progress on
                                                        Arc Testnet…
                                                    </>
                                                ) : (
                                                    <>
                                                        <ArrowUpRight className="mr-1.5 size-4" />
                                                        {consent
                                                            ? studentRail
                                                                ? `Approve & Pay ${payAmount} USDC`
                                                                : `Sign & Send ${payQuote?.payer_debit ?? payAmount} USDC`
                                                            : 'Check Consent Box Above to Sign'}
                                                    </>
                                                )}
                                            </Button>

                                            <p className="clay-meta flex items-center justify-center gap-1.5 text-center text-[11px] text-[var(--status-pending)]">
                                                <TriangleAlert className="size-3.5 shrink-0" />
                                                Arc Testnet finality is
                                                sub-second and irreversible once
                                                signed.
                                            </p>
                                        </div>
                                    </form>
                                ) : (
                                    /* =================================================
                                       CAMPUS BILL CRYPTO TRANSFER FORM
                                       ================================================= */
                                    <div className="mt-5 flex flex-col gap-5">
                                        {!pilot_enabled && (
                                            <div className="flex items-start gap-2.5 rounded-xl border border-amber-500/30 bg-amber-500/10 p-3.5 text-xs text-amber-300">
                                                <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                                                <span>
                                                    Campus bill submission is
                                                    currently switched off on
                                                    this installation. You can
                                                    still inspect bills and test
                                                    quotes.
                                                </span>
                                            </div>
                                        )}

                                        {/* Bill Selector Cards */}
                                        <div className="space-y-2">
                                            <span className="bulletin-eyebrow text-xs">
                                                Select Campus Bill to Settle
                                            </span>
                                            {bills.length === 0 ? (
                                                <p className="clay-inset rounded-xl p-4 text-sm text-[var(--clay-text-muted)]">
                                                    No campus bills have been
                                                    issued to your student
                                                    account.
                                                </p>
                                            ) : (
                                                <div className="grid gap-2.5 sm:grid-cols-2">
                                                    {bills.map((bill) => {
                                                        const isSelected =
                                                            bill.id ===
                                                            selectedBill?.id;
                                                        const rem =
                                                            formatBaseUnitsShort(
                                                                bill.remaining_base_units,
                                                            );
                                                        return (
                                                            <button
                                                                key={bill.id}
                                                                type="button"
                                                                onClick={() =>
                                                                    selectBill(
                                                                        bill,
                                                                    )
                                                                }
                                                                className={`clay-focus flex flex-col items-start gap-1.5 rounded-xl border p-3.5 text-left transition-all ${
                                                                    isSelected
                                                                        ? 'border-[var(--clay-primary-bright)] bg-[var(--clay-primary)]/15 shadow-xs'
                                                                        : 'border-[var(--clay-border)] bg-[var(--clay-surface-soft)] hover:border-[var(--clay-primary-bright)]/40'
                                                                }`}
                                                            >
                                                                <div className="flex w-full items-center justify-between gap-2">
                                                                    <span className="text-xs font-bold text-[var(--clay-text)]">
                                                                        {
                                                                            bill.category_label
                                                                        }
                                                                    </span>
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="font-mono text-[10px]"
                                                                    >
                                                                        {rem}{' '}
                                                                        USDC
                                                                    </Badge>
                                                                </div>
                                                                <p className="line-clamp-1 text-xs text-[var(--clay-text-muted)]">
                                                                    {
                                                                        bill.description
                                                                    }
                                                                </p>
                                                            </button>
                                                        );
                                                    })}
                                                </div>
                                            )}
                                        </div>

                                        {selectedBill && (
                                            <>
                                                {/* Amount Stage for Bill */}
                                                <div className="clay-inset rounded-2xl border border-[var(--clay-border)] p-5">
                                                    <div className="flex items-center justify-between gap-2">
                                                        <label
                                                            htmlFor="crypto_bill_amount"
                                                            className="bulletin-eyebrow text-xs"
                                                        >
                                                            Bill Transfer Amount
                                                        </label>
                                                        <span className="font-mono text-xs text-[var(--clay-text-muted)]">
                                                            Still owed:{' '}
                                                            <strong className="text-[var(--clay-text)]">
                                                                {formatBaseUnitsShort(
                                                                    selectedBill.remaining_base_units,
                                                                )}{' '}
                                                                USDC
                                                            </strong>
                                                        </span>
                                                    </div>

                                                    <div className="mt-3 flex items-baseline justify-between gap-3">
                                                        <input
                                                            id="crypto_bill_amount"
                                                            type="text"
                                                            inputMode="decimal"
                                                            value={billAmount}
                                                            onChange={(e) => {
                                                                setBillAmount(
                                                                    e.target
                                                                        .value,
                                                                );
                                                                setBillMountedQuote(
                                                                    null,
                                                                );
                                                                setBillError(
                                                                    null,
                                                                );
                                                            }}
                                                            className="w-full bg-transparent font-mono text-4xl font-bold tracking-tight text-[var(--clay-text)] tabular-nums focus:outline-none sm:text-5xl"
                                                        />
                                                        <span className="shrink-0 rounded-xl border border-[var(--clay-border)] bg-[var(--clay-surface)] px-3 py-1.5 font-mono text-sm font-bold text-[var(--clay-primary-bright)]">
                                                            USDC
                                                        </span>
                                                    </div>

                                                    {liveFiatPreview && (
                                                        <p className="mt-2 text-xs text-[var(--clay-text-muted)]">
                                                            ≈ {liveFiatPreview}
                                                        </p>
                                                    )}

                                                    <InputError
                                                        message={
                                                            billError ??
                                                            undefined
                                                        }
                                                        className="mt-2"
                                                    />
                                                </div>

                                                {/* Jev Advisory Classification */}
                                                <div className="rounded-2xl border border-dashed border-[var(--clay-border)] bg-[var(--clay-surface-soft)]/40 p-4">
                                                    <div className="flex items-center justify-between gap-2">
                                                        <div className="flex items-center gap-2">
                                                            <BrainCircuit className="size-4 text-[var(--clay-primary-bright)]" />
                                                            <span className="text-xs font-semibold text-[var(--clay-text)]">
                                                                Jev AI Advisory
                                                                Read (Optional)
                                                            </span>
                                                        </div>
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={billBusy}
                                                            onClick={
                                                                runBillClassification
                                                            }
                                                            className="h-7 rounded-full text-xs"
                                                        >
                                                            {billBusy ? (
                                                                <Loader2 className="mr-1 size-3 animate-spin" />
                                                            ) : (
                                                                <Sparkles className="mr-1 size-3" />
                                                            )}
                                                            Ask Jev
                                                        </Button>
                                                    </div>

                                                    {billClassification ? (
                                                        <div className="mt-2.5 grid gap-1.5 text-xs text-[var(--clay-text-muted)]">
                                                            <div className="flex items-center justify-between">
                                                                <span>
                                                                    Category:{' '}
                                                                    <strong className="text-[var(--clay-text)]">
                                                                        {
                                                                            billClassification.category
                                                                        }
                                                                    </strong>
                                                                </span>
                                                                <span>
                                                                    Confidence:{' '}
                                                                    <strong className="text-[var(--clay-text)]">
                                                                        {Math.round(
                                                                            billClassification.confidence *
                                                                                100,
                                                                        )}
                                                                        %
                                                                    </strong>
                                                                </span>
                                                            </div>
                                                            {billClassification.narrative && (
                                                                <p className="text-[var(--clay-text)]">
                                                                    {
                                                                        billClassification.narrative
                                                                    }
                                                                </p>
                                                            )}
                                                        </div>
                                                    ) : (
                                                        <p className="mt-1.5 text-xs text-[var(--clay-text-muted)]">
                                                            {billUnavailableReason ??
                                                                'Advisory only — your bill transfer works identically with or without AI classification.'}
                                                        </p>
                                                    )}
                                                </div>

                                                {/* Bill Quote Lock & Consent */}
                                                {!billMountedQuote ? (
                                                    <Button
                                                        type="button"
                                                        disabled={
                                                            billBusy ||
                                                            BigInt(
                                                                selectedBill.remaining_base_units,
                                                            ) === 0n
                                                        }
                                                        onClick={mountBillQuote}
                                                        className="clay-focus h-12 w-full rounded-2xl bg-[var(--clay-primary)] text-sm font-bold text-[var(--clay-primary-foreground)]"
                                                    >
                                                        {billBusy ? (
                                                            <Loader2 className="mr-2 size-4 animate-spin" />
                                                        ) : (
                                                            <Lock className="mr-2 size-4" />
                                                        )}
                                                        Lock 120s Network Quote
                                                        for{' '}
                                                        {
                                                            selectedBill.category_label
                                                        }
                                                    </Button>
                                                ) : (
                                                    <div className="space-y-4 rounded-2xl border border-[var(--clay-primary-bright)]/50 bg-[var(--clay-surface-soft)] p-4">
                                                        <div className="flex items-center justify-between text-xs">
                                                            <span className="font-semibold text-[var(--clay-primary-bright)]">
                                                                Locked Bill
                                                                Quote
                                                            </span>
                                                            <span className="font-mono text-[var(--clay-text-muted)]">
                                                                Policy:{' '}
                                                                {
                                                                    billMountedQuote.policy_code
                                                                }
                                                            </span>
                                                        </div>
                                                        <dl className="grid gap-2 text-xs">
                                                            <div className="flex items-center justify-between">
                                                                <dt className="clay-meta">
                                                                    Recipient
                                                                    Treasury
                                                                </dt>
                                                                <dd className="font-mono flex items-center gap-1.5">
                                                                    <span>
                                                                        {shortAddress(
                                                                            billMountedQuote.recipient,
                                                                        )}
                                                                    </span>
                                                                    <button
                                                                        type="button"
                                                                        onClick={() =>
                                                                            copyToClipboard(
                                                                                billMountedQuote.recipient,
                                                                                'Recipient treasury address',
                                                                            )
                                                                        }
                                                                        className="clay-focus inline-flex size-5 items-center justify-center rounded text-[var(--clay-text-muted)] hover:text-[var(--clay-text)]"
                                                                        title="Copy recipient address"
                                                                    >
                                                                        <Copy className="size-3" />
                                                                    </button>
                                                                    {treasury.qr_code && (
                                                                        <button
                                                                            type="button"
                                                                            onClick={() =>
                                                                                setQrModal(
                                                                                    {
                                                                                        title: 'Institution Treasury QR',
                                                                                        address:
                                                                                            billMountedQuote.recipient,
                                                                                        qr: treasury.qr_code!,
                                                                                    },
                                                                                )
                                                                            }
                                                                            className="clay-focus inline-flex size-5 items-center justify-center rounded text-[var(--clay-text-muted)] hover:text-[var(--clay-text)]"
                                                                            title="Show treasury QR code"
                                                                        >
                                                                            <QrCode className="size-3" />
                                                                        </button>
                                                                    )}
                                                                </dd>
                                                            </div>
                                                            <div className="flex justify-between">
                                                                <dt className="clay-meta">
                                                                    Total Debit
                                                                </dt>
                                                                <dd className="font-mono font-bold text-[var(--clay-text)]">
                                                                    {
                                                                        billMountedQuote.total
                                                                    }{' '}
                                                                    USDC
                                                                </dd>
                                                            </div>
                                                        </dl>
                                                        <p className="rounded-xl border border-[var(--clay-border)] bg-[var(--clay-surface)] p-3 text-xs leading-relaxed text-[var(--clay-text)]">
                                                            {
                                                                billMountedQuote.consent_text
                                                            }
                                                        </p>
                                                        <Button
                                                            type="button"
                                                            disabled={billBusy}
                                                            onClick={
                                                                acceptBillQuote
                                                            }
                                                            className="clay-focus h-12 w-full rounded-2xl bg-[var(--clay-primary)] text-sm font-bold text-[var(--clay-primary-foreground)]"
                                                        >
                                                            {billBusy ? (
                                                                <Loader2 className="mr-2 size-4 animate-spin" />
                                                            ) : (
                                                                <ArrowUpRight className="mr-2 size-4" />
                                                            )}
                                                            Sign & Consent to
                                                            Bill Transfer
                                                        </Button>
                                                    </div>
                                                )}
                                            </>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* =========================================================
                            RIGHT COLUMN: LIVE SETTLEMENT TELEMETRY & LEDGER (5 cols)
                           ========================================================= */}
                        <div className="flex flex-col gap-5 lg:col-span-5">
                            {/* Live Settlement Tracker Card */}
                            <section className="clay-card border-[var(--clay-border)] p-5 sm:p-6">
                                <div className="flex items-center justify-between gap-2 pb-4">
                                    <div className="flex items-center gap-2">
                                        <Radio
                                            className={`size-4 ${
                                                hasTuitionInFlight ||
                                                hasBillInFlight
                                                    ? 'animate-pulse text-amber-400'
                                                    : 'text-[var(--clay-primary-bright)]'
                                            }`}
                                        />
                                        <h2 className="bulletin-eyebrow text-xs">
                                            Live Settlement Telemetry
                                        </h2>
                                    </div>
                                    {(hasTuitionInFlight ||
                                        hasBillInFlight) && (
                                        <span className="rounded-full border border-amber-500/30 bg-amber-500/10 px-2.5 py-0.5 text-[10px] font-semibold text-amber-300 animate-pulse">
                                            Broadcasting on Arc…
                                        </span>
                                    )}
                                </div>

                                {mode === 'tuition' ? (
                                    trackerAttempts.length > 0 ? (
                                        <div className="space-y-4">
                                            <StudentPaymentTracker
                                                attempts={trackerAttempts}
                                                tuitionAccount={tuitionAccount}
                                                walletBalance={
                                                    snapshot?.balance
                                                }
                                                onOpenPay={() => {
                                                    setReceipt(null);
                                                    selectQuickTuitionAmount(
                                                        5_000_000,
                                                    );
                                                }}
                                                className="border-0 bg-transparent p-0 shadow-none"
                                            />

                                            {/* If any attempt is stuck in unsubmitted prepared hold, let the student clear it */}
                                            {trackerAttempts
                                                .filter(
                                                    (a) =>
                                                        a.state ===
                                                            'prepared' &&
                                                        !a.tx_hash,
                                                )
                                                .map((heldAttempt) => (
                                                    <div
                                                        key={heldAttempt.id}
                                                        className="flex items-center justify-between gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-200"
                                                    >
                                                        <span>
                                                            Unsubmitted quote
                                                            hold #
                                                            {heldAttempt.id} (
                                                            {formatBaseUnitsShort(
                                                                heldAttempt.amount_base_units,
                                                            )}{' '}
                                                            USDC)
                                                        </span>
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={
                                                                clearingHoldId ===
                                                                heldAttempt.id
                                                            }
                                                            onClick={() =>
                                                                void handleClearExpiredHold(
                                                                    heldAttempt.id,
                                                                )
                                                            }
                                                            className="h-7 rounded-full border-amber-400/40 bg-transparent text-xs text-amber-200 hover:bg-amber-500/20"
                                                        >
                                                            {clearingHoldId ===
                                                            heldAttempt.id ? (
                                                                <Loader2 className="size-3 animate-spin" />
                                                            ) : (
                                                                'Release Hold'
                                                            )}
                                                        </Button>
                                                    </div>
                                                ))}
                                        </div>
                                    ) : (
                                        <div className="clay-inset flex flex-col items-center gap-2 rounded-2xl p-6 text-center">
                                            <Layers className="size-6 text-[var(--clay-text-muted)]" />
                                            <p className="text-xs font-semibold text-[var(--clay-text)]">
                                                Ready for On-Chain Broadcast
                                            </p>
                                            <p className="text-xs text-[var(--clay-text-muted)]">
                                                As soon as you sign, live
                                                4-stage telemetry (Consent →
                                                Circle W3S Relayer → Arc Block
                                                Finality → Ledger Credit) will
                                                stream here automatically.
                                            </p>
                                        </div>
                                    )
                                ) : billPayments.length > 0 ? (
                                    <div className="space-y-3">
                                        {billPayments
                                            .slice(0, 4)
                                            .map((payment) => {
                                                const isDone =
                                                    payment.is_allocated ||
                                                    payment.verified;
                                                const isFailed =
                                                    payment.submission_state ===
                                                    'failed';
                                                return (
                                                    <div
                                                        key={payment.id}
                                                        className="rounded-xl border border-[var(--clay-border)] bg-[var(--clay-surface-soft)] p-3.5 text-xs"
                                                    >
                                                        <div className="flex items-center justify-between gap-2">
                                                            <span className="font-bold text-[var(--clay-text)]">
                                                                Bill #
                                                                {
                                                                    payment.student_bill_id
                                                                }{' '}
                                                                &middot;{' '}
                                                                {formatBaseUnitsShort(
                                                                    payment.amount_base_units,
                                                                )}{' '}
                                                                USDC
                                                            </span>
                                                            <span
                                                                className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold ${
                                                                    isDone
                                                                        ? 'bg-emerald-500/15 text-emerald-400'
                                                                        : isFailed
                                                                          ? 'bg-rose-500/15 text-rose-300'
                                                                          : 'bg-amber-500/15 text-amber-300'
                                                                }`}
                                                            >
                                                                {isDone ? (
                                                                    <Check className="size-3" />
                                                                ) : isFailed ? (
                                                                    <XCircle className="size-3" />
                                                                ) : (
                                                                    <Loader2 className="size-3 animate-spin" />
                                                                )}
                                                                {
                                                                    payment.submission_state
                                                                }
                                                            </span>
                                                        </div>
                                                        <p className="mt-1 text-[var(--clay-text-muted)]">
                                                            {
                                                                payment.submission_label
                                                            }
                                                        </p>
                                                        {payment.tx_hash && (
                                                            <a
                                                                href={`${EXPLORER}/tx/${payment.tx_hash}`}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="mt-2 inline-flex items-center gap-1 font-mono text-[11px] text-[var(--clay-primary-bright)] hover:underline"
                                                            >
                                                                Tx:{' '}
                                                                {shortAddress(
                                                                    payment.tx_hash,
                                                                )}
                                                                <ExternalLink className="size-3" />
                                                            </a>
                                                        )}
                                                        {payment.receipt && (
                                                            <Button
                                                                type="button"
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() =>
                                                                    setOfficialReceipt(
                                                                        payment.receipt ??
                                                                            null,
                                                                    )
                                                                }
                                                                className="mt-2.5 h-8 w-full rounded-full border-emerald-500/40 bg-emerald-500/10 text-xs font-semibold text-emerald-300 hover:bg-emerald-500/20"
                                                            >
                                                                <ReceiptText className="mr-1.5 size-3.5" />
                                                                View Official
                                                                e-Receipt (
                                                                {
                                                                    payment
                                                                        .receipt
                                                                        .receipt_number
                                                                }
                                                                )
                                                            </Button>
                                                        )}
                                                    </div>
                                                );
                                            })}
                                    </div>
                                ) : (
                                    <div className="clay-inset rounded-2xl p-6 text-center text-xs text-[var(--clay-text-muted)]">
                                        No campus bill transfers recorded yet.
                                    </div>
                                )}
                            </section>

                            {/* Account Ledger Breakdown Card */}
                            {tuitionAccount && (
                                <section className="clay-card border-[var(--clay-border)] p-5 sm:p-6">
                                    <div className="flex items-center justify-between gap-2">
                                        <div>
                                            <p className="bulletin-eyebrow text-xs">
                                                Tuition Account Summary
                                            </p>
                                            <h3 className="clay-title mt-0.5 text-base font-bold">
                                                {tuitionAccount.term}
                                            </h3>
                                        </div>
                                        <span className="rounded-full bg-[var(--status-resolved)]/15 px-3 py-1 font-mono text-xs font-bold text-[var(--status-resolved)]">
                                            {tuitionCompletionPercent}% Paid
                                        </span>
                                    </div>

                                    {/* Progress Bar */}
                                    <div className="mt-3 h-2 w-full overflow-hidden rounded-full bg-[var(--clay-surface-soft)]">
                                        <div
                                            className="h-full rounded-full bg-[var(--status-resolved)] transition-all duration-500"
                                            style={{
                                                width: `${tuitionCompletionPercent}%`,
                                            }}
                                        />
                                    </div>

                                    <dl className="mt-4 grid grid-cols-2 gap-3 border-t border-[var(--clay-border)] pt-4 text-xs">
                                        <div>
                                            <dt className="clay-meta">
                                                Total Billed
                                            </dt>
                                            <dd className="mt-1 font-mono text-sm font-semibold text-[var(--clay-text)]">
                                                {formatUsdc(
                                                    tuitionAccount.total_amount,
                                                )}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="clay-meta">
                                                Paid & Verified
                                            </dt>
                                            <dd className="mt-1 font-mono text-sm font-semibold text-[var(--status-resolved)]">
                                                {formatUsdc(
                                                    tuitionAccount.paid_amount,
                                                )}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="clay-meta">
                                                Held In-Flight
                                            </dt>
                                            <dd className="mt-1 font-mono text-sm font-semibold text-amber-400">
                                                {formatUsdc(
                                                    tuitionAccount.held_amount ??
                                                        '0',
                                                )}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="clay-meta">
                                                Remaining Balance
                                            </dt>
                                            <dd className="mt-1 font-mono text-sm font-bold text-[var(--clay-primary-bright)]">
                                                {formatUsdc(
                                                    tuitionAccount.remaining_amount,
                                                )}
                                            </dd>
                                        </div>
                                    </dl>

                                    <div className="mt-4 flex items-center justify-between border-t border-[var(--clay-border)]/60 pt-3 text-[11px] text-[var(--clay-text-muted)]">
                                        <span>
                                            Student: {student.name} (
                                            {student.student_number})
                                        </span>
                                        <CheckCircle2 className="size-3.5 text-emerald-400" />
                                    </div>
                                </section>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            {/* Official Payment e-Receipt Modal */}
            <OfficialPaymentReceiptModal
                receipt={officialReceipt}
                open={officialReceipt !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setOfficialReceipt(null);
                    }
                }}
            />

            {/* QR Code Inspection Dialog */}
            <Dialog
                open={qrModal !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setQrModal(null);
                    }
                }}
            >
                <DialogContent className="bulletin clay-card border-[var(--clay-border)] max-w-xs sm:max-w-sm text-center">
                    {qrModal && (
                        <>
                            <DialogHeader>
                                <DialogTitle className="clay-h2 text-base font-bold">
                                    {qrModal.title}
                                </DialogTitle>
                                <DialogDescription className="clay-meta text-xs">
                                    Arc Testnet (Chain ID 5042002) &middot; Scan
                                    with crypto wallet
                                </DialogDescription>
                            </DialogHeader>

                            <div className="flex flex-col items-center gap-4 py-3">
                                <div className="p-3 bg-white rounded-2xl shadow-inner border border-zinc-200">
                                    <img
                                        src={qrModal.qr}
                                        alt={qrModal.title}
                                        className="size-48 object-contain"
                                    />
                                </div>
                                <div className="flex w-full items-center justify-between gap-2 rounded-xl border border-[var(--clay-border)] bg-[var(--clay-surface-soft)] p-2.5 text-xs font-mono">
                                    <span className="truncate text-[var(--clay-text)]">
                                        {qrModal.address}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            copyToClipboard(
                                                qrModal.address,
                                                qrModal.title,
                                            )
                                        }
                                        className="clay-focus inline-flex size-6 shrink-0 items-center justify-center rounded text-[var(--clay-text-muted)] hover:text-[var(--clay-text)]"
                                        title="Copy address"
                                    >
                                        <Copy className="size-3.5" />
                                    </button>
                                </div>
                            </div>
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
