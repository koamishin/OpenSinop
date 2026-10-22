---
paths:
  - rector.php
---

# Rector Php

## Rector naming set breaks named-arg constructors
Rector's `naming: true` prepared set renames constructor-promoted params to match type names, which breaks every named-argument caller and silently changed resolve()'s return type/PHPDoc. Do not re-enable it; also audit rector's removed named arguments (e.g. it dropped a required `type:` arg from CircleWalletService::executePayment) after each run and re-run the full test suite.

## Two services are skipped on purpose: they keep a vestigial public signature
`rector.php` skips `app/Services/AutonomousPipelineBridge.php` and `app/Services/PaymentService.php`. Do not "fix" that by letting the dead-code rule strip their parameters:

- `AutonomousPipelineBridge::bridgePayment()` accepts a target invoice id it never reads, and `RunAutonomousPipelineBridge` passes one to it from its `--invoice` option. The option is accepted today and has never had any effect, so removing it would break operator scripts for no gain.
- `PaymentService::disburse()` is a retired simulation seam that always throws. Its parameters document the retired signature.

`RemoveUnusedPublicMethodParameterRector` rewrites the signature but not the call sites, so applying it produces code that fails the project's own PHPStan gate (`invoked with 2 parameters, 1 required`). That is the signal to leave the skip in place rather than to widen the change.

If a future change genuinely retires these signatures, remove the skip in the same change that updates every caller, and re-run the full suite.

