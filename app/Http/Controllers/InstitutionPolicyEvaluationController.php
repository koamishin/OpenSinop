<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ReviewCollectionBatch;
use App\Http\Requests\StoreInstitutionPolicyEvaluationRequest;
use App\Models\BudgetSnapshot;
use App\Models\CollectionBatch;
use App\Models\CollectionBatchReview;
use App\Models\InstitutionPolicyEvaluation;
use App\Models\InstitutionPolicyPublication;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InstallationInstitution;
use App\Services\InstitutionPolicyEvaluations;
use App\Services\InstitutionPolicyWorkspace;
use App\Settings\AiSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InstitutionPolicyEvaluationController extends Controller
{
    public function index(Request $request, InstallationInstitution $institutions, InstitutionPolicyWorkspace $policies,
        InstitutionPolicyEvaluations $evaluations, AiSettings $settings): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', InstitutionPolicyEvaluation::class);
        $institution = $institutions->require();
        $publication = $policies->active();
        $selection = $request->validate(['source_type' => ['nullable', 'required_with:source_id', Rule::in(['collection', 'transaction', 'budget_snapshot'])],
            'source_id' => ['nullable', 'required_with:source_type', 'integer', 'min:1']]);

        return Inertia::render('finance/evaluations/index', [
            'institution' => ['id' => $institution->id, 'name' => $institution->name],
            'sources' => fn (): array => $this->sources($request, $institution->id, $evaluations, $selection),
            'current' => $publication instanceof InstitutionPolicyPublication ? ['title' => $publication->version->title,
                'policy_version_id' => $publication->institution_policy_version_id, 'publication_digest' => $publication->publication_digest] : null,
            'evaluations' => fn (): array => InstitutionPolicyEvaluation::query()->where('organization_id', $institution->id)
                ->latest('id')->limit(50)->get()->map(fn (InstitutionPolicyEvaluation $evaluation): array => $this->data($evaluation))->all(),
            'queueReady' => $evaluations->queueReady(), 'aiEnabled' => $settings->mayCallProvider(),
            'adminUrl' => url($request->user()->hasRole('super_admin') ? '/admin' : '/finance'),
            'financeUrl' => url('/finance'),
            'collectionsUrl' => url('/finance/collections'),
            'supervisorUrl' => url('/finance/finance-supervisor'),
            'policyUrl' => route('finance.policies.index'),
        ]);
    }

    /** @param array<string, mixed> $selection
     * @return list<array<string, mixed>>
     */
    private function sources(Request $request, int $institutionId, InstitutionPolicyEvaluations $evaluations, array $selection): array
    {
        $sources = [];
        if (isset($selection['source_type'], $selection['source_id'])) {
            $sources[] = $evaluations->source($request->user(), $selection['source_type'], (int) $selection['source_id']);
        }
        foreach (['collection' => CollectionBatch::class, 'transaction' => Transaction::class, 'budget_snapshot' => BudgetSnapshot::class] as $type => $class) {
            foreach ($class::query()->where('organization_id', $institutionId)->latest('id')->limit(30)->get() as $source) {
                if ($type === ($selection['source_type'] ?? null) && $source->id === (int) ($selection['source_id'] ?? 0)) {
                    continue;
                }
                if (! Gate::forUser($request->user())->allows('view', $source)) {
                    continue;
                }
                try {
                    $sources[] = $evaluations->source($request->user(), $type, $source->id);
                } catch (ValidationException) {
                    continue;
                }
            }
        }

        return $sources;
    }

    public function store(StoreInstitutionPolicyEvaluationRequest $request, InstitutionPolicyEvaluations $evaluations): JsonResponse
    {
        $evaluation = $evaluations->request($request->user(), $request->validated());

        return response()->json(['data' => $this->data($evaluation)], 202)->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, InstitutionPolicyEvaluation $institutionPolicyEvaluation): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $institutionPolicyEvaluation);

        return response()->json(['data' => $this->data($institutionPolicyEvaluation)])->header('Cache-Control', 'no-store, private');
    }

    public function approveReceipt(
        Request $request,
        InstallationInstitution $institutions,
        ReviewCollectionBatch $reviewCollection,
        InstitutionPolicyWorkspace $policies,
        InstitutionPolicyEvaluations $evaluations
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->hasAnyRole(['admin', 'super_admin']), 403);

        $validated = $request->validate([
            'collection_id' => ['required', 'integer', 'min:1'],
        ]);

        $institution = $institutions->require();
        /** @var CollectionBatch $batch */
        $batch = CollectionBatch::query()
            ->where('organization_id', $institution->id)
            ->findOrFail((int) $validated['collection_id']);

        Gate::forUser($actor)->authorize('view', $batch);

        $existingReview = CollectionBatchReview::query()->where('collection_batch_id', $batch->id)->first();
        if ($existingReview === null) {
            Gate::forUser($actor)->authorize('review', $batch);
            $reviewCollection->handle(
                $actor,
                $batch,
                $batch->snapshot_digest,
                'approve_receipts',
                'Direct staff approval from Policy Evaluations',
                'Verified receipt against institutional ledger and treasury records.'
            );
        }

        // Re-evaluate against active policy publication so updated results are immediate
        $newEvaluation = null;
        $publication = $policies->active();
        if ($publication instanceof InstitutionPolicyPublication) {
            $source = $evaluations->source($actor, 'collection', $batch->id);

            /** @var InstitutionPolicyEvaluation|null $existingEvaluation */
            $existingEvaluation = InstitutionPolicyEvaluation::query()
                ->where('organization_id', $institution->id)
                ->where('source_type', 'collection')
                ->where('source_id', $batch->id)
                ->latest('id')
                ->first();

            if ($existingEvaluation instanceof InstitutionPolicyEvaluation
                && $existingEvaluation->state === 'completed'
                && $existingEvaluation->source_digest === $source['source_digest']
                && ! ($existingEvaluation->result['needs_review'] ?? false)) {
                $newEvaluation = $existingEvaluation;
            } else {
                $newEvaluation = $evaluations->request($actor, [
                    'source_type' => 'collection',
                    'source_id' => $batch->id,
                    'source_digest' => $source['source_digest'],
                    'expected_publication_digest' => $publication->publication_digest,
                    'request_key' => (string) Str::uuid(),
                    'reason' => 'Fresh evaluation after receipt approval and verification.',
                ]);

            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Receipt approved. Policy evaluation queued; no spending authorized.',
            'collection_id' => $batch->id,
            'evaluation' => $newEvaluation instanceof InstitutionPolicyEvaluation ? $this->data($newEvaluation) : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function data(InstitutionPolicyEvaluation $evaluation): array
    {
        $valid = $evaluation->hasValidResult();

        return [...$evaluation->only(['id', 'source_type', 'source_id', 'source_digest', 'reason', 'state', 'stage', 'completed_targets',
            'total_targets', 'timeline', 'institution_policy_version_id', 'publication_digest', 'provider', 'model', 'last_error',
            'started_at', 'completed_at', 'created_at']), 'label' => $evaluation->input['label'] ?? 'Source unavailable',
            'result' => $valid ? $evaluation->result : null, 'result_valid' => $valid, 'can_execute' => false];
    }
}
