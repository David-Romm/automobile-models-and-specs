<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MergeTrimPrices extends Command
{
    protected $signature = 'app:merge-trim-prices 
        {trim_csv : Path to Trim_table.csv} 
        {combined_jsonl : Path to automobiles_combined.jsonl}
        {--out= : Output file path (.jsonl)}';

    protected $description = 'Merge Trim_table.csv prices into automobiles_combined.jsonl using fuzzy matching.';

    public function handle(): int
    {
        $trimCsvPath = $this->argument('trim_csv');
        $combinedJsonlPath = $this->argument('combined_jsonl');
        $outputPath = $this->option('out') ?? $combinedJsonlPath . '.with_prices';

        if (!file_exists($trimCsvPath)) {
            $this->error("Trim CSV not found: $trimCsvPath");
            return 1;
        }

        if (!file_exists($combinedJsonlPath)) {
            $this->error("Combined JSONL not found: $combinedJsonlPath");
            return 1;
        }

        // Load and parse CSV
        $this->output->info("Loading Trim table from CSV...");
        $trimData = $this->loadTrimCsv($trimCsvPath);
        $this->output->info("Loaded " . count($trimData) . " trim entries.");

        // Process JSONL and merge
        $this->output->info("Processing automobiles_combined.jsonl...");
        $this->mergeAndOutput($combinedJsonlPath, $outputPath, $trimData);

        $this->output->info("Merge complete! Output: $outputPath");
        return 0;
    }

    /**
     * Get parent brands mapping for sub-brands.
     */
    private function getBrandMapping(): array
    {
        return [
            'abarth' => ['fiat', 'lancia'],
            'corvette' => ['chevrolet'],
            'ds' => ['ds automobiles', 'citroen'],
            'maybach' => ['mercedes', 'mercedes benz', 'mercedes-benz'],
            'amg' => ['mercedes', 'mercedes benz', 'mercedes-benz'],
            'smart' => ['mercedes', 'mercedes benz', 'mercedes-benz'],
            'chrysler' => ['dodge'],
            'jeep' => ['chrysler'],
            'dodge' => ['chrysler'],
            'ram' => ['dodge'],
            'gmc' => ['chevrolet'],
            'buick' => ['chevrolet'],
            'cadillac' => ['chevrolet'],
        ];
    }

    /**
     * Load trim table CSV and index by (maker, genmodel, year, fuel_type).
     * Also create entries for parent brands.
     */
    private function loadTrimCsv(string $path): array
    {
        $trimData = [];
        $handle = fopen($path, 'r');

        if (!$handle) {
            return [];
        }

        // Skip header
        fgetcsv($handle);

        $brandMapping = $this->getBrandMapping();

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 9) {
                continue;
            }

            $maker = $this->normalize($row[1]); // Maker
            $genmodel = $this->normalize($row[2]); // Genmodel
            $trim = $this->normalize($row[3]); // Trim
            $year = (int)($row[4] ?? 0); // Year
            $price = (float)($row[5] ?? 0); // Price
            $fuelType = $this->normalize($row[7] ?? ''); // Fuel_type

            if (!$maker || !$genmodel || !$year || !$price) {
                continue;
            }

            // Index by maker-genmodel-year-fuel combo
            $key = "{$maker}|{$genmodel}|{$year}|{$fuelType}";
            if (!isset($trimData[$key])) {
                $trimData[$key] = [];
            }

            $trimData[$key][] = [
                'trim' => $trim,
                'price' => $price,
            ];

            // Also index under parent brands if this is a sub-brand
            if (isset($brandMapping[$maker])) {
                foreach ($brandMapping[$maker] as $parentBrand) {
                    $parentKey = "{$parentBrand}|{$genmodel}|{$year}|{$fuelType}";
                    if (!isset($trimData[$parentKey])) {
                        $trimData[$parentKey] = [];
                    }
                    $trimData[$parentKey][] = [
                        'trim' => $trim,
                        'price' => $price,
                    ];
                }
            }
        }

        fclose($handle);
        return $trimData;
    }

    /**
     * Normalize a string for fuzzy matching: lowercase, trim, remove special chars.
     */
    private function normalize(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9\s]/', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Calculate similarity (0-1) between two strings using Levenshtein.
     */
    private function similarity(string $a, string $b): float
    {
        $a = $this->normalize($a);
        $b = $this->normalize($b);

        if ($a === $b) {
            return 1.0;
        }

        if (strlen($a) === 0 || strlen($b) === 0) {
            return 0.0;
        }

        $lev = levenshtein($a, $b);
        $maxLen = max(strlen($a), strlen($b));

        if ($maxLen === 0) {
            return 0.0;
        }

        return 1.0 - ($lev / $maxLen);
    }

    /**
     * Find matching price(s) for an automobile record.
     */
    private function findMatchingPrice(array $autoRecord, array $trimData): ?float
    {
        $brand = $autoRecord['brand'] ?? '';
        $name = $autoRecord['name'] ?? '';

        // Extract year range from name
        $yearRange = $this->extractYearRange($name);
        $year_start = $yearRange['start'] ?? 0;
        $year_end = $yearRange['end'] ?? 0;

        // Extract fuel type from specs or name
        $fuelType = $this->extractFuelType($autoRecord);

        $matches = [];

        foreach ($trimData as $key => $trims) {
            [$trimBrand, $trimGenmodel, $trimYear, $trimFuel] = explode('|', $key);

            // Check if brand matches
            $makerSim = $this->similarity($brand, $trimBrand);
            $nameContainsMaker = stripos($name, $trimBrand) !== false;
            
            if ($makerSim < 0.25 && !$nameContainsMaker) {
                continue;
            }

            // Check if genmodel appears in name
            $nameContainsGenmodel = stripos($name, $trimGenmodel) !== false;
            $nameSim = $this->similarity($name, $trimGenmodel);
            if (!$nameContainsGenmodel && $nameSim < 0.1) {
                continue;
            }

            // Year checking: very lenient
            if ($year_start && $year_end) {
                // Allow any trim year that falls in or close to range
                if ($trimYear < $year_start - 5 || $trimYear > $year_end + 5) {
                    continue;
                }
            } elseif ($year_start) {
                // If only start year, allow wider range
                if ($trimYear < $year_start - 5 || $trimYear > $year_start + 15) {
                    continue;
                }
            }
            // If no year data, accept all years

            // Don't filter by fuel type - too unreliable given varying data quality

            // This is a match; collect all prices
            foreach ($trims as $trim) {
                $matches[] = $trim['price'];
            }
        }

        if (empty($matches)) {
            return null;
        }

        // Return average of all matching prices
        return array_sum($matches) / count($matches);
    }

    /**
     * Extract fuel type from automobile record.
     */
    private function extractFuelType(array $autoRecord): ?string
    {
        $specs = $autoRecord['specs'] ?? [];
        if (is_string($specs)) {
            $specs = json_decode($specs, true) ?? [];
        }

        // Look for fuel type in specs
        if (is_array($specs) && !empty($specs)) {
            foreach ($specs as $spec) {
                if (isset($spec['data']['Engine Specs']['Fuel:'])) {
                    return $spec['data']['Engine Specs']['Fuel:'];
                }
            }
        }

        // Fallback: try to extract from name
        if (stripos($autoRecord['name'] ?? '', 'electric') !== false) {
            return 'Electric';
        }
        if (stripos($autoRecord['name'] ?? '', 'hybrid') !== false) {
            return 'Hybrid';
        }
        if (stripos($autoRecord['name'] ?? '', 'diesel') !== false) {
            return 'Diesel';
        }

        return null;
    }

    /**
     * Extract year range from name (e.g., "2013-2018" in parens or at start).
     */
    private function extractYearRange(string $name): array
    {
        $years = [];
        
        // Try to find year range in parentheses: (2013-2018)
        if (preg_match('/\((\d{4})-(\d{4})\)/', $name, $matches)) {
            $years['start'] = (int)$matches[1];
            $years['end'] = (int)$matches[2];
            return $years;
        }

        // Try to find year range at start or anywhere: 2013 ... 2018 or just YYYY
        if (preg_match('/(\d{4})\s*(?:.*?(\d{4}))?/', $name, $matches)) {
            $years['start'] = (int)$matches[1];
            $years['end'] = (int)($matches[2] ?? $matches[1]);
        }

        return $years;
    }

    /**
     * Read JSONL, merge prices, and write to output.
     */
    private function mergeAndOutput(string $inputPath, string $outputPath, array $trimData): void
    {
        $inHandle = fopen($inputPath, 'r');
        $outHandle = fopen($outputPath, 'w');

        if (!$inHandle || !$outHandle) {
            $this->error("Cannot open files for processing.");
            return;
        }

        $count = 0;
        $merged = 0;
        $progressBar = $this->output->createProgressBar();
        $progressBar->start();

        while (($line = fgets($inHandle)) !== false) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $record = json_decode($line, true);
            if (!$record) {
                fwrite($outHandle, $line . "\n");
                $count++;
                $progressBar->advance();
                continue;
            }

            // Try to find and add price
            $price = $this->findMatchingPrice($record, $trimData);
            if ($price !== null) {
                $record['price'] = round($price, 2);
                $merged++;
            }

            fwrite($outHandle, json_encode($record) . "\n");
            $count++;
            $progressBar->advance();
        }

        $progressBar->finish();
        fclose($inHandle);
        fclose($outHandle);

        $this->output->writeln("\n");
        $this->output->info("Processed $count records, merged prices into $merged records.");
    }
}
