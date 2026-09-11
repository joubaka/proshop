<?php

// Test-only worker: uses the same hard database/server identity guard as the portal.
try {
    if (PHP_SAPI !== 'cli') { throw new RuntimeException('CLI only.'); }
    require __DIR__.'/bootstrap.php';
    [$script, $product, $variation, $location, $delta, $barrier] = $argv;
    if (realpath(dirname($barrier)) !== realpath(__DIR__.'/../../.local-acceptance/cache')
        || !preg_match('/^concurrency-[a-f0-9]+\.signal$/', basename($barrier))) {
        throw new RuntimeException('Invalid test barrier.');
    }
    $place = App\BusinessLocation::findOrFail((int) $location);
    if (!str_starts_with($place->name, 'Concurrency test ')) {
        throw new RuntimeException('Not a concurrency fixture location.');
    }
    // Widen the old read-modify-save race deterministically, without faking the DB.
    App\VariationLocationDetails::retrieved(function () { usleep(150000); });
    $mode = $argv[6] ?? 'stock';
    if ($mode === 'stock') {
        Illuminate\Support\Facades\DB::beginTransaction();
        // Establish an older repeatable-read snapshot like a real controller does.
        Illuminate\Support\Facades\DB::table('variation_location_details')->where('location_id', $place->id)->get();
    }
    echo "READY\n";
    fflush(STDOUT);
    $deadline = microtime(true) + 20;
    while (file_get_contents($barrier) !== 'GO') {
        if (microtime(true) > $deadline) { throw new RuntimeException('Missing barrier.'); }
        usleep(10000);
    }
    $util = new App\Utils\ProductUtil;
    if ($mode === 'payment') {
        $invoice = App\Transaction::where('location_id', $place->id)->findOrFail((int) $product);
        try {
            app(App\Support\InvoicePaymentCoordinator::class)->pay($invoice, 'stripe', function ($attempt) {
                usleep(300000);
                return 'simulated-'.$attempt->id;
            });
        } catch (Symfony\Component\HttpKernel\Exception\HttpException $error) {
            if (!in_array($error->getStatusCode(), [409, 422], true)) { throw $error; }
            echo "BLOCKED\n";
        }
    } elseif ((float) $delta >= 0) {
        $util->updateProductQuantity((int) $location, (int) $product, (int) $variation, (float) $delta, 0, null, false);
    } else {
        $util->decreaseProductQuantity((int) $product, (int) $variation, (int) $location, -(float) $delta);
    }
    if ($mode === 'stock') { Illuminate\Support\Facades\DB::commit(); }
    echo "DONE\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL);
    exit(1);
}
