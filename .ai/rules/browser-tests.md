---
paths:
  - 'tests/**'
  - 'resources/js/**'
---

# Browser Tests

## Never click a control by its visible label — drive it by `data-test` hook
`pestphp/pest-plugin-browser` resolves `click('Some text')` through `Pest\Browser\Support\GuessLocator`. That class
calls `Selector::isExplicit()` first, and it returns true when the string contains ANY CSS special character:

```php
$cssSpecialChars = ['[', ']', '#', '>', '+', '~', ':', '*', '|', '^', ',', '=', '(', ')'];
```

An "explicit" selector is handed straight to Playwright as a CSS selector and never falls back to text matching. So
`click('Yes, choose another wallet')` reaches the browser as the CSS selector `Yes, choose another wallet`, matches
nothing, and dies on the 5s action timeout. The element is really there — visible, enabled, stable, receiving pointer
events — and a JS `.click()` on it works, so the symptom points at the app when the fault is the selector.

Note the asymmetry that makes this expensive to diagnose: `assertSee('...')` does NOT go through `GuessLocator` (it
calls `page->getByText()` directly), so asserting the same comma-bearing text succeeds while clicking it times out.

Fix: put `data-test="<id>"` on the control and click `'@<id>'`. The `@` form resolves to
`[data-testid=<id>], [data-test=<id>]`, so the `data-test="…"` convention already used across `resources/js/components/`
is exactly what the browser tests should target. Do NOT fix this by stripping punctuation from the visible copy:
product text is not hostage to a test plugin's parser, and the next copy edit reintroduces the bug.

Student wallet flow hooks: `wallet-connection-entry`, `wallet-change-open`, `wallet-change-confirm`,
`wallet-change-keep`, `wallet-change-cancel`, `wallet-connect-browser`.

## Browser tests drive the BUILT bundle, so run `npm run build` first
`$this->withVite()` restores the real Vite instance and `Vite::useHotFile(storage_path('framework/testing-no-vite-hot'))`
points at a hot file that does not exist, so Vite serves the built manifest in `public/build`. A browser test therefore
asserts the markup of the last build, not your edited source: add a `data-test` hook, forget to rebuild, and the test
fails with a timeout that looks like a missing element. `public/build` is gitignored, so a fresh clone has no bundle
at all and every browser test errors until `npm run build` has run.

## EVERY browser test must call `Vite::useHotFile(...)`, or a stale `public/hot` blanks the page
`public/hot` is a gitignored marker that `npm run dev` writes and **never removes**. While it exists, Laravel's Vite
points every asset URL at the dev server it names — so if that dev server is not running, the browser gets no JS and
no CSS. A React/Inertia page then renders as a **completely blank white page** with no console error and no JavaScript
error, and the test fails with "Expected to see text [...] but it was not found or not visible" plus a blank
screenshot. Filament pages are server-rendered, so they keep working and hide the cause.

The fix is the same guard the rest of the suite already uses, in every browser test:

```php
$this->withVite();
Vite::useHotFile(storage_path('framework/testing-no-vite-hot'));
```

That pins Vite to the built manifest no matter what `public/hot` says. Do not "fix" it by deleting `public/hot` — that
is the developer's local dev-server state, and the next `npm run dev` recreates it.

## CI runs the browser group in its own process; one long run drops the WebSocket
CI splits the suite into two commands, and both must be green:

```bash
php artisan test --compact --exclude-group=browser
php artisan test --compact --group=browser
```

Run them separately, exactly as CI does. A single long `php artisan test` interleaves ~2,200 tests with the browser
tests, and in that shape the plugin's WebSocket to the Playwright server occasionally dies mid-run ("Code 1006
(ABNORMAL_CLOSE)"), after which every remaining browser test fails with `WebsocketClosedException` even though each
passes in isolation. It is a harness flake, not a product fault.


## Playwright's own browsers are not installed here — system Chromium is symlinked in
`npx playwright install` is not run in this environment, so Playwright 1.63 looks for binaries that do not exist. The
system Chromium at `/usr/bin/chromium` is symlinked into the cache instead, and BOTH paths are required because 1.63
launches the headless shell for headless runs:

```
~/.cache/ms-playwright/chromium-1243/chrome-linux64/chrome -> /usr/bin/chromium
~/.cache/ms-playwright/chromium_headless_shell-1243/chrome-headless-shell-linux64/chrome-headless-shell -> /usr/bin/chromium
```

Without them the suite reports `PlaywrightOutdatedException` ("Playwright is outdated") or
`Executable doesn't exist at .../chrome-headless-shell`, neither of which mentions the missing binary.
