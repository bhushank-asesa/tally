<?php
ini_set('memory_limit', '2048M');
ini_set('max_input_vars', '600'); // Example: Set max input variables to 3000
        ini_set('max_execution_time', '900');
$host = "localhost";
$dbname = "tally";
$user = "root";
$pass = "";

try {
    // Connect to DB
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $filePath = "Master.xml";
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

    $groups = $xml->xpath('//TALLYMESSAGE/GROUP');
    echo "✅ Found " . count($groups) . " group records.\n";

    $stmt = $pdo->prepare("INSERT IGNORE INTO groups (name, parent, guid) VALUES (:name, :parent, :guid)");

    $imported = 0;
    foreach ($groups as $group) {
        $name = (string)$group['NAME'];
        $parent = (string)$group->PARENT;
        $guid = (string)$group->GUID;

        if ($name) {
            $stmt->execute([
                ':name' => $name,
                ':parent' => $parent,
                ':guid' => $guid
            ]);
            $imported++;
        }
    }

    echo "✅ Successfully imported $imported group records into MySQL.\n";

} catch (Exception $e) {
    echo '❌ Error: ' . $e->getMessage();
}
