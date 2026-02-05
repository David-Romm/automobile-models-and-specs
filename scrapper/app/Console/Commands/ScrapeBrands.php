<?php

namespace App\Console\Commands;

use App\Models\Brand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ScrapeBrands extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scrape:brands';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This function scrapes automobile manufacturers from autoevolution.com';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {

        $this->output->info('Looking for brands.');

        $pendingUrls = ['https://www.autoevolution.com/cars/'];
        $visitedUrls = [];
        $seenBrandHashes = [];
        $processedCount = 0;

        $progressbar = $this->output->createProgressBar();
        $progressbar->start();

        while (!empty($pendingUrls)) {

            $pageUrl = array_shift($pendingUrls);
            if (isset($visitedUrls[$pageUrl])) {
                continue;
            }

            $visitedUrls[$pageUrl] = true;

            $htmlSource = browseUrl($pageUrl);
            $pageDom = str_get_html($htmlSource);

            if (!$pageDom) {
                continue;
            }

            $brandDOMs = $pageDom->find('.carman');

            foreach ($brandDOMs as $brandDOM) {

                $url = trim($brandDOM->find('[itemprop="url"]')[0]->content ?? null);
                $name = trim($brandDOM->find('[itemprop="name"]')[0]->plaintext ?? null);
                $logo = trim($brandDOM->find('[itemprop="logo"]')[0]->src ?? null);

                if (!$url || !$name) {
                    continue;
                }

                $hash = \hash('crc32', $url);
                if (isset($seenBrandHashes[$hash])) {
                    continue;
                }

                $seenBrandHashes[$hash] = true;

                Brand::updateOrCreate(
                    ['url_hash' => $hash],
                    [
                        'url' => $url,
                        'name' => $name,
                        'logo' => $logo,
                    ]);

                $processedCount++;
                $progressbar->advance();

            }

            $nextUrls = $this->extractBrandListUrls($pageDom);
            foreach ($nextUrls as $nextUrl) {
                if (!isset($visitedUrls[$nextUrl])) {
                    $pendingUrls[] = $nextUrl;
                }
            }

        }

        $progressbar->finish();

        $this->output->info($processedCount . ' brands inserted/updated on database.');

        return Command::SUCCESS;

    }

    /**
     * Extracts potential brand list pages (letters/pagination).
     *
     * @param simple_html_dom $pageDom
     * @return array
     */
    private function extractBrandListUrls($pageDom): array
    {

        $urls = [];

        foreach ($pageDom->find('a') as $linkDom) {

            $href = trim($linkDom->getAttribute('href') ?? '');
            if (!$href) {
                continue;
            }

            $normalized = $this->normalizeCarsUrl($href);
            if (!$normalized) {
                continue;
            }

            if ($this->isBrandListUrl($normalized)) {
                $urls[$normalized] = true;
            }

        }

        return array_keys($urls);

    }

    /**
     * Normalize relative/absolute links to a full cars URL.
     *
     * @param string $href
     * @return string|null
     */
    private function normalizeCarsUrl(string $href): string|null
    {

        if (str_starts_with($href, '#')) {
            return null;
        }

        if (str_starts_with($href, '//')) {
            $href = 'https:' . $href;
        }

        if (str_starts_with($href, '/')) {
            $href = 'https://www.autoevolution.com' . $href;
        }

        if (!str_starts_with($href, 'http')) {
            return null;
        }

        return $href;

    }

    /**
     * Check if URL looks like a brand list page (letters/pagination).
     *
     * @param string $url
     * @return bool
     */
    private function isBrandListUrl(string $url): bool
    {

        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';
        $query = $parts['query'] ?? '';

        if (!str_contains($host, 'autoevolution.com')) {
            return false;
        }

        if (!str_starts_with($path, '/cars/')) {
            return false;
        }

        if (str_contains($path, '.html')) {
            return false;
        }

        if ($path === '/cars/' || $path === '/cars') {
            return true;
        }

        if (preg_match('#^/cars/[a-z]/?$#i', $path)) {
            return true;
        }

        if (preg_match('#^/cars/letter-[a-z]/?$#i', $path)) {
            return true;
        }

        if ($query) {
            parse_str($query, $queryParams);
            foreach (['letter', 'page', 'p', 'l', 'start'] as $key) {
                if (array_key_exists($key, $queryParams)) {
                    return true;
                }
            }
        }

        return false;

    }
}
