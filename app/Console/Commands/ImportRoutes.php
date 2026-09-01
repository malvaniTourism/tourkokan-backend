<?php

namespace App\Console\Commands;

use App\Imports\RouteAndRouteStopsImport;
use App\Models\Route;
use App\Models\RouteStops;
use App\Models\Site;
use Illuminate\Console\Command;
use Maatwebsite\Excel\Facades\Excel;

class ImportRoutes extends Command
{
    protected $signature = 'routes:import
                            {taluka? : Taluka name or CSV path. Omit to list what is available.}
                            {--all : Import every CSV in excels/Routes/Final}
                            {--queue : Dispatch to the queue instead of running inline}';

    protected $description = 'Import a taluka route CSV (excels/Routes/Final) into routes + route_stops';

    private const DIR = 'excels/Routes/Final';

    public function handle(): int
    {
        $files = $this->resolveFiles();

        if ($files === []) {
            $this->components->error('Nothing to import.');
            $this->line('Available: ' . (implode(', ', $this->available()) ?: 'none — put CSVs in ' . self::DIR));

            return self::FAILURE;
        }

        foreach ($files as $file) {
            $this->importOne($file);
        }

        return self::SUCCESS;
    }

    private function importOne(string $path): void
    {
        $taluka    = str_replace('_prod_routes', '', pathinfo($path, PATHINFO_FILENAME));
        $errorFile = "routes/{$taluka}_errors.csv";

        $before = [
            'routes' => Route::count(),
            'stops'  => RouteStops::count(),
            'sites'  => Site::count(),
        ];

        $this->components->info("Importing {$taluka} — " . basename($path));

        Excel::import(new RouteAndRouteStopsImport($errorFile, ! $this->option('queue')), $path);

        if ($this->option('queue')) {
            $this->components->warn('Queued — nothing is written until a queue worker runs.');

            return;
        }

        $this->components->twoColumnDetail('routes created', (string) (Route::count() - $before['routes']));
        $this->components->twoColumnDetail('route stops created', (string) (RouteStops::count() - $before['stops']));
        $this->components->twoColumnDetail('sites auto-created', (string) (Site::count() - $before['sites']));
        $this->components->twoColumnDetail('errors logged to', "storage/app/{$errorFile}");
    }

    /** @return array<int, string> */
    private function resolveFiles(): array
    {
        if ($this->option('all')) {
            return glob(base_path(self::DIR . '/*.csv')) ?: [];
        }

        $arg = $this->argument('taluka');

        if ($arg === null) {
            return [];
        }

        // Accept either a bare taluka name or an explicit path.
        foreach ([$arg, base_path($arg), base_path(self::DIR . "/{$arg}.csv"), base_path(self::DIR . "/{$arg}_prod_routes.csv")] as $candidate) {
            if (is_file($candidate)) {
                return [$candidate];
            }
        }

        $this->components->error("No CSV found for '{$arg}'.");

        return [];
    }

    /** @return array<int, string> */
    private function available(): array
    {
        return array_map(
            fn ($f) => str_replace('_prod_routes', '', pathinfo($f, PATHINFO_FILENAME)),
            glob(base_path(self::DIR . '/*.csv')) ?: []
        );
    }
}
