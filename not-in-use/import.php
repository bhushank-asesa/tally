<?php
try {
    ini_set('max_input_vars', '900'); // Example: Set max input variables to 3000
    ini_set('max_execution_time', '1200');
    ini_set('memory_limit', '4096M'); // Increase to 1 GB (or more if needed)

    $filePath = "Transactions.xml";

    if (!file_exists($filePath)) {
        die("File not found!");
    }

    $rawXml = file_get_contents($filePath);

    $encoding = mb_detect_encoding($rawXml, ['UTF-8', 'UTF-16', 'UTF-16LE', 'UTF-16BE'], true);
    if ($encoding !== 'UTF-8') {
        $rawXml = mb_convert_encoding($rawXml, 'UTF-8', $encoding);
        $encoding = mb_detect_encoding($rawXml, ['UTF-8', 'UTF-16', 'UTF-16LE', 'UTF-16BE'], true);
    }


    if (!$rawXml) {
        die("File could not be read or is empty!");
    }
    $cleanXml = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $rawXml);
    $cleanXml = preg_replace('/&#x?0*4;/i', '', $cleanXml); // remove &#4; or &#x04;

    $xml = simplexml_load_string($cleanXml);
    if (!$xml) {
        throw new Exception("XML parsing failed!");
    }
    // Loop through all TALLYMESSAGE nodes
    $allCount = $voucherCount = 0;
    $masters = [];
    echo "34 count " . count($xml->BODY->IMPORTDATA->REQUESTDATA->TALLYMESSAGE);
    foreach ($xml->BODY->IMPORTDATA->REQUESTDATA->TALLYMESSAGE as $message) {
        $allCount++;
        // Check if this message contains a VOUCHER node
        if (isset($message->VOUCHER)) {
            $voucher = $message->VOUCHER;
            $voucherCount++;
            // Extract useful info
            $voucherType = (string) $voucher->VOUCHERTYPENAME;
            $voucherDate = (string) $voucher->DATE;
            $voucherNumber = (string) $voucher->VOUCHERNUMBER;
            $partyLedger = (string) $voucher->PARTYLEDGERNAME;

            $isHtml = "<br>";
            echo $isHtml . "$allCount $voucherCount";
            echo $isHtml . "Voucher Type: $voucherType";
            echo $isHtml . "Date: $voucherDate";
            echo $isHtml . "Voucher No: $voucherNumber";
            echo $isHtml . "Party: $partyLedger";
            echo $isHtml . "------------------------";
            if (isset($masters["party"][$partyLedger])) {
                $masters["party"][$partyLedger]++;
            } else {
                $masters["party"][$partyLedger] = 1;
            }
            if (isset($masters['type'][$voucherType])) {
                $masters['type'][$voucherType]++;
            } else {
                $masters['type'][$voucherType] = 1;
            }
            if (isset($masters['date'][$voucherDate])) {
                $masters['date'][$voucherDate]++;
            } else {
                $masters['date'][$voucherDate] = 1;
            }
        }
    }
    echo '<pre>';
    print_r($masters);
    echo '</pre>';
    exit();
} catch (Exception $e) {
    echo 'error ' . $e->getMessage() . ' in file ' . $e->getFile() . ' at line no ' . $e->getLine();
}