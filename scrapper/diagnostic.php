<?php

// Quick diagnostic script

function normalize(string $text): string {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9\s]/', '', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function similarity(string $a, string $b): float {
    $a = normalize($a);
    $b = normalize($b);

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

// Test with trim data
$autoRecord = [
    'brand' => 'ABARTH',
    'name' => 'Abarth 124 Spider (2016-2024) specs'
];

$trimMaker = 'abarth';
$trimGenmodel = '124 spider';

$makerSim = similarity($autoRecord['brand'], $trimMaker);
$nameSim = similarity($autoRecord['name'], $trimGenmodel);

echo "AutoRecord:\n";
echo "  Brand: " . $autoRecord['brand'] . "\n";
echo "  Name: " . $autoRecord['name'] . "\n\n";

echo "Trim Data:\n";
echo "  Maker: " . $trimMaker . "\n";
echo "  Genmodel: " . $trimGenmodel . "\n\n";

echo "Similarities:\n";
echo "  Maker: " . $makerSim . " (threshold 0.5)\n";
echo "  Name: " . $nameSim . " (threshold 0.3)\n";

echo "\nNormalized:\n";
echo "  Auto brand: " . normalize($autoRecord['brand']) . "\n";
echo "  Trim maker: " . normalize($trimMaker) . "\n";
echo "  Auto name: " . normalize($autoRecord['name']) . "\n";
echo "  Trim genmodel: " . normalize($trimGenmodel) . "\n";
