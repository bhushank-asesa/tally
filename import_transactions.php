<?php
ini_set('memory_limit', '4096M');
ini_set('max_input_vars', '900');
ini_set('max_execution_time', '1200');
try {

    $pdo = new PDO("mysql:host=localhost;dbname=tally", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $filePath = "Transactions.xml";
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

    // Prepare insert statement
    $insertTxn = $pdo->prepare("INSERT INTO tally_transactions (date, amount, type, status, ledger_name, parent_name, full_path, top_parent) VALUES (:date, :amount, :type, :status, :ledger, :parent, :path, :top)");

    foreach ($xml->BODY->IMPORTDATA->REQUESTDATA->TALLYMESSAGE as $msg) {
        if (!isset($msg->VOUCHER))
            continue;
        $voucher = $msg->VOUCHER;
        $voucherGuid = (string) $voucher->GUID;
        $voucherNumber = (string) $voucher->VOUCHERNUMBER;
        $date = DateTime::createFromFormat('Ymd', (string) $voucher->DATE)->format('Y-m-d');
        $narration = (string) $voucher->NARRATION;
        $partyLedgerName = (string) $voucher->PARTYLEDGERNAME;
        $voucherType = (string) $voucher->VOUCHERTYPENAME;
        $createdBy = (string) $voucher->ENTEREDBY;

        $ledgerEntries = $voucher->xpath('ALLLEDGERENTRIES.LIST');

        $debitAmount = 0;
        $creditAmount = 0;
        $accountLedgerName = '';
        $instrumentNumber = '';
        $bankName = '';
        $billRef = '';

        foreach ($ledgerEntries as $entry) {
            $ledgerName = (string) $entry->LEDGERNAME;
            $amount = (float) $entry->AMOUNT;
            $isDeemedPositive = (string) $entry->ISDEEMEDPOSITIVE;

            // Debit or Credit
            if ($amount > 0 && $isDeemedPositive == "No") {
                $debitAmount = $amount;
                $accountLedgerName = $ledgerName;

                if ($entry->xpath('BILLALLOCATIONS.LIST')) {
                    foreach ($entry->xpath('BILLALLOCATIONS.LIST') as $bill) {
                        $billRef .= (string) $bill->NAME . ': ' . (string) $bill->AMOUNT . '; ';
                    }
                }
            } elseif ($amount < 0 && $isDeemedPositive == "Yes") {
                $creditAmount = abs($amount);
                $accountLedgerName = $ledgerName;

                if ($entry->BANKALLOCATIONS->INSTRUMENTNUMBER) {
                    $instrumentNumber = (string) $entry->BANKALLOCATIONS->INSTRUMENTNUMBER;
                    $bankName = (string) $entry->BANKALLOCATIONS->BANKNAME;
                }
            }
        }

        // INSERT into MySQL
        $stmt = $pdo->prepare("
        INSERT INTO all_transactions (
            voucher_guid, voucher_number, date, narration, party_ledger_name, 
            account_ledger_name, debit_amount, credit_amount, voucher_type, 
            bill_ref, instrument_number, bank_name, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

        $stmt->execute([
            $voucherGuid,
            $voucherNumber,
            $date,
            $narration,
            $partyLedgerName,
            $accountLedgerName,
            $debitAmount,
            $creditAmount,
            $voucherType,
            $billRef,
            $instrumentNumber,
            $bankName,
            $createdBy
        ]);

    }
    echo "✅ Transactions imported.\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . " at line " . $e->getLine() . "\n";
}