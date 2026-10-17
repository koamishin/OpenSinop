<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PaymentIntent;
use App\Models\StudentBill;
use App\Models\StudentBillPayment;
use App\Models\StudentControlledPayment;
use App\Models\StudentTuitionPayment;
use App\Models\Transaction;
use App\Models\TuitionAccount;
use App\Services\ChainQuantity;
use App\Services\StudentWalletService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Support\Amounts;

class StudentPaymentsController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $student = $user->student()->firstOrFail();
        $walletAddress = app(StudentWalletService::class)->addressFor($student);
        // Receipts stay readable after a deliberate wallet switch.
        $knownAddresses = $student->wallets()->pluck('address')->push($walletAddress)->filter()->all();

        $transactions = Transaction::query()
            ->paidToAny($knownAddresses)
            ->whereIn('type', ['student_assistance', 'refund'])
            ->latest('executed_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'type' => $transaction->type->value,
                'type_label' => $transaction->type->getLabel(),
                'amount' => (float) $transaction->amount,
                'currency' => $transaction->currency,
                'status' => $transaction->status->value,
                'status_label' => $transaction->status->getLabel(),
                'tx_hash' => $transaction->provider_tx_hash,
                'network' => $transaction->network,
                'executed_at' => $transaction->executed_at?->format('M d, Y h:i A'),
            ]);

        $confirmedTotal = (float) Transaction::query()
            ->paidToAny($knownAddresses)
            ->whereIn('type', ['student_assistance', 'refund'])
            ->where('status', 'confirmed')
            ->sum('amount');

        $controlledIds = StudentControlledPayment::query()
            ->where('student_id', $student->id)
            ->pluck('id')
            ->all();

        $tuitionReceipts = StudentTuitionPayment::query()
            ->where('student_id', $student->id)
            ->whereNotIn('request_key', $controlledIds)
            ->with(['tuitionAccount.academicTerm', 'attempt'])
            ->latest('executed_at')
            ->limit(25)
            ->get()
            ->map(function (StudentTuitionPayment $payment) use ($user, $student): array {
                $attempt = $payment->attempt;
                $account = $payment->tuitionAccount;
                $explorerUrl = $this->explorerUrl($payment->tx_hash);

                $rawBlock = $attempt?->settlement_evidence['receipt']['blockNumber'] ?? null;
                $blockNumber = null;
                if (is_string($rawBlock)) {
                    $blockNumber = str_starts_with($rawBlock, '0x') ? (string) hexdec($rawBlock) : $rawBlock;
                } elseif (is_int($rawBlock)) {
                    $blockNumber = (string) $rawBlock;
                }

                return [
                    'receipt_number' => sprintf('OR-ARC-T%s-%06d', ($payment->executed_at ?? $payment->created_at ?? now())->format('Y'), $payment->id),
                    'payment_type' => 'tuition',
                    'student_name' => $user->name,
                    'student_number' => $student->student_number,
                    'program' => $student->program,
                    'term' => $account?->academicTerm?->name ?? 'Current Term',
                    'description' => 'Official Tuition Settlement'.($account?->academicTerm?->name ? ' · '.$account->academicTerm->name : ''),
                    'amount_base_units' => (string) $payment->amount_base_units,
                    'applied_base_units' => (string) $payment->applied_base_units,
                    'overpayment_base_units' => (string) $payment->overpayment_base_units,
                    'remaining_balance_base_units' => $account ? (string) $account->remainingAmount() : '0',
                    'total_tuition_base_units' => $account ? (string) $account->total_amount : '0',
                    'paid_tuition_base_units' => $account ? (string) $account->paid_amount : '0',
                    'network_fee' => $payment->network_fee ?: '0.00',
                    'fee_guarantee' => $attempt?->fee_guarantee ?? 'relayer_sponsored',
                    'payer_address' => $payment->payer_address,
                    'recipient_address' => $payment->recipient_address,
                    'chain' => $payment->chain ?: 'ARC-TESTNET',
                    'chain_id' => $attempt?->chain_id ?: 5042002,
                    'block_number' => $blockNumber,
                    'verified_status' => 'Verified on Arc Testnet',
                    'tx_hash' => $payment->tx_hash,
                    'explorer_url' => $explorerUrl,
                    'settlement_digest' => $attempt?->settlement_evidence_digest,
                    'consent_digest' => $attempt?->consent_digest,
                    'issued_at' => ($payment->executed_at ?? $payment->created_at ?? now())->format('M d, Y h:i:s A'),
                ];
            });

        $billReceipts = StudentBillPayment::query()
            ->where('student_id', $student->id)
            ->whereNotIn('request_key', $controlledIds)
            ->where('tx_verified_on_chain', true)
            ->with('studentBill')
            ->latest('executed_at')
            ->limit(25)
            ->get()
            ->map(function (StudentBillPayment $payment) use ($user, $student): array {
                $explorerUrl = $this->explorerUrl($payment->tx_hash);

                $rawBlock = $payment->settlement_evidence['receipt']['blockNumber'] ?? null;
                $blockNumber = null;
                if (is_string($rawBlock)) {
                    $blockNumber = str_starts_with($rawBlock, '0x') ? (string) hexdec($rawBlock) : $rawBlock;
                } elseif (is_int($rawBlock)) {
                    $blockNumber = (string) $rawBlock;
                }

                return [
                    'receipt_number' => sprintf('OR-ARC-B%s-%06d', ($payment->executed_at ?? $payment->created_at ?? now())->format('Y'), $payment->id),
                    'payment_type' => 'bill',
                    'student_name' => $user->name,
                    'student_number' => $student->student_number,
                    'program' => $student->program,
                    'term' => $payment->studentBill?->category->label() ?? 'Campus Bill',
                    'description' => $payment->studentBill?->description ?? 'Campus Bill Payment',
                    'amount_base_units' => (string) $payment->amount_base_units,
                    'applied_base_units' => (string) $payment->applied_base_units,
                    'overpayment_base_units' => (string) $payment->overpayment_base_units,
                    'remaining_balance_base_units' => (string) ($payment->studentBill?->remainingAmount() ?? 0),
                    'network_fee' => $payment->network_fee ?? '0.00',
                    'fee_guarantee' => $payment->fee_guarantee ?? 'relayer_sponsored',
                    'payer_address' => $payment->payer_address,
                    'recipient_address' => $payment->recipient_address,
                    'chain' => $payment->chain ?: 'ARC-TESTNET',
                    'chain_id' => 5042002,
                    'block_number' => $blockNumber,
                    'verified_status' => 'Verified on Arc Testnet',
                    'tx_hash' => $payment->tx_hash,
                    'explorer_url' => $explorerUrl,
                    'settlement_digest' => $payment->settlement_evidence_digest,
                    'consent_digest' => $payment->consent_digest,
                    'issued_at' => ($payment->executed_at ?? $payment->settlement_verified_at ?? $payment->created_at ?? now())->format('M d, Y h:i:s A'),
                ];
            });

        $controlledReceipts = StudentControlledPayment::query()->where('student_id', $student->id)
            ->whereNotNull('posted_at')->latest('posted_at')->get()
            ->filter(fn (StudentControlledPayment $p): bool => $p->tx_hash !== null && ($p->evidence['verdict'] ?? null) === 'verified')
            ->map(function (StudentControlledPayment $payment) use ($user, $student): array {
                $s = $payment->snapshot;
                $tuition = $payment->tuition_account_id !== null;
                $account = $tuition ? TuitionAccount::query()->with('academicTerm')->find($payment->tuition_account_id)
                    : StudentBill::query()->find($payment->student_bill_id);

                return [
                    'receipt_number' => 'TEST-ARC-'.$payment->id, 'payment_type' => $tuition ? 'tuition' : 'bill',
                    'student_name' => $user->name, 'student_number' => $student->student_number, 'program' => $student->program,
                    'term' => $account instanceof TuitionAccount ? $account->academicTerm->name : 'Campus bill',
                    'description' => 'Verified student-controlled payment · testnet only',
                    'amount_base_units' => (string) $s['amount_base_units'], 'applied_base_units' => (string) $payment->applied_base_units,
                    'overpayment_base_units' => (string) $payment->overpayment_base_units,
                    'remaining_balance_base_units' => (string) ($account?->remainingAmount() ?? 0),
                    'network_fee' => isset($payment->evidence['actual_fee_base_units'])
                        ? Amounts::toDecimalString((int) $payment->evidence['actual_fee_base_units'], 6) : 'Unavailable',
                    'fee_guarantee' => 'Student-paid; no sponsorship assumed',
                    'payer_address' => $s['sender'], 'recipient_address' => $s['recipient'], 'chain' => 'ARC-TESTNET', 'chain_id' => 5042002,
                    'block_number' => ChainQuantity::toDecimalString($payment->evidence['receipt']['blockNumber'] ?? null),
                    'verified_status' => 'Verified testnet allocation — not official tuition revenue',
                    'tx_hash' => $payment->tx_hash, 'explorer_url' => $this->explorerUrl($payment->tx_hash),
                    'settlement_digest' => PaymentIntent::digest($payment->evidence), 'issued_at' => $payment->posted_at->format('M d, Y h:i:s A'),
                ];
            });
        $receipts = $controlledReceipts->concat($tuitionReceipts)->concat($billReceipts)->values()->all();

        $tuitionPaidBaseUnits = (int) StudentTuitionPayment::query()
            ->where('student_id', $student->id)
            ->where('tx_verified_on_chain', true)
            ->sum('applied_base_units');

        $tuitionPaidBaseUnits += (int) StudentControlledPayment::query()->where('student_id', $student->id)
            ->whereNotNull('posted_at')->whereNotNull('tuition_account_id')
            ->whereNotIn('id', StudentTuitionPayment::query()->where('student_id', $student->id)->pluck('request_key'))
            ->sum('applied_base_units');

        return Inertia::render('payments', [
            'transactions' => $transactions,
            'receipts' => $receipts,
            'controlledTransactions' => StudentControlledPayment::query()->where('student_id', $student->id)
                ->where(function ($query): void {
                    $query->whereNotNull('posted_at')->orWhereNotNull('approval_started_at')->orWhereNotNull('tx_hash')->orWhereNotNull('circle_challenge_id');
                })->latest('created_at')->get()->map(fn (StudentControlledPayment $p): array => [
                    'id' => $p->id, 'type' => $p->tuition_account_id !== null ? 'Tuition payment' : 'Campus bill',
                    'amount' => Amounts::toDecimalString((int) $p->snapshot['amount_base_units'], 6),
                    'verified' => $p->posted_at !== null && ($p->evidence['verdict'] ?? null) === 'verified',
                    'tx_hash' => $p->tx_hash, 'explorer_url' => $this->explorerUrl($p->tx_hash),
                ]),
            'wallet' => [
                'address' => $walletAddress,
            ],
            'totals' => [
                'confirmed' => $confirmedTotal,
                'tuition_paid' => round($tuitionPaidBaseUnits / 1_000_000, 2),
                'currency' => 'USDC',
            ],
        ]);
    }

    private function explorerUrl(?string $txHash): ?string
    {
        if (! is_string($txHash) || trim($txHash) === '') {
            return null;
        }

        try {
            return app(ArcNetworkGateway::class)->explorerUrl($txHash);
        } catch (Throwable) {
            return 'https://testnet.arcscan.app/tx/'.$txHash;
        }
    }
}
