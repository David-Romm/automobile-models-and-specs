<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use JsonMachine\JsonMachine;
use PDO;

class CombineAutoData extends Command
{
    protected $signature = 'app:combine-auto 
        {automobiles : Path to automobiles.json} 
        {brands : Path to brands.json} 
        {engines : Path to engines.json}
        {--out= : Output file path (.jsonl)}';

    protected $description = 'Combine automobiles, brands, and engines into a streamed JSONL file.';

    /**
     * Stream a LARGE JSON array from disk without extra libs.
     * Yields decoded associative arrays for each top-level object.
     * Robust against strings, escapes, nested objects/arrays, and whitespace.
     */
    protected function streamTopLevelArray(string $path): \Generator
    {
        $fh = fopen($path, 'rb');
        if (!$fh) return;

        // Skip to first '['
        $in = '';
        while (!feof($fh)) {
            $c = fgetc($fh);
            if ($c === false) break;
            if (ctype_space($c)) continue;
            if ($c === '[') break;
            // If file doesn't start with '[', bail out
            fclose($fh);
            return;
        }

        // State machine to collect each top-level JSON value
        $buf = '';
        $depth = 0;
        $inString = false;
        $escape = false;
        $started = false;   // started a top-level value
        $finished = false;  // saw closing ']'

        while (!feof($fh) && !$finished) {
            $c = fgetc($fh);
            if ($c === false) break;

            if (!$started) {
                // Skip whitespace and commas before the next value or closing ']'
                if (ctype_space($c) || $c === ',') continue;
                if ($c === ']') { $finished = true; break; }

                // Start of a value (expecting an object `{` or array `[` typically)
                $started = true;
                $buf = '';
                $depth = 0;
                $inString = false;
                $escape = false;
            }

            $buf .= $c;

            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($c === '\\') {
                    $escape = true;
                } elseif ($c === '"') {
                    $inString = false;
                }
                continue;
            } else {
                if ($c === '"') {
                    $inString = true;
                    continue;
                }
                if ($c === '{' || $c === '[') {
                    $depth++;
                } elseif ($c === '}' || $c === ']') {
                    $depth--;
                }
                if ($depth === 0) {
                    // End of the current top-level value
                    $json = trim($buf);
                    // Clean up possible trailing commas/newlines already handled above
                    $decoded = json_decode($json, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        yield $decoded;
                    }
                    // Reset for next value
                    $started = false;
                    $buf = '';
                }
            }
        }

