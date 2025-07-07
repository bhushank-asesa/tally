<?php

// import transaction actual code
ini_set('memory_limit', '4096M');
ini_set('max_input_vars', '900');
ini_set('max_execution_time', '1200');
try {
    $pdo = new PDO("mysql:host=localhost;dbname=tally-2", "root", "");
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
    $txnCount = 0;
    foreach ($xml->BODY->IMPORTDATA->REQUESTDATA->TALLYMESSAGE as $msg) {
        if (!isset($msg->VOUCHER))
            continue;
        $pdo->beginTransaction();

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

        $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_guid, voucher_number, date, narration, party_ledger_name, voucher_type, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$voucherGuid, $voucherNumber, $date, $narration, $partyLedgerName, $voucherType, $createdBy]);
        $voucherId = $pdo->lastInsertId();

        $ledgerStmt = $pdo->prepare("INSERT INTO voucher_ledger_entries (voucher_id, ledger_name, amount, is_deemed_positive, type) VALUES (?, ?, ?, ?, ?)");
        foreach ($ledgerEntries as $entry) {
            $ledgerName = (string) $entry->LEDGERNAME;
            $amount = (float) $entry->AMOUNT;
            $isDeemedPositive = (string) $entry->ISDEEMEDPOSITIVE;
            $type = ($isDeemedPositive === "Yes") ? 'Credit' : 'Debit';
            $ledgerStmt->execute([$voucherId, $ledgerName, abs($amount), $isDeemedPositive, $type]);

            // Optional: Insert BANKALLOCATIONS if exists
            if ($entry->BANKALLOCATIONS) {
                foreach ($entry->BANKALLOCATIONS->children() as $bankAlloc) {
                    $bankStmt = $pdo->prepare("INSERT INTO voucher_bank_allocations (voucher_id, ledger_name, instrument_number, bank_name) VALUES (?, ?, ?, ?)");
                    $bankStmt->execute([
                        $voucherId,
                        $ledgerName,
                        (string) $bankAlloc->INSTRUMENTNUMBER,
                        (string) $bankAlloc->BANKNAME
                    ]);
                }
            }
        }
        $inventoryEntries = $voucher->xpath('INVENTORYENTRIES.LIST');
        $invStmt = $pdo->prepare("INSERT INTO voucher_inventory_entries (voucher_id, stock_item_name, rate, amount, actual_qty, billed_qty) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($inventoryEntries as $inv) {
            $invStmt->execute([
                $voucherId,
                (string) $inv->STOCKITEMNAME,
                (float) $inv->RATE,
                (float) $inv->AMOUNT,
                (string) $inv->ACTUALQTY,
                (string) $inv->BILLEDQTY
            ]);
        }
        // GST
        $gstDetails = $voucher->xpath('GSTDETAILS.LIST');
        $gstStmt = $pdo->prepare("INSERT INTO voucher_gst_details (voucher_id, tax_rate, gst_registration_type, taxable_amount) VALUES (?, ?, ?, ?)");
        foreach ($gstDetails as $gst) {
            $gstStmt->execute([
                $voucherId,
                (float) $gst->TAXRATE,
                (string) $gst->TAXTYPE,
                (float) $gst->TAXABLEAMOUNT
            ]);
        }

        // TDS
        $tdsEntries = $voucher->xpath('TDSENTRIES.LIST');
        $tdsStmt = $pdo->prepare("INSERT INTO voucher_tds_entries (voucher_id, tds_section_name, tds_amount, assessable_amount) VALUES (?, ?, ?, ?)");
        foreach ($tdsEntries as $tds) {
            $tdsStmt->execute([
                $voucherId,
                (string) $tds->NATUREOFPAYMENT,
                (float) $tds->TDSAMOUNT,
                (float) $tds->ASSESSABLEAMOUNT
            ]);
        }
        $pdo->commit();
        $txnCount++;
    }
    echo "✅ $txnCount Transactions imported.\n";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "❌ Error: " . $e->getMessage() . " at line " . $e->getLine() . "\n";
}