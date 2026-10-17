<?php

declare(strict_types=1);

namespace App\Filament\Resources\Transactions\Tables;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\InstitutionPolicyEvaluation;
use App\Models\Transaction;
use App\Services\ArcNetworkGateway as AppArcNetworkGateway;
use App\Services\LeptonReconciliationService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

class TransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Executed At')
                    ->dateTime('M d, Y H:i:s')
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2).' USDC')
                    ->weight('bold')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('network')
                    ->label('Network')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn ($state): string => strtoupper((string) $state)),
                TextColumn::make('settlement')
                    ->label('Settlement')
                    ->badge()
                    ->getStateUsing(fn (Transaction $record): string => self::settlementLabel($record))
                    ->color(fn (Transaction $record): string => self::settlementColor($record))
                    ->tooltip(fn (Transaction $record): string => self::settlementTooltip($record))
                    ->sortable(),
                TextColumn::make('provider_tx_hash')
                    ->label('Arc Tx Hash')
                    ->placeholder('No on-chain transfer')
                    ->copyable()
                    ->limit(16)
                    ->fontFamily('mono')
                    ->tooltip(fn ($record) => $record->provider_tx_hash ?? 'Ledger entry: no on-chain transfer was made.')
                    ->url(fn (Transaction $record): ?string => self::explorerUrl($record))
                    ->openUrlInNewTab(),
                TextColumn::make('recipient_address')
                    ->label('Recipient Address')
                    ->copyable()
                    ->limit(16)
                    ->fontFamily('mono')
                    ->tooltip(fn ($record) => $record->recipient_address),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(TransactionType::class),
                SelectFilter::make('status')
                    ->options(TransactionStatus::class),
            ])
            ->recordActions([
                Action::make('reevaluatePolicy')->label('Re-evaluate policy')->icon('heroicon-o-sparkles')->color('gray')
                    ->visible(fn (): bool => Gate::allows('create', InstitutionPolicyEvaluation::class))
                    ->url(fn (Transaction $record): string => route('finance.policy-evaluations.index', ['source_type' => 'transaction', 'source_id' => $record->id])),
                Action::make('verifyOnChain')
                    ->label('Verify against Arc')
                    ->icon('heroicon-m-shield-check')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Verify this receipt against the chain?')
                    ->modalDescription('Looks the stored hash up on '.strtoupper((string) config('lepton.arc.chain', 'ARC-TESTNET')).'. A stored hash is a claim; this is the proof.')
                    ->action(function (Transaction $record): void {
                        $result = app(LeptonReconciliationService::class)->verify($record);

                        $metadata = array_merge($record->metadata ?? [], [
                            'reconciliation' => $result['verdict'],
                            'reconciled_at' => now()->toIso8601String(),
                            'reconciliation_note' => $result['reason'],
                            'reconciled_block' => $result['block'],
                        ]);

                        if ($result['verdict'] === 'reverted') {
                            $metadata['status_after_reconciliation'] = 'failed';
                            $record->update([
                                'status' => TransactionStatus::FAILED,
                                'metadata' => $metadata,
                            ]);
                        } else {
                            $record->update(['metadata' => $metadata]);
                        }

                        match ($result['verdict']) {
                            'verified' => Notification::make()
                                ->title('Successful receipt on-chain')
                                ->body($result['reason'])
                                ->success()
                                ->send(),
                            'pending' => Notification::make()
                                ->title('Still in flight')
                                ->body($result['reason'])
                                ->info()
                                ->send(),
                            'reverted' => Notification::make()
                                ->title('The chain reverted this transaction')
                                ->body($result['reason'].' This receipt has been marked failed.')
                                ->danger()
                                ->persistent()
                                ->send(),
                            'unresolved' => Notification::make()
                                ->title('Not found, and not proven absent')
                                ->body($result['reason'])
                                ->warning()
                                ->persistent()
                                ->send(),
                            'observed' => Notification::make()
                                ->title('Provider reports completion')
                                ->body($result['reason'])
                                ->warning()
                                ->send(),
                            'ledger_only' => Notification::make()
                                ->title('Ledger entry, not a chain transfer')
                                ->body($result['reason'])
                                ->warning()
                                ->send(),
                            default => Notification::make()
                                ->title('Could not verify')
                                ->body($result['reason'])
                                ->warning()
                                ->send(),
                        };
                    }),
                Action::make('viewOnArcScan')
                    ->label('View on ArcScan')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('gray')
                    ->visible(fn (Transaction $record): bool => self::explorerUrl($record) !== null)
                    ->url(fn (Transaction $record): ?string => self::explorerUrl($record))
                    ->openUrlInNewTab(),
                ViewAction::make(),
            ])
            ->headerActions([
                Action::make('reconcileAll')
                    ->label('Reconcile all')
                    ->icon('heroicon-m-shield-exclamation')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Reconcile every transaction against the chain?')
                    ->modalDescription('Each stored hash is looked up on '.strtoupper((string) config('lepton.arc.chain', 'ARC-TESTNET')).'. Only receipts the chain reports as reverted are marked failed; hashes the chain cannot find are reported, never failed. This cannot be undone.')
                    ->action(function (): void {
                        $report = app(LeptonReconciliationService::class)->verifyAll();

                        foreach ($report['items'] as $item) {
                            /** @var Transaction $transaction */
                            $transaction = $item['transaction'];

                            $metadata = array_merge($transaction->metadata ?? [], [
                                'reconciliation' => $item['verdict'],
                                'reconciled_at' => now()->toIso8601String(),
                                'reconciliation_note' => $item['reason'],
                                'reconciled_block' => $item['block'],
                            ]);

                            $transaction->update($item['verdict'] === 'reverted'
                                ? ['status' => TransactionStatus::FAILED, 'metadata' => $metadata]
                                : ['metadata' => $metadata]);
                        }

                        $body = sprintf(
                            '%d verified, %d reverted, %d unresolved, %d pending, %d provider-only, %d unverifiable, %d ledger-only.',
                            $report['verified'],
                            $report['reverted'],
                            $report['unresolved'],
                            $report['pending'],
                            $report['observed'],
                            $report['unverifiable'],
                            $report['ledger_only'],
                        );

                        match ($report['verdict']) {
                            'clean' => Notification::make()->title('All receipts verified on-chain')->body($body)->success()->send(),
                            'discrepancies' => Notification::make()->title('The chain reverted these transactions')->body($body)->danger()->persistent()->send(),
                            default => Notification::make()->title('Some receipts remain unresolved')->body($body)->warning()->persistent()->send(),
                        };
                    }),
            ]);
    }

    public static function settlementLabel(Transaction $record): string
    {
        if ($record->isReconciledFailed()) {
            return 'Reverted on chain';
        }

        return match ($record->metadata['reconciliation'] ?? null) {
            'verified' => 'Verified on-chain',
            'unresolved' => 'Unresolved',
            'pending' => 'In flight',
            'observed' => 'Provider only',
            default => null,
        } ?? (($record->metadata['simulated_inbound'] ?? false) === true ? 'Ledger only'
            : (($record->metadata['is_fake'] ?? false) === true ? 'Simulated' : 'Unverified'));
    }

    public static function settlementColor(Transaction $record): string
    {
        if ($record->isReconciledFailed()) {
            return 'danger';
        }

        if (($record->metadata['simulated_inbound'] ?? false) === true) {
            return 'gray';
        }

        return match ($record->metadata['reconciliation'] ?? null) {
            'verified' => 'success',
            'unresolved' => 'warning',
            default => ($record->metadata['is_fake'] ?? false) === true ? 'warning' : 'info',
        };
    }

    public static function settlementTooltip(Transaction $record): string
    {
        $gateway = $record->metadata['gateway'] ?? null;
        $network = $record->metadata['executed_network'] ?? $record->network;

        if ($record->isReconciledFailed()) {
            return 'Reconciliation against '.strtoupper((string) $network).' found a receipt for this hash that reports failure. The chain proved it did not settle.';
        }

        if (($record->metadata['reconciliation'] ?? null) === 'verified') {
            return 'A successful on-chain receipt exists on '.strtoupper((string) $network).'. The transfer is verified on-chain.';
        }

        if (($record->metadata['reconciliation'] ?? null) === 'unresolved') {
            return 'No transaction with this hash was found on '.strtoupper((string) $network).'. That is not proof it never settled: propagation delay, a reorganisation and a hash from another network look identical. Investigate.';
        }

        if (($record->metadata['reconciliation'] ?? null) === 'pending') {
            return 'Known to '.strtoupper((string) $network).' with no receipt yet, so it is still in flight.';
        }

        if (($record->metadata['reconciliation'] ?? null) === 'observed') {
            return 'The provider reports this complete, but no chain receipt could be read. Provider observation is not proof.';
        }

        if (($record->metadata['simulated_inbound'] ?? false) === true) {
            return 'Inbound ledger credit. No USDC moved on '.strtoupper((string) $network).'.';
        }

        if (($record->metadata['is_fake'] ?? false) === true) {
            return 'Executed against the fake Lepton driver. Set LEPTON_DRIVER=circle for real settlement.';
        }

        return 'A stored hash is a claim, not proof. Run "Verify against Arc" or php artisan lepton:reconcile to prove settlement on '.strtoupper((string) $network).($gateway !== null ? ' via '.$gateway : '').'.';
    }

    /**
     * Prefer the explorer URL captured at execution time, then derive one
     * from the current Arc gateway.
     */
    public static function explorerUrl(Transaction $record): ?string
    {
        if (! $record->provider_tx_hash) {
            return null;
        }

        $captured = $record->metadata['explorer_url'] ?? null;

        if (is_string($captured) && $captured !== '') {
            return AppArcNetworkGateway::normalizeExplorerUrl($captured);
        }

        try {
            return AppArcNetworkGateway::normalizeExplorerUrl(
                app(ArcNetworkGateway::class)->explorerUrl($record->provider_tx_hash)
            );
        } catch (Throwable) {
            return null;
        }
    }
}
