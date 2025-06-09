<?php
ini_set('memory_limit', '2048M');

// DB connection
$pdo = new PDO("mysql:host=localhost;dbname=tally;charset=utf8", "root", "");

// Step 1: Load and clean the raw XML
$raw = file_get_contents('Master.xml');

// Remove control characters except tabs, newlines, carriage returns
$clean = preg_replace('/[^\P{C}\t\r\n]+/u', '', $raw);

// Replace problematic standalone ampersands (&)
$clean = preg_replace('/&(?!amp;|lt;|gt;|apos;|quot;|#\d+;)/', '&amp;', $clean);

// Step 2: Wrap in a root tag if not already present
if (strpos($clean, '<ENVELOPE>') === false) {
    // Wrap multiple <TALLYMESSAGE> or fragments in a fake root
    $clean = "<ROOT>\n" . $clean . "\n</ROOT>";
}

// Step 3: Try to parse
$xml = simplexml_load_string($clean);
if (!$xml) {
    echo "❌ XML still invalid after cleaning. Try manually checking near line with &#4;\n";
    exit;
}

// Step 4: Extract ledgers and parent groups
$ledgerHierarchy = [];

foreach ($xml->xpath('//LEDGER') as $ledger) {
    $name = trim((string)$ledger['NAME']);
    $parent = trim((string)$ledger->PARENT);
    $ledgerHierarchy[$name] = $parent;
}

// Step 5: Recursive top group resolution
function findTopGroup($ledger, $map) {
    $current = $ledger;
    $seen = [];
    while (isset($map[$current]) && !in_array($current, $seen)) {
        $seen[] = $current;
        $current = $map[$current];
    }
    return $current;
}

// Step 6: Insert into MySQL
$count = 0;
foreach ($ledgerHierarchy as $ledger => $parent) {
    $top = findTopGroup($ledger, $ledgerHierarchy);
    $stmt = $pdo->prepare("INSERT IGNORE INTO ledgers (name, parent, top_group) VALUES (?, ?, ?)");
    $stmt->execute([$ledger, $parent, $top]);
    $count++;
}

echo "✅ Imported $count ledger records into MySQL.\n";
