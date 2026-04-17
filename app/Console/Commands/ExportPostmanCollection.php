<?php

namespace App\Console\Commands;

use App\Postman\PostmanCollectionGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class ExportPostmanCollection extends Command
{
    protected $signature = 'postman:export
                            {--output=postman/Salon-SaaS-API.postman_collection.json : Path relative to the application base path}';

    protected $description = 'Generate Postman Collection v2.1 JSON from api routes (middleware + example bodies)';

    public function handle(): int
    {
        $path = $this->laravel->basePath().DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $this->option('output'));
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $collection = PostmanCollectionGenerator::generate();
        file_put_contents($path, json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        Artisan::call('route:list', ['--path' => 'api', '--json' => true]);
        $routeRows = json_decode(Artisan::output(), true);
        $requestCount = is_array($collection['item'] ?? null)
            ? array_sum(array_map(fn ($f) => count($f['item'] ?? []), $collection['item']))
            : 0;

        $this->info("Wrote {$path}");
        $this->line('Postman requests: '.$requestCount);
        $this->line('Raw route:list rows (before PUT/PATCH dedupe): '.(is_array($routeRows) ? count($routeRows) : 0));

        return self::SUCCESS;
    }
}
