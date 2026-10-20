<?php

declare(strict_types=1);

use App\Ai\Agents\InstitutionPolicyAnalyst;
use App\Jobs\AssessInstitutionPolicy;
use App\Models\InstitutionPolicyAssessment;
use App\Models\InstitutionPolicyPublication;
use App\Models\InstitutionPolicyVersion;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\AiProviderResolver;
use App\Services\InstallationInstitution;
use App\Services\InstitutionPolicyInterpreter;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Vite;
use Spatie\Permission\Models\Role;

test('finance authors custom policy categories examples and controls in ReUI workspace', function (): void {
    $this->withVite();
    Vite::useHotFile(storage_path('framework/testing-no-vite-hot'));
    config(['eduflow.institution_id' => null]);
    Organization::factory()->create();
    $maker = User::factory()->create()->assignRole(Role::findOrCreate('finance_officer', 'web'));
    $this->actingAs($maker);
    $page = visit('/finance/policies')->assertSee('Your policy. Clear decisions.')->type('#title', 'Research purchasing policy')
        ->type('#clause-title-0', 'Teaching materials')->type('#clause-text-0', 'Laboratory consumables belong to Teaching Materials.')
        ->click('Categories')->click('Add category')->type('#category-0-id', 'teaching-materials')
        ->type('#category-0-name', 'Teaching Materials')->type('#category-0-description', 'Consumables for classroom experiments.')
        ->type('#category-0-inclusions', 'Pipettes and reagents')->type('#category-0-exclusions', 'Durable equipment')
        ->click('Examples')->click('Add example')->type('#example-0', 'Disposable pipettes for classes')
        ->click('#expected-0')->click('[role="option"]:has-text("Teaching Materials")')
        ->click('Financial controls')->type('#minimum_reserve', '12.000001')->type('#max_daily_disbursement', '400')
        ->click('Save revision')->assertSee('Revision saved.')->assertNoJavaScriptErrors();
    $version = InstitutionPolicyVersion::query()->sole();
    expect($version->content['categories'][0]['id'])->toBe('teaching-materials')
        ->and($version->financePolicy->minimum_reserve_base_units)->toBe(12_000001)
        ->and($version->hasValidContent())->toBeTrue()->and(Transaction::query()->count())->toBe(0);
    $page->click('Review & publish')->assertSee('Authors cannot publish their own policy.');
})->group('browser');

test('reviewer publishes exact tested policy through ReUI without creating payment', function (): void {
    $this->withVite();
    Vite::useHotFile(storage_path('framework/testing-no-vite-hot'));
    $c = institutionPolicyContext();
    app(AiSettings::class)->advisory_enabled = true;
    app(AiSettings::class)->disclosure_accepted = true;
    InstitutionPolicyAnalyst::fake([institutionPolicyAnswer(), institutionPolicyAnswer()]);
    assessInstitutionPolicy($c);
    assessInstitutionPolicy($c, 'examples');
    $this->actingAs($c['reviewer']);
    visit('/finance/policies')->click('Review & publish')->type('#review-reason', 'Exact source and category examples checked independently.')
        ->check('#publication-attestation')->press('Publish policy')->assertSee('Policy published by independent reviewer.')
        ->assertNoJavaScriptErrors();
    expect(InstitutionPolicyPublication::query()->count())->toBe(1)->and(Transaction::query()->count())->toBe(0);
})->group('browser');

test('policy workspace works on mobile dark mode with no horizontal overflow', function (): void {
    $this->withVite();
    Vite::useHotFile(storage_path('framework/testing-no-vite-hot'));
    $c = institutionPolicyContext();
    $this->actingAs($c['maker']);
    $page = visit('/finance/policies')->on()->mobile()->assertSee('Your policy. Clear decisions.');
    $page->script('document.documentElement.classList.add("dark")');
    $page->click('Categories')->assertSee('What this category means')->assertNoJavaScriptErrors();
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->screenshot(fullPage: false, filename: 'institution-policy-mobile-dark');
})->group('browser');

test('playground queues policy interpretation and shows persisted result with real client assets', function (): void {
    $this->withVite();
    Vite::useHotFile(storage_path('framework/testing-no-vite-hot'));
    $c = institutionPolicyContext();
    app(AiSettings::class)->advisory_enabled = true;
    app(AiSettings::class)->disclosure_accepted = true;
    InstitutionPolicyAnalyst::fake([institutionPolicyAnswer()]);
    $this->actingAs($c['maker']);
    $page = visit('/finance/policies')->click('Playground')->press('Interpret policy')->assertSee('Assessment queued.');
    $assessment = InstitutionPolicyAssessment::query()->sole();
    (new AssessInstitutionPolicy($assessment->id))->handle(app(InstitutionPolicyInterpreter::class),
        app(InstallationInstitution::class), app(AiProviderResolver::class));
    $page->assertSee('Consumable teaching supplies match the institution definition.')->assertSee('Laboratory consumables belong to Teaching Materials.')
        ->assertNoJavaScriptErrors();
    expect(Transaction::query()->count())->toBe(0);
})->group('browser');

test('simple rulebook lets regular users toggle categories pick safety limits and activate policy in one click', function (): void {
    $this->withVite();
    Vite::useHotFile(storage_path('framework/testing-no-vite-hot'));
    config(['eduflow.institution_id' => null]);
    Organization::factory()->create(['name' => 'Sunnyvale High School']);
    $admin = User::factory()->create()->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($admin);

    $page = visit('/finance/policies')
        ->assertSee('School Spending Playbook')
        ->assertSee('What types of bills and fees are allowed?')
        ->click('Select Core 6')
        ->assertSee('Spending Categories Updated')
        ->type('#custom-cat-name', 'Robotics Club Kits')
        ->type('#custom-cat-inc', 'Arduino boards, sensors, motors')
        ->click('Add Category')
        ->assertSee('ON · Custom')
        ->assertSee('Robotics Club Kits')
        ->click('⚡ Electric & Internet Bill')
        ->assertSee('Allowed Category: Approved Supplier Invoices')
        ->click('Balanced Everyday Mode')
        ->assertSee('Safety Limits Updated')
        ->click('Save & Turn On Policy')
        ->assertSee('Rules Saved & Turned On')
        ->assertNoJavaScriptErrors();

    $latest = InstitutionPolicyVersion::query()->latest('id')->first();
    expect(InstitutionPolicyPublication::query()->count())->toBe(1)
        ->and($latest?->content['controls']['minimum_reserve'])->toBe('500')
        ->and(collect($latest?->content['categories'] ?? [])->pluck('id')->all())->toContain('robotics-club-kits');
})->group('browser');
