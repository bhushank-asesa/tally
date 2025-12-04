<?php
ini_set('memory_limit', '-1');
ini_set('max_execution_time', 0);

$xmlFile = "Transactions.xml";

echo "🚀 Import started...\n";
$skipped = 0;
$failedNodes = 0;
// Remove invalid XML chars before processing
function sanitizeChunk($xml)
{
    // Remove disallowed control characters
    $xml = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $xml);

    // Remove invalid UTF-8 sequences (keeps valid parts)
    $xml = iconv('UTF-8', 'UTF-8//IGNORE', $xml);

    // Trim weird artifacts
    return trim($xml);
}

$reader = new XMLReader();
$reader->open($xmlFile);

$count = 0;

// Safe wrapper to avoid warnings from readOuterXml()
function safeReadOuterXML($reader)
{
    $xml = @$reader->readOuterXml(); // suppress warnings
    if (!$xml || trim($xml) === "") {
        return false;
    }
    return sanitizeChunk($xml);
}

while ($reader->read()) {

    if ($reader->nodeType == XMLReader::ELEMENT && $reader->name === "TALLYMESSAGE") {

        $raw = @$reader->readOuterXml();

        if (!$raw || trim($raw) === "") {
            $failedNodes++;
            continue;
        }

        // Sanitize deeply
        $clean = sanitizeChunk($raw);

        if (trim($clean) === "") {
            $skipped++;
            continue;
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $ok = $dom->loadXML($clean);
        libxml_clear_errors();

        if (!$ok) {
            $skipped++;
            continue;
        }

        // Successfully parsed → now process
        $count++;
        if ($count % 500 === 0) {
            echo "Imported: $count vouchers (Skipped: $skipped, FailedNodes: $failedNodes)\n";
        }
    }
}

echo "-------------------------------- 🎉 Import Finished ✔ Total vouchers: $count (Skipped: $skipped, FailedNodes: $failedNodes)--------------------------------\n";