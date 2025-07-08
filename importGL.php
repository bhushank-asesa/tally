<?php
// import masters actual code 1
ini_set('memory_limit', '2048M');
ini_set('max_input_vars', '600'); // Example: Set max input variables to 3000
ini_set('max_execution_time', '900');
try {
    $pdo = new PDO("mysql:host=localhost;dbname=wasan-tally", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $tallyCompanyId = 2;

    $filePath = "Nashik-Master.xml";
    if (!file_exists($filePath)) {
        die("❌ File not found!");
    }

    $rawXml = file_get_contents($filePath);
    if (!$rawXml) {
        die("❌ File could not be read or is empty!");
    }

    $encoding = mb_detect_encoding($rawXml, ['UTF-8', 'UTF-16', 'UTF-16LE', 'UTF-16BE'], true);
    if ($encoding !== 'UTF-8') {
        $rawXml = mb_convert_encoding($rawXml, 'UTF-8', $encoding);
    }

    $cleanXml = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $rawXml);
    $cleanXml = preg_replace('/&#x?0*4;/i', '', $cleanXml);

    if (substr_count($cleanXml, '<ENVELOPE>') > 1) {
        $cleanXml = "<ROOT>\n" . $cleanXml . "\n</ROOT>";
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($cleanXml);
    if (!$xml) {
        echo "❌ XML parsing failed!\n";
        foreach (libxml_get_errors() as $error) {
            echo "Line {$error->line}: {$error->message}\n";
        }
        exit;
    }

    // Step 1: Import groups and ledgers
    $insert = $pdo->prepare("INSERT IGNORE INTO ledgers (name, type, parent_name, guid, tally_company_id) VALUES (:name, :type, :parent, :guid, :tally_company_id)");

    foreach ($xml->BODY->IMPORTDATA->REQUESTDATA->TALLYMESSAGE as $msg) {
        if (isset($msg->GROUP)) {
            $insert->execute([
                ':name' => (string) $msg->GROUP['NAME'],
                ':type' => 'group',
                ':parent' => (string) $msg->GROUP->PARENT ?: null,
                ':guid' => (string) $msg->GROUP->GUID,
                ':tally_company_id' => (string) $tallyCompanyId,
            ]);
        } elseif (isset($msg->LEDGER)) {
            $insert->execute([
                ':name' => (string) $msg->LEDGER['NAME'],
                ':type' => 'ledger',
                ':parent' => (string) $msg->LEDGER->PARENT ?: null,
                ':guid' => (string) $msg->LEDGER->GUID,
                ':tally_company_id' => (string) $tallyCompanyId,
            ]);
        }
    }

    // Step 2: Build full path and top parent
    function buildHierarchyPath($pdo, $name)
    {
        $path = [];
        $topParent = null;
        while ($name) {
            $stmt = $pdo->prepare("SELECT name, parent_name FROM tally_hierarchy WHERE name = :name LIMIT 1");
            $stmt->execute([':name' => $name]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row)
                break;
            array_unshift($path, $row['name']);
            $topParent = $row['name'];
            $name = $row['parent_name'];
        }
        return ['path' => implode(' → ', $path), 'top' => $topParent];
    }

    echo "✅ Done! Imported hierarchy with parent name.\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . " at line " . $e->getLine() . "\n";
}
