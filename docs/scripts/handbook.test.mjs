import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const page = (name) => readFileSync(new URL(`../src/content/docs/${name}.mdx`, import.meta.url), 'utf8');
const source = (name) => readFileSync(new URL(`../../${name}`, import.meta.url), 'utf8');

test('the welcome page routes readers by task rather than database model names', () => {
    const home = page('index');
    for (const role of ['I work in finance', 'I look after the server', 'I want to contribute', 'Our school is evaluating it']) {
        assert.ok(home.includes(role));
    }
    assert.ok(home.includes('guides/workflows/'));
});

test('read-only bridge documentation matches removed spending capability', () => {
    const bridge = source('app/Services/AutonomousPipelineBridge.php');
    assert.ok(!bridge.includes('generateReviewerTotp'));
    assert.ok(!bridge.includes('givePermissionTo'));
    assert.ok(!bridge.includes('reviewVendorPayment->handle'));
    assert.match(page('institution/safety-readiness'), /no longer grants/);
    assert.match(page('guides/operations'), /read-only and defaults off/);
    assert.match(page('guides/policy-advice'), /cannot borrow another staff identity/);
});

test('operator guidance does not invent environment controls that the current code never reads', () => {
    const config = source('config/eduflow.php');
    assert.ok(!config.includes("env('EDUFLOW_BACKGROUND_FINANCE'"));
    assert.ok(!config.includes("env('EDUFLOW_FINANCE_QUEUE_CONNECTION'"));
    const configuration = page('getting-started/configuration');
    assert.match(configuration, /are not read there/);
    assert.match(page('guides/operations'), /default queue/);
});

test('student guidance states operator control and testnet limitations', () => {
    const students = page('guides/students');
    assert.match(students, /operator-controlled/i);
    assert.match(students, /not an official school tuition receipt/);
    assert.match(students, /execution remains blocked/);
});

test('the fake local setup disables bridge submission before starting services', () => {
    const local = page('getting-started/local-development');
    assert.ok(local.indexOf('LEPTON_DRIVER=fake') < local.indexOf('composer run dev'));
    assert.ok(local.indexOf('EDUFLOW_AUTONOMOUS_PIPELINE_ENABLED=false') < local.indexOf('composer run dev'));
    assert.match(local, /EducationDemoSeeder/);
    assert.match(local, /does not validate every required fixture/);
});

test('all original public guide URLs remain available', () => {
    for (const path of ['architecture', 'getting-started/configuration', 'getting-started/installation',
        'getting-started/local-development', 'guides/ci-cd', 'guides/core-concepts', 'guides/customization',
        'guides/development', 'guides/directory-structure', 'guides/extensibility', 'guides/security',
        'guides/workflows', 'institution/overview', 'institution/pilot-playbook', 'institution/safety-readiness',
        'reference/commands', 'reference/examples', 'reference/troubleshooting']) {
        assert.match(page(path), /^---\ntitle:/);
    }
});

test('edit links point at the docs project instead of the application root', () => {
    const config = readFileSync(new URL('../astro.config.mjs', import.meta.url), 'utf8');
    assert.ok(config.includes('https://github.com/koamishin/OpenSinop/edit/main/docs/'));
    assert.ok(!config.includes('edit/main/docs/src/content/docs/'));
});

test('installation describes the current Compose env_file and separate database names', () => {
    const compose = source('docker-compose.yml');
    assert.match(compose, /env_file:/);
    assert.match(compose, /POSTGRES_DB:/);
    const installation = page('getting-started/installation');
    assert.match(installation, /loads `\.env` with `env_file`/);
    assert.match(installation, /`DB_DATABASE` and `POSTGRES_DB` aligned/);
    assert.match(installation, /docker compose up -d --no-build/);
});
