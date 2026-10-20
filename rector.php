<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddVoidReturnTypeWhereNoReturnRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/bootstrap/cache',
        __DIR__.'/storage',
        __DIR__.'/vendor',
        // Public signatures are part of this application's contract, even when a
        // parameter is unused inside the method. `AutonomousPipelineBridge::bridgePayment()`
        // keeps `$targetInvoiceId` for the `app:eduflow:autonomous-pipeline-bridge` command
        // that calls it with two arguments, and `PaymentService::disburse()` keeps the
        // arguments of the retired simulated payout it refuses to perform. Removing
        // either breaks a caller that is not in this repository's type graph.
        RemoveUnusedPublicMethodParameterRector::class => [
            __DIR__.'/app',
            __DIR__.'/tests',
        ],
    ])
    ->withPhpSets(
        php82: true,
    )
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        instanceOf: true,
        earlyReturn: true,
    )
    ->withRules([
        AddVoidReturnTypeWhereNoReturnRector::class,
    ]);
