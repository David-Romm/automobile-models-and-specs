<?php

namespace App\Console\Commands;

ini_set('memory_limit', '1G');
error_reporting(E_ALL);

use App\Models\Automobile;
use App\Models\Brand;
use App\Models\Engine;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use simple_html_dom;
use Throwable;

class ScrapeAutomobiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scrape:automobiles {--start-over=} {--limit=} {--fast}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This function scrapes automobile models from the autoevolution.com';

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
     * @throws Throwable
     */
    public function handle(): int
    {

        //Ask for continue from that point process stopped.
        $forceAll = $this->option('start-over') ?? $this->ask('Do you want to start over? (yes/no)');

        //Truncate models
        if ($forceAll === 'yes') {
            Automobile::truncate();
            Engine::truncate();
            Brand::truncate();
        }

        //Scrape brands from scratch.
        $this->call('scrape:brands');

    //Print an info about process.
        $this->output->info('Looking for automobile models.');

        //Get rows as array that keeps row DOMs.
        $automobileRowsDOMs = $this->getAutomobileRowDOMs();

        if ($automobileRowsDOMs) {

            // Optionally limit how many NEW models to process
            $limit = $this->option('limit');
            $limit = is_null($limit) ? null : (int)$limit;

            //Count automobile rows count.
            $modelsCount = count($automobileRowsDOMs);

            //Create a console progressbar.
            $progressbar = $this
                ->output
                ->createProgressBar($limit ? min($limit, $modelsCount) : $modelsCount);
            $progressbar->setFormat('very_verbose');
            $progressbar->start();

            //Print an info about models count.
            $this->output->info($modelsCount . ' models found.');

            $processedNew = 0;

            foreach ($automobileRowsDOMs as $automobileRowDOM) {

                //Get automobile detail page url.
                $detailURL = $automobileRowDOM->find('a', 0)->href ?? null;

                DB::beginTransaction();

                try{

                    //Check process continue option
                    $automobile = Automobile::where('url_hash', hash('crc32', $detailURL))->first();

                    //If automobile exists in database, do not process it.
                    if ($automobile) {
                        // When limiting, only count newly processed ones in the progress bar
                        if (is_null($limit)) {
                            $progressbar->advance();
                        }
                        continue;
                    }

                    //Process automobile detail page.
                    $this->processAutomobileDetailPage($detailURL);

                    DB::commit();

                    //Increase progressbar (only when we actually processed a new model if limiting)
                    $progressbar->advance();

                    $processedNew++;
                    if (!is_null($limit) && $processedNew >= $limit) {
                        break;
                    }

                }catch (Throwable $exception){

                    DB::rollback();

                    throw $exception;

                }

            }

            //Finish progressbar.
            $progressbar->finish();

        } else {

            $this
                ->output
                ->error('There is no automobile row found in search page.');

        }

        //Print an information that process finished.
        $this
            ->output
            ->info(count($automobileRowsDOMs) . ' models inserted/updated on database.');

        return self::SUCCESS;

    }

    /**
     * Convert the case of the string to the title case.
     *
     * @param string $text
     * @return string
     */
    private function toTitleCase(string $text): string
    {
        return mb_convert_case(trim($text), MB_CASE_TITLE);
    }

    /**
     * Drops HTML elements' attributes to make them more clear.
     *
     * @param string $text
     * @return string
     */
    private function dropHtmlAttributes(string $text): string
    {
        $clean= preg_replace("/<([a-z][a-z0-9]*)[^>]*?(\/?)>/si", '<$1$2>', $text);
        return trim($clean);
    }

    /**
     * Loads a URL source as html DOM.
     *
     * @param string $url
     * @return simple_html_dom|bool
     */
    private function loadURLAsDom(string $url): simple_html_dom|bool
    {

        return str_get_html(browseUrl($url));

    }

    /**
     * Gets a press release if it exists for the automobile.
     *
     * @param simple_html_dom $pageDom
     * @return string|null
     */
    private function getPressRelease(simple_html_dom $pageDom): string|null
    {

        $pressRelease = null;

        // Parse id to get press release
        $iForPressRelease = $pageDom->find('[onclick^="aeshowpress("]', 0);

        if ($iForPressRelease) {

            $onClick = $iForPressRelease->getAttribute('onclick') ?? '';

            if (preg_match('/aeshowpress\((\d+)\s*,/i', $onClick, $matches) && isset($matches[1])) {

                $raw = browseUrl('https://www.autoevolution.com/rhh.php?k=pr_cars&i=' . $matches[1]);
                if ($raw) {
                    $prDom = str_get_html($raw);
                    if ($prDom) {
                        $contentNode = $prDom->find('.content', 0) ?? $prDom->find('.fl.newstext', 0) ?? $prDom->find('body', 0);
                        if ($contentNode) {
                            $pressRelease = $this->dropHtmlAttributes($contentNode->innertext);
                        }
                    }
                }

            }

        }

        return $pressRelease;

    }

    /**
     * Loads search page and get brand ids as a comma-separated string.
     *
     * @return string|null
     */
    private function getBrandIds(): string|null
    {

        $pageContents = browseUrl('https://www.autoevolution.com/carfinder/');

        $pageDom = str_get_html($pageContents);

        $brandIds = null;

        $selectBoxItemDOMs = $pageDom
            ->find('.cfrow', 1)
            ->find('ul [data-opt]');

        foreach ($selectBoxItemDOMs as $boxItemDom) {
            $brandIds[] = $boxItemDom->getAttribute('data-opt');
        }

        return $brandIds ? implode(',', $brandIds) : null;

    }

    /**
     * Gets automobile rows from the search page.
     *
     * @return array|null
     */
    private function getAutomobileRowDOMs(): array|null
    {

        $brandIds = $this->getBrandIds();

        if (!$brandIds) {
            $this
                ->output
                ->error('Brand ids for searching models is could not received.');
            die();
        }

        //Make another request to get all automobiles (reuse brandIds to avoid duplicate request)
        $pageContents = browseUrlPost('https://www.autoevolution.com/carfinder/', [
            'n[brand]' => $brandIds,
            'n[submitted]' => 1
        ]);

        $pageDom = str_get_html($pageContents);

        return $pageDom->find('h5');

    }

    /**
     * Get's automobile description from HTML source.
     *
     * @param simple_html_dom $pageDom
     * @return string|null
     */
    private function getContent(simple_html_dom $pageDom): string|null
    {

        $description = null;
        $descriptionDoms = $pageDom
            ->find('.fl.newstext .mgbot_20');
        //get last description dom
        $descriptionDom = end($descriptionDoms);

        if ($descriptionDom) {
            $description = $this->dropHtmlAttributes(
                $descriptionDom->innertext
            );
        }

        return $description;

    }

    /**
     * Gets automobile photos from HTML resource.
     *
     * @param simple_html_dom $pageDom
     * @return array|null
     */
    private function getPhotos(simple_html_dom $pageDom): array|null
    {

        $photos = null;

        $photosJSON = json_decode(
            $pageDom
                ->find('#schema-gallery-data', 0)
                ->innertext ?? null
        );

        if (is_object($photosJSON)) {
            foreach ($photosJSON->associatedMedia as $media) {
                $photos[] = $media->contentUrl;
            }
        }

        return $photos;

    }

    /**
     * Processes automobile detail page.
     *
     * @param string $detailURL
     * @return void
     * @throws Exception
     */
    private function processAutomobileDetailPage(string $detailURL): void
    {

        $pageDom = $this->loadURLAsDom($detailURL);

        if ($pageDom) {

            $name = $pageDom->find('.newstitle', 0)->plaintext ?? null;
            $brandName = trim($pageDom->find('[itemprop="itemListElement"]', 2)->plaintext ?? null);
            $brand = Brand::where('name', $brandName)->first();

            if (!$brand) {
                throw new Exception($brandName . ' could not found in database.');
            }

            $automobile = Automobile::updateOrCreate([
                'url_hash' => hash('crc32', $detailURL),
            ], [
                'url' => $detailURL,
                'brand_id' => $brand->id,
                'name' => $name,
                'description' => $this->getContent($pageDom),
                'press_release' => $this->option('fast') ? null : $this->getPressRelease($pageDom),
                'photos' => $this->option('fast') ? null : $this->getPhotos($pageDom),
            ]);

            // First, try to detect body type, segment, infotainment from page-level data
            $pageLevelBodyType = $this->getBodyType($pageDom);
            $pageLevelSegment = $this->getSegment($pageDom);
            $pageLevelInfotainment = $this->getInfotainment($pageDom);

            // Process engines and try to detect these fields from specs as a fallback
            $engineDetections = $this->processEngineDOMs($automobile->id, $pageDom);

            $finalBodyType = $pageLevelBodyType ?? ($engineDetections['body_type'] ?? null);
            $finalSegment = $pageLevelSegment ?? ($engineDetections['segment'] ?? null);
            $finalInfotainment = $pageLevelInfotainment ?? ($engineDetections['infotainment'] ?? null);

            $changed = false;
            if ($finalBodyType) { $automobile->body_type = $finalBodyType; $changed = true; }
            if ($finalSegment) { $automobile->segment = $finalSegment; $changed = true; }
            if ($finalInfotainment) { $automobile->infotainment = $finalInfotainment; $changed = true; }
            if ($changed) { $automobile->save(); }

        } else {

            throw new Exception($detailURL . ' could not load as dom.');

        }

    }

    /**
     * Processes automobile's engine variants.
     *
     * @param int $automobileId
     * @param simple_html_dom $pageDom
     * @return void
     */
    private function processEngineDOMs(int $automobileId, simple_html_dom $pageDom): array
    {

        $engineVariants = $pageDom->find('[data-engid]');

        $detectedBodyType = null;
        $detectedSegment = null;
        $detectedInfotainment = null;

        foreach ($engineVariants as $engineVariant) {

            $otherId = $engineVariant->getAttribute('data-engid');
            $name = $engineVariant->find('.enginedata .title .col-green', 0)->plaintext ?? null;

            if (!$name) {
                continue;
            }

            $specs = [];

            foreach ($engineVariant->find('.techdata') as $techData) {

                $sectionName = $techData->find('.title', 0)->plaintext ?? null;

                if (str_contains($sectionName, 'ENGINE SPECS')) {
                    $sectionName = 'ENGINE SPECS';
                }

                $sectionName = $this->toTitleCase($sectionName);
                $sectionRows = $techData->find('tr');

                foreach ($sectionRows as $row) {

                    $rowColumns = $row->find('td');

                    if (count($rowColumns) !== 2) {
                        continue;
                    }

                    $specColumn = $rowColumns[0];
                    $valueColumn = $rowColumns[1];

                    $specName = $this->toTitleCase($specColumn->plaintext ?? null);
                    $specValue = $this->toTitleCase($valueColumn->plaintext ?? null);
                    $specs[$sectionName][$specName] = $specValue;

                    // Try to detect body type from a likely 'Body' section
                    if (!$detectedBodyType) {
                        $isBodySection = ($sectionName === 'Body') || str_contains(mb_strtolower($sectionName), 'body');
                        $specLower = mb_strtolower($specName);
                        $isBodyTypeSpec = ($specName === 'Body Type') || str_contains($specLower, 'body type') || str_contains($specLower, 'body style');
                        if ($isBodySection && $isBodyTypeSpec && $specValue) {
                            $detectedBodyType = $specValue;
                        }
                    }

                    // Try to detect segment from a general/body section
                    if (!$detectedSegment) {
                        $isGeneralish = ($sectionName === 'Body') || ($sectionName === 'General') || str_contains(mb_strtolower($sectionName), 'body');
                        if ($isGeneralish && str_contains(mb_strtolower($specName), 'segment') && $specValue) {
                            $detectedSegment = $specValue;
                        }
                    }

                    // Try to detect infotainment from infotainment/multimedia section
                    if (!$detectedInfotainment) {
                        $isInfotainmentSection = ($sectionName === 'Infotainment') || str_contains(mb_strtolower($sectionName), 'infotainment') || str_contains(mb_strtolower($sectionName), 'multimedia');
                        $specLower2 = mb_strtolower($specName);
                        if ($isInfotainmentSection && (str_contains($specLower2, 'infotainment') || str_contains($specLower2, 'system'))) {
                            if ($specValue) {
                                $detectedInfotainment = $specValue;
                            }
                        }
                    }

                }

            }

            Engine::updateOrCreate([
                'other_id' => $otherId,
            ], [
                'automobile_id' => $automobileId,
                'name' => $name,
                'specs' => $specs,
            ]);

        }

        return [
            'body_type' => $detectedBodyType,
            'segment' => $detectedSegment,
            'infotainment' => $detectedInfotainment,
        ];

    }

    /**
     * Try to get Segment from page-level tables or JSON-LD/microdata.
     */
    private function getSegment(simple_html_dom $pageDom): string|null
    {
        // Plaintext header fallback (e.g., "Segment: Medium Premium")
        $plain = $pageDom->plaintext ?? '';
        if ($plain) {
            if (preg_match('/Segment\s*:\s*(.+?)(?:Infotainment\s*:|\r|\n)/i', $plain, $m)) {
                $val = trim($m[1]);
                if ($val) return $this->toTitleCase($val);
            }
        }
        // Tables
        foreach ($pageDom->find('tr') as $row) {
            $cols = $row->find('td');
            if (count($cols) !== 2) continue;
            $label = $this->toTitleCase($cols[0]->plaintext ?? '');
            $value = $this->toTitleCase($cols[1]->plaintext ?? '');
            if (str_contains(mb_strtolower($label), 'segment') && $value) return $value;
        }
        // Microdata (rare)
        $item = $pageDom->find('[itemprop="segment"]', 0);
        if ($item) {
            $text = trim($item->plaintext ?? '');
            if ($text) return $this->toTitleCase($text);
            $content = trim($item->getAttribute('content') ?? '');
            if ($content) return $this->toTitleCase($content);
        }
        // JSON-LD guess
        foreach ($pageDom->find('script[type="application/ld+json"]') as $script) {
            $json = json_decode($script->innertext ?? '', true);
            if (!$json) continue;
            $candidates = [];
            if (is_array($json)) {
                $isAssoc = array_keys($json) !== range(0, count($json) - 1);
                $candidates = $isAssoc ? [$json] : $json;
            }
            foreach ($candidates as $node) {
                if (!is_array($node)) continue;
                foreach (['segment','vehicleSegment','vehicleClass'] as $k) {
                    if (!empty($node[$k]) && is_string($node[$k])) return $this->toTitleCase($node[$k]);
                }
                foreach (['vehicleModel','model'] as $childKey) {
                    if (!empty($node[$childKey]) && is_array($node[$childKey])) {
                        foreach (['segment','vehicleSegment','vehicleClass'] as $k) {
                            if (!empty($node[$childKey][$k]) && is_string($node[$childKey][$k])) return $this->toTitleCase($node[$childKey][$k]);
                        }
                    }
                }
            }
        }
        return null;
    }

    /**
     * Try to get Infotainment from page-level tables.
     */
    private function getInfotainment(simple_html_dom $pageDom): string|null
    {
        // Plaintext header fallback (icons may follow, so capture tokens on page too)
        $plain = $pageDom->plaintext ?? '';
        if ($plain) {
            if (preg_match('/Infotainment\s*:\s*(.+?)(?:\r|\n|Specs\s*&\s*engine\s*options)/i', $plain, $m)) {
                $val = trim($m[1]);
                if ($val && strip_tags($val)) return $this->toTitleCase($val);
            }
            $tokens = [];
            foreach ([
                'Apple CarPlay',
                'Android Auto',
                'Android Automotive',
                'Apple CarPlay Wireless',
                'Android Auto Wireless'
            ] as $t) {
                if (stripos($plain, $t) !== false) $tokens[] = $t;
            }
            if ($tokens) return implode(', ', array_values(array_unique($tokens)));
        }
        // Tables
        foreach ($pageDom->find('tr') as $row) {
            $cols = $row->find('td');
            if (count($cols) !== 2) continue;
            $label = $this->toTitleCase($cols[0]->plaintext ?? '');
            $value = $this->toTitleCase($cols[1]->plaintext ?? '');
            $lower = mb_strtolower($label);
            if ((str_contains($lower, 'infotainment') || str_contains($lower, 'multimedia')) && $value) return $value;
        }
        // No solid microdata/JSON-LD source known; return null if not found
        return null;
    }

    /**
     * Try to extract body type from page-level content (outside engine variants).
     * Heuristics:
     *  - Look for rows where first cell contains 'Body Type' or 'Body Style'.
     *  - Look for microdata itemprop="bodyType"/"bodyStyle".
     *  - Parse JSON-LD blocks and look for bodyType/vehicleBodyType/bodyStyle fields.
     *
     * @param simple_html_dom $pageDom
     * @return string|null
     */
    private function getBodyType(simple_html_dom $pageDom): string|null
    {
        // Plaintext header fallback (e.g., "Body style: Wagon ... Segment: ...")
        $plain = $pageDom->plaintext ?? '';
        if ($plain) {
            if (preg_match('/Body\s*style\s*:\s*(.+?)(?:Segment\s*:|Infotainment\s*:|\r|\n)/i', $plain, $m)) {
                $val = trim($m[1]);
                if ($val) return $this->toTitleCase($val);
            }
        }
        // 1) Generic table scan: find tr with two tds, first matching label
        foreach ($pageDom->find('tr') as $row) {
            $cols = $row->find('td');
            if (count($cols) !== 2) {
                continue;
            }
            $label = $this->toTitleCase($cols[0]->plaintext ?? '');
            $value = $this->toTitleCase($cols[1]->plaintext ?? '');
            $labelLower = mb_strtolower($label);
            if ((str_contains($labelLower, 'body type') || str_contains($labelLower, 'body style')) && $value) {
                return $value;
            }
        }

        // 2) Microdata itemprop
        $item = $pageDom->find('[itemprop="bodyType"],[itemprop="bodytype"],[itemprop="bodystyle"],[itemprop="bodyStyle"]', 0);
        if ($item) {
            $text = trim($item->plaintext ?? '');
            if ($text) {
                return $this->toTitleCase($text);
            }
            $content = trim($item->getAttribute('content') ?? '');
            if ($content) {
                return $this->toTitleCase($content);
            }
        }

        // 3) JSON-LD blocks
        foreach ($pageDom->find('script[type="application/ld+json"]') as $script) {
            $json = json_decode($script->innertext ?? '', true);
            if (!$json) {
                continue;
            }
            $candidates = [];
            if (is_array($json)) {
                $isAssoc = array_keys($json) !== range(0, count($json) - 1);
                $candidates = $isAssoc ? [$json] : $json;
            }
            foreach ($candidates as $node) {
                if (!is_array($node)) continue;
                $keys = ['bodyType', 'vehicleBodyType', 'bodyStyle'];
                foreach ($keys as $k) {
                    if (!empty($node[$k]) && is_string($node[$k])) {
                        return $this->toTitleCase($node[$k]);
                    }
                }
                // Sometimes nested under 'vehicleModel' or 'model'
                foreach (['vehicleModel', 'model'] as $childKey) {
                    if (!empty($node[$childKey]) && is_array($node[$childKey])) {
                        foreach ($keys as $k) {
                            if (!empty($node[$childKey][$k]) && is_string($node[$childKey][$k])) {
                                return $this->toTitleCase($node[$childKey][$k]);
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

}