        fclose($fh);
    }
    public function handle()
    {
        $autosPath = $this->argument('automobiles');
        $brandsPath = $this->argument('brands');
        $enginesPath = $this->argument('engines');
        $outPath = $this->option('out') ?: storage_path('app/exports/automobiles_combined.jsonl');

        @mkdir(dirname($outPath), 0777, true);
        @mkdir(storage_path('app/cache'), 0777, true);

        $dbPath = storage_path('app/cache/auto.sqlite');
        if (file_exists($dbPath)) unlink($dbPath);

        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create tables (minimal columns we need)
        $pdo->exec('
            CREATE TABLE brands (
                id INTEGER PRIMARY KEY,
                name TEXT,
                logo TEXT
            );
        ');
        $pdo->exec('CREATE INDEX idx_brands_id ON brands(id);');

        $pdo->exec('
            CREATE TABLE engines (
                id INTEGER PRIMARY KEY,
                automobile_id INTEGER,
                name TEXT,
                specs_json TEXT
            );
        ');
        $pdo->exec('CREATE INDEX idx_engines_auto ON engines(automobile_id);');

        $this->info('Loading brands (streamed)…');
        $this->streamInsert($pdo, $brandsPath, function($row) use ($pdo) {
            // Support both array-of-objects and NDJSON
            if (!isset($row['id'])) return;
            $stmt = $pdo->prepare('INSERT INTO brands (id, name, logo) VALUES (?, ?, ?)');
            $stmt->execute([
                (int)$row['id'],
                $row['name'] ?? null,
                $row['logo'] ?? null,
            ]);
        });

        $this->info('Loading engines (streamed)…');
        $this->streamInsert($pdo, $enginesPath, function($row) use ($pdo) {
            if (!isset($row['id']) || !isset($row['automobile_id'])) return;

            // engines.specs is a JSON STRING in your input — store as-is (we’ll parse later)
            $stmt = $pdo->prepare('INSERT INTO engines (id, automobile_id, name, specs_json) VALUES (?, ?, ?, ?)');
            $stmt->execute([
                (int)$row['id'],
                (int)$row['automobile_id'],
                $row['name'] ?? null,
                $row['specs'] ?? null,
            ]);
        });

        $this->info('Combining automobiles → JSONL (streamed)…');

        $out = fopen($outPath, 'wb');
        if (!$out) {
            $this->error("Cannot open output: $outPath");
            return 1;
        }

        $count = 0;
        $this->streamEach($autosPath, function($auto) use ($pdo, $out, &$count) {
            if (!isset($auto['id'])) return;

            $autoId   = (int)$auto['id'];
            $brandId  = isset($auto['brand_id']) ? (int)$auto['brand_id'] : null;

            // Look up brand
            $brand = [ 'name' => null, 'logo' => null ];
            if ($brandId !== null) {
                $stmt = $pdo->prepare('SELECT name, logo FROM brands WHERE id = ? LIMIT 1');
                $stmt->execute([$brandId]);
                $res = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($res) $brand = $res;
            }

            // Fetch engines/specs for this auto (may be multiple)
            $stmt = $pdo->prepare('SELECT id, name, specs_json FROM engines WHERE automobile_id = ?');
            $stmt->execute([$autoId]);
            $specs = [];
            while ($eng = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $parsed = null;
                if (!empty($eng['specs_json'])) {
                    // Your input has a JSON string — decode twice if it looks double-encoded
                    $parsed = json_decode($eng['specs_json'], true);
                    if ($parsed === null && json_last_error() !== JSON_ERROR_NONE) {
                        // Try decoding un-escaped variants or leave as null
                        $parsed = null;
                    }
                }
                $specs[] = [
                    'engine_id' => (int)$eng['id'],
                    'name'      => $eng['name'],
                    'data'      => $parsed, // nested JSON object for direct access
                ];
            }

            // photos in automobiles.json is a JSON-encoded string → parse to array if needed
            $photos = $auto['photos'] ?? null;
            if (is_string($photos)) {
                $tmp = json_decode($photos, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
                    $photos = $tmp;
                } else {
                    // If not valid JSON, fall back to single string or empty array
                    $photos = $photos ? [$photos] : [];
                }
            } elseif (!is_array($photos)) {
                $photos = $photos ? [$photos] : [];
            }

            $record = [
                'id'          => $autoId,
                'brand'       => $brand['name'],
                'name'        => trim(($auto['name'] ?? '')),
                'description' => $auto['description'] ?? null,
                'photos'      => $photos,
                'logo'        => $brand['logo'],
                'url'         => $auto['url'] ?? null,
                'specs'       => $specs, // array of engines with parsed spec objects
            ];

            fwrite($out, json_encode($record, JSON_UNESCAPED_SLASHES) . "\n");
            $count++;
            if (($count % 5000) === 0) {
                $this->info("Wrote $count automobiles…");
            }
        });

        fclose($out);
        $this->info("Done. Wrote $count automobiles to: $outPath");

        return 0;
    }

    /**
     * Stream a JSON file that may be either:
     *  - a single large array: [ {...}, {...}, ... ]
     *  - NDJSON (one JSON object per line)
     */
    protected function streamEach(string $path, callable $onRow): void
    {
        $firstChar = $this->peekFirstNonWhitespaceChar($path);

        if ($firstChar === '[') {
            // File is a big JSON array: [ {...}, {...}, ... ]
            foreach ($this->streamTopLevelArray($path) as $row) {
                if (is_array($row)) {
                    $onRow($row);
                }
            }
        } else {
            // Treat as NDJSON (one JSON object per line)
            $fh = fopen($path, 'rb');
            if (!$fh) return;
            while (!feof($fh)) {
                $line = fgets($fh);
                if ($line === false) break;
                $line = trim($line);
                if ($line === '') continue;
                $row = json_decode($line, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($row)) {
                    $onRow($row);
                }
            }
            fclose($fh);
        }
    }

    protected function streamInsert(PDO $pdo, string $path, callable $onRow): void
    {
        $this->streamEach($path, $onRow);
    }

    protected function peekFirstNonWhitespaceChar(string $path): ?string
    {
        $fh = fopen($path, 'rb');
        if (!$fh) return null;
        while (!feof($fh)) {
            $c = fgetc($fh);
            if ($c === false) break;
            if (!ctype_space($c)) { fclose($fh); return $c; }
        }
        fclose($fh);
        return null;
    }
}
