<?php
/**
 * XML IMPORTER - Batch Processing for Large Files
 * Imports Tally XML vouchers into database with efficient batch processing
 * Handles files up to 2GB+ with optimized memory usage
 */

ini_set('memory_limit', '4096M');
ini_set('max_execution_time', '3600');

// Logging configuration
define('LOG_FILE', 'xml_import.log');
define('LOG_MAX_SIZE', 10 * 1024 * 1024); // 10MB max log file size

/**
 * Log messages to file with timestamp
 */
function logMessage($level, $message, $context = [])
{
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message";

    // Add context if provided
    if (!empty($context)) {
        $logEntry .= " | Context: " . json_encode($context);
    }

    $logEntry .= PHP_EOL;

    // Rotate log if too large
    if (file_exists(LOG_FILE) && filesize(LOG_FILE) > LOG_MAX_SIZE) {
        $backupFile = LOG_FILE . '.' . date('Y-m-d_His');
        @rename(LOG_FILE, $backupFile);
    }

    // Write to log file
    @file_put_contents(LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);

    // Also output to console
    echo $message . PHP_EOL;
}

/**
 * Log error with stack trace
 */
function logError($message, $exception = null, $context = [])
{
    $errorMsg = "ERROR: $message";

    if ($exception instanceof Exception) {
        $errorMsg .= " | Exception: " . $exception->getMessage();
        $errorMsg .= " | File: " . $exception->getFile() . ":" . $exception->getLine();
        $errorMsg .= " | Stack Trace: " . $exception->getTraceAsString();
        $context['exception_class'] = get_class($exception);
    }

    logMessage('ERROR', $errorMsg, $context);
}

/**
 * Log warning
 */
function logWarning($message, $context = [])
{
    logMessage('WARNING', "WARNING: $message", $context);
}

/**
 * Log info
 */
function logInfo($message, $context = [])
{
    logMessage('INFO', $message, $context);
}

// Define helper functions before use
/**
 * Format bytes to human readable format
 */
function formatBytes($bytes, $precision = 2)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Format time to human readable format
 */
function formatTime($seconds)
{
    if ($seconds < 60) {
        return number_format($seconds, 2) . ' seconds';
    } elseif ($seconds < 3600) {
        $mins = floor($seconds / 60);
        $secs = $seconds % 60;
        return $mins . 'm ' . number_format($secs, 2) . 's';
    } else {
        $hours = floor($seconds / 3600);
        $mins = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        return $hours . 'h ' . $mins . 'm ' . number_format($secs, 2) . 's';
    }
}

require "clean_xml.php";

$input = "../Transactions.xml";
$cleanFile = "cleaned.xml"; // Created in same directory as script

// Get absolute path for logging
$cleanFileAbsolute = realpath(dirname(__FILE__)) . DIRECTORY_SEPARATOR . $cleanFile;

// Configuration
$BATCH_SIZE = 1000; // Insert in batches of 1000 records
$PROGRESS_INTERVAL = 1000; // Show progress every 1000 records

logInfo("====================================== XML Import Started ======================================");
logInfo("Configuration", [
    'input_file' => $input,
    'clean_file' => $cleanFile,
    'clean_file_full_path' => $cleanFileAbsolute,
    'script_directory' => dirname(__FILE__),
    'batch_size' => $BATCH_SIZE,
    'progress_interval' => $PROGRESS_INTERVAL,
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time')
]);

// STEP 1: CLEAN FILE
logInfo("Step 1: Cleaning XML file");
$cleanResult = cleanXmlFile($input, $cleanFile);
if (!$cleanResult) {
    logError("XML cleaning failed - aborting import", null, ['input' => $input, 'output' => $cleanFile]);
    die("❌ XML cleaning failed. Check log file: " . LOG_FILE . "\n");
}
logInfo("XML cleaned successfully");

// STEP 2: READ USING XMLReader WITH BATCH PROCESSING
logInfo("Step 2: Connecting to database");
try {
    $pdo = require "db.php";
    if (!$pdo || !($pdo instanceof PDO)) {
        logError("Database connection failed: db.php did not return valid PDO object");
        die("❌ Database connection failed. Check log file: " . LOG_FILE . "\n");
    }
    logInfo("Database connection established");
} catch (Exception $e) {
    logError("Database connection exception", $e);
    die("❌ Database connection failed. Check log file: " . LOG_FILE . "\n");
}

// Enable transactions for better performance
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

logInfo("Opening XML file for reading", ['file' => $cleanFile]);

// Check file first
if (!file_exists($cleanFile)) {
    logError("Cleaned XML file does not exist", null, ['file' => $cleanFile]);
    die("❌ Cleaned XML file not found. Check log file: " . LOG_FILE . "\n");
}

$fileSize = filesize($cleanFile);
logInfo("Cleaned XML file size: " . formatBytes($fileSize), ['size_bytes' => $fileSize]);

// Check first few bytes to see structure
$sampleHandle = fopen($cleanFile, 'r');
if ($sampleHandle) {
    $firstBytes = fread($sampleHandle, 500);
    fclose($sampleHandle);
    logInfo("First 500 bytes of cleaned XML", ['preview' => substr($firstBytes, 0, 500)]);

    // Check for TALLYMESSAGE or root element
    if (strpos($firstBytes, 'TALLYMESSAGE') === false && strpos($firstBytes, '<ENVELOPE') === false) {
        logWarning("TALLYMESSAGE or ENVELOPE not found in first 500 bytes - check XML structure");
    }
}

$reader = new XMLReader();
if (!$reader->open($cleanFile)) {
    $error = libxml_get_last_error();
    logError("Cannot open cleaned.xml file", null, [
        'file' => $cleanFile,
        'file_exists' => file_exists($cleanFile),
        'file_size' => $fileSize,
        'libxml_error' => $error ? $error->message : 'Unknown error'
    ]);
    die("❌ Cannot open cleaned.xml. Check log file: " . LOG_FILE . "\n");
}
logInfo("XML file opened successfully");

echo "➡ Step 2: Importing vouchers with batch processing...\n";
echo "📊 Batch size: $BATCH_SIZE records\n";
echo "📊 Progress update: Every $PROGRESS_INTERVAL records\n\n";

$voucherCount = 0;
$tallyMsgCount = 0;
$batchCount = 0;
$batch = [];
$tallyCompanyId = 2;
$startTime = microtime(true);

// Prepared statement for batch insert
$insert = $pdo->prepare("INSERT INTO vouchers (voucher_guid, voucher_number, date, narration, party_ledger_name, voucher_type, created_by, tally_company_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$ledgerStmt = $pdo->prepare("INSERT INTO voucher_ledger_entries (voucher_id, ledger_name, amount, is_deemed_positive, type, tally_company_id) VALUES (?, ?, ?, ?, ?, ?)");
$inventoryStmt = $pdo->prepare("
    INSERT INTO voucher_inventory_entries
    (voucher_id, stock_item_name, actual_qty, billed_qty, rate, amount, tally_company_id)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");
// Start transaction
$pdo->beginTransaction();

try {
    logInfo("Starting XML parsing and import loop");
    $errorCount = 0;
    $skipCount = 0;

    $firstElementFound = false;
    $elementCount = 0;
    $rootElement = null;

    while ($reader->read()) {
        // Log first few elements to understand structure
        if ($reader->nodeType === XMLReader::ELEMENT && !$firstElementFound) {
            $firstElementFound = true;
            $rootElement = $reader->name;
            logInfo("First XML element found", [
                'element_name' => $reader->name,
                'node_type' => $reader->nodeType,
                'depth' => $reader->depth
            ]);
        }

        // Count all elements for debugging
        if ($reader->nodeType === XMLReader::ELEMENT) {
            $elementCount++;
            if ($elementCount <= 10) {
                logInfo("Element found", [
                    'name' => $reader->name,
                    'depth' => $reader->depth,
                    'is_tallymessage' => ($reader->name === 'TALLYMESSAGE')
                ]);
            }
        }

        // Target <TALLYMESSAGE> - can be direct or inside ENVELOPE/BODY
        if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === "TALLYMESSAGE") {
            $tallyMsgCount++;

            try {
                // Read into SimpleXML only for this message (more memory efficient)
                $xmlString = $reader->readOuterXml();

                // Skip empty nodes
                if (trim($xmlString) === '') {
                    $skipCount++;
                    $reader->next();
                    continue;
                }

                // Convert to SimpleXML
                $msg = @simplexml_load_string($xmlString);
                if (!$msg) {
                    $error = libxml_get_last_error();
                    $errorCount++;
                    if ($errorCount <= 10) { // Log first 10 errors
                        logWarning("Failed to parse XML string to SimpleXML", [
                            'tally_message_count' => $tallyMsgCount,
                            'libxml_error' => $error ? $error->message : 'Unknown error',
                            'xml_preview' => substr($xmlString, 0, 200)
                        ]);
                    }
                    $reader->next();
                    continue;
                }

                // Skip if VOUCHER not present
                if (!isset($msg->VOUCHER)) {
                    $skipCount++;
                    $reader->next();
                    continue;
                }

                $voucher = $msg->VOUCHER;
                $voucherCount++;

                // Read fields - handle missing fields gracefully
                $guid = isset($voucher->GUID) ? trim((string) $voucher->GUID) : '';
                $voucherNumber = (string) $voucher->VOUCHERNUMBER;
                $date = DateTime::createFromFormat('Ymd', (string) $voucher->DATE)->format('Y-m-d');
                $narration = (string) $voucher->NARRATION;
                $partyLedgerName = (string) $voucher->PARTYLEDGERNAME;
                $voucherType = (string) $voucher->VOUCHERTYPENAME;
                $createdBy = (string) $voucher->ENTEREDBY;

                $ledgerEntries = $voucher->xpath('ALLLEDGERENTRIES.LIST');
                $inventoryEntries = $voucher->xpath('ALLINVENTORYENTRIES.LIST');
                $inventoryAllocationEntries = $voucher->xpath('INVENTORYALLOCATIONS.LIST');

                $debitAmount = 0;
                $creditAmount = 0;
                $accountLedgerName = '';
                $instrumentNumber = '';
                $bankName = '';
                $billRef = '';

                // Validate required fields
                if (empty($guid)) {
                    logWarning("Voucher missing GUID, skipping", [
                        'voucher_count' => $voucherCount,
                        'type' => $type,
                        'date' => $date,
                        'has_voucher' => isset($voucher)
                    ]);
                    $reader->next();
                    continue;
                }



                // Add to batch
                $batch[] = [$guid, $voucherNumber, $date, $narration, $partyLedgerName, $voucherType, $createdBy, $tallyCompanyId, $ledgerEntries, $inventoryEntries, $inventoryAllocationEntries];

                // When batch is full, insert and commit
                if (count($batch) >= $BATCH_SIZE) {
                    try {
                        // Insert batch
                        foreach ($batch as $rowIndex => $row) {
                            $rowTallyCompanyId = isset($row[7]) ? $row[7] : null;
                            $rowLedgerEntries = isset($row[8]) ? $row[8] : null;
                            $rowInventoryEntries = isset($row[9]) ? $row[9] : null;
                            $rowInventoryAllocationEntries = isset($row[10]) ? $row[10] : null;
                            if (isset($row[8])) {
                                unset($row[8]);
                            }
                            if (isset($row[9])) {
                                unset($row[9]);
                            }
                            if (isset($row[10])) {
                                unset($row[10]);
                            }
                            $insertResult = $insert->execute($row);
                            if ($insertResult === false) {
                                $errorInfo = $insert->errorInfo();
                                logError("Failed to insert voucher record", null, [
                                    'batch_number' => $batchCount + 1,
                                    'row_in_batch' => $rowIndex,
                                    'guid' => $row[0],
                                    'pdo_error' => $errorInfo
                                ]);
                            }
                            $voucherId = $pdo->lastInsertId();
                            foreach ($ledgerEntries as $entry) {
                                $ledgerName = (string) $entry->LEDGERNAME;
                                $amount = (float) $entry->AMOUNT;
                                $isDeemedPositive = (string) $entry->ISDEEMEDPOSITIVE;
                                $type = ($isDeemedPositive === "Yes") ? 'Credit' : 'Debit';
                                $ledgerStmt->execute([$voucherId, $ledgerName, abs($amount), $isDeemedPositive, $type, $tallyCompanyId]);

                            }
                            if ($rowInventoryEntries) {
                                foreach ($rowInventoryEntries as $inv) {

                                    $stockItemName = (string) $inv->STOCKITEMNAME;
                                    $actualQty = (string) $inv->ACTUALQTY;
                                    $billedQty = (string) $inv->BILLEDQTY;
                                    $rate = (string) $inv->RATE;
                                    $amount = (float) $inv->AMOUNT;
                                    if ($stockItemName) {
                                        $inventoryStmt->execute([
                                            $voucherId,
                                            $stockItemName,
                                            $actualQty,
                                            $billedQty,
                                            $rate,
                                            $amount,
                                            $tallyCompanyId
                                        ]);
                                        if (isset($inv->INVENTORYALLOCATIONS_LIST)) {
                                            foreach ($inv->INVENTORYALLOCATIONS_LIST as $alloc) {
                                                insertInventoryAllocation([
                                                    'voucher_id' => $voucherId,
                                                    'inventory_entry_id' => $inventoryEntryId,
                                                    'stock_item_name' => (string) $alloc->stock_item_name ?? null,
                                                    'godown_name' => (string) $alloc->GODOWNNAME ?? null,
                                                    'batch_name' => (string) $alloc->BATCHNAME ?? null,
                                                    'quantity' => (string) $alloc->ACTUALQTY ?? null,
                                                    'amount' => (string) $alloc->AMOUNT ?? null,
                                                    'rate' => (string) $alloc->RATE ?? null,
                                                    'cost_center' => (string) $alloc->COSTCENTRENAME ?? null
                                                ]);
                                            }
                                        }
                                    }
                                }
                            }
                            if ($rowInventoryAllocationEntries) {

                                foreach ($rowInventoryAllocationEntries as $alloc) {

                                    insertInventoryAllocation([
                                        'voucher_id' => $voucherId,
                                        'inventory_entry_id' => null,
                                        'stock_item_name' => (string) $alloc->stock_item_name ?? null,
                                        'godown_name' => (string) $alloc->GODOWNNAME ?? null,
                                        'batch_name' => (string) $alloc->BATCHNAME ?? null,
                                        'quantity' => (string) $alloc->ACTUALQTY ?? null,
                                        'amount' => (string) $alloc->AMOUNT ?? null,
                                        'rate' => (string) $alloc->RATE ?? null,
                                        'cost_center' => (string) $alloc->COSTCENTRENAME ?? null
                                    ]);
                                }
                            }
                        }

                        // Commit transaction
                        $pdo->commit();

                        // Start new transaction
                        $pdo->beginTransaction();

                        // Clear batch
                        $batch = [];
                        $batchCount++;

                        // Progress update
                        $elapsed = microtime(true) - $startTime;
                        $rate = $elapsed > 0 ? $voucherCount / $elapsed : 0;
                        $progressMsg = sprintf(
                            "✓ Processed: %d vouchers | Batches: %d | Rate: %.1f records/sec | Memory: %s",
                            $voucherCount,
                            $batchCount,
                            $rate,
                            formatBytes(memory_get_usage(true))
                        );
                        logInfo($progressMsg, [
                            'vouchers' => $voucherCount,
                            'batches' => $batchCount,
                            'rate' => $rate,
                            'memory' => memory_get_usage(true)
                        ]);
                    } catch (PDOException $e) {
                        logError("Database error during batch insert", $e, [
                            'batch_number' => $batchCount + 1,
                            'batch_size' => count($batch),
                            'vouchers_processed' => $voucherCount
                        ]);
                        throw $e; // Re-throw to trigger rollback
                    }
                }

                // Progress update at intervals
                if ($voucherCount % $PROGRESS_INTERVAL === 0 && count($batch) > 0) {
                    $elapsed = microtime(true) - $startTime;
                    $rate = $elapsed > 0 ? $voucherCount / $elapsed : 0;
                    $progressMsg = sprintf(
                        "📊 Progress: %d vouchers processed | Rate: %.1f records/sec | Memory: %s",
                        $voucherCount,
                        $rate,
                        formatBytes(memory_get_usage(true))
                    );
                    logInfo($progressMsg, [
                        'vouchers' => $voucherCount,
                        'rate' => $rate,
                        'memory' => memory_get_usage(true)
                    ]);
                }

                // Clear SimpleXML from memory
                unset($msg, $voucher, $xmlString);

            } catch (Exception $e) {
                logError("Error processing TALLYMESSAGE", $e, [
                    'tally_message_count' => $tallyMsgCount,
                    'voucher_count' => $voucherCount
                ]);
                // Continue processing other messages
            }

            // Move reader forward
            $reader->next();
        }
    }

    logInfo("XML parsing completed", [
        'tally_messages' => $tallyMsgCount,
        'vouchers_found' => $voucherCount,
        'skipped' => $skipCount,
        'errors' => $errorCount,
        'total_elements_found' => $elementCount
    ]);

    if ($tallyMsgCount == 0) {
        logError("No TALLYMESSAGE elements found in XML", null, [
            'total_elements' => $elementCount,
            'file_size' => filesize($cleanFile),
            'root_element' => $rootElement,
            'suggestion' => 'Check if XML has ENVELOPE root or different structure. XML may need different parsing approach.'
        ]);

        // Try to find what elements exist
        $reader->close();
        $reader2 = new XMLReader();
        if ($reader2->open($cleanFile)) {
            $foundElements = [];
            $maxCheck = 100;
            $checked = 0;
            while ($reader2->read() && $checked < $maxCheck) {
                if ($reader2->nodeType === XMLReader::ELEMENT) {
                    $name = $reader2->name;
                    if (!isset($foundElements[$name])) {
                        $foundElements[$name] = 0;
                    }
                    $foundElements[$name]++;
                    $checked++;
                }
            }
            $reader2->close();
            logInfo("Element frequency in XML (first 100 elements)", ['elements' => $foundElements]);
        }
    }

    // Insert remaining records in batch
    if (!empty($batch)) {
        logInfo("Inserting remaining records in final batch", ['count' => count($batch)]);
        try {
            foreach ($batch as $rowIndex => $row) {
                $rowLedgerEntries = isset($row[8]) ? $row[8] : null;
                $rowTallyCompanyId = isset($row[7]) ? $row[7] : null;
                $rowInventoryEntries = isset($row[9]) ? $row[9] : null;
                $rowInventoryAllocationEntries = isset($row[10]) ? $row[10] : null;
                if (isset($row[8])) {
                    unset($row[8]);
                }
                if (isset($row[9])) {
                    unset($row[9]);
                }
                if (isset($row[10])) {
                    unset($row[10]);
                }
                $insertResult = $insert->execute($row);
                if ($insertResult === false) {
                    $errorInfo = $insert->errorInfo();
                    logError("Failed to insert voucher in final batch", null, [
                        'row_in_batch' => $rowIndex,
                        'guid' => $row[0],
                        'pdo_error' => $errorInfo
                    ]);
                }
                $voucherId = $pdo->lastInsertId();
                foreach ($ledgerEntries as $entry) {
                    $ledgerName = (string) $entry->LEDGERNAME;
                    $amount = (float) $entry->AMOUNT;
                    $isDeemedPositive = (string) $entry->ISDEEMEDPOSITIVE;
                    $type = ($isDeemedPositive === "Yes") ? 'Credit' : 'Debit';
                    $ledgerStmt->execute([$voucherId, $ledgerName, abs($amount), $isDeemedPositive, $type, $tallyCompanyId]);

                }
                if ($rowInventoryEntries) {
                    foreach ($rowInventoryEntries as $inv) {

                        $stockItemName = (string) $inv->STOCKITEMNAME;
                        $actualQty = (string) $inv->ACTUALQTY;
                        $billedQty = (string) $inv->BILLEDQTY;
                        $rate = (string) $inv->RATE;
                        $amount = (float) $inv->AMOUNT;
                        if ($stockItemName) {
                            $inventoryStmt->execute([
                                $voucherId,
                                $stockItemName,
                                $actualQty,
                                $billedQty,
                                $rate,
                                $amount,
                                $tallyCompanyId
                            ]);
                            if (isset($inv->INVENTORYALLOCATIONS_LIST)) {
                                foreach ($inv->INVENTORYALLOCATIONS_LIST as $alloc) {
                                    insertInventoryAllocation([
                                        'voucher_id' => $voucherId,
                                        'inventory_entry_id' => $inventoryEntryId,
                                        'stock_item_name' => (string) $alloc->stock_item_name ?? null,
                                        'godown_name' => (string) $alloc->GODOWNNAME ?? null,
                                        'batch_name' => (string) $alloc->BATCHNAME ?? null,
                                        'quantity' => (string) $alloc->ACTUALQTY ?? null,
                                        'amount' => (string) $alloc->AMOUNT ?? null,
                                        'rate' => (string) $alloc->RATE ?? null,
                                        'cost_center' => (string) $alloc->COSTCENTRENAME ?? null
                                    ]);
                                }
                            }
                        }
                    }
                }
                if ($rowInventoryAllocationEntries) {
                    foreach ($rowInventoryAllocationEntries as $alloc) {
                        insertInventoryAllocation([
                            'voucher_id' => $voucherId,
                            'inventory_entry_id' => null,
                            'stock_item_name' => (string) $alloc->stock_item_name ?? null,
                            'godown_name' => (string) $alloc->GODOWNNAME ?? null,
                            'batch_name' => (string) $alloc->BATCHNAME ?? null,
                            'quantity' => (string) $alloc->ACTUALQTY ?? null,
                            'amount' => (string) $alloc->AMOUNT ?? null,
                            'rate' => (string) $alloc->RATE ?? null,
                            'cost_center' => (string) $alloc->COSTCENTRENAME ?? null
                        ]);
                    }
                }
            }
            $pdo->commit();
            $batchCount++;
            logInfo("Final batch committed successfully");
        } catch (PDOException $e) {
            logError("Database error during final batch insert", $e, [
                'batch_size' => count($batch)
            ]);
            throw $e;
        }
    }

} catch (Exception $e) {
    // Rollback on error
    try {
        $pdo->rollBack();
        logInfo("Transaction rolled back due to error");
    } catch (Exception $rollbackError) {
        logError("Failed to rollback transaction", $rollbackError);
    }

    $reader->close();
    logError("Fatal error during import process", $e, [
        'vouchers_imported' => $voucherCount,
        'batches_completed' => $batchCount,
        'tally_messages_processed' => $tallyMsgCount
    ]);
    die("❌ Fatal Error: " . $e->getMessage() . "\nCheck log file: " . LOG_FILE . "\n");
}

$reader->close();

$totalTime = microtime(true) - $startTime;
$avgRate = $totalTime > 0 ? $voucherCount / $totalTime : 0;

$summary = [
    'tally_messages' => $tallyMsgCount,
    'vouchers_imported' => $voucherCount,
    'batches_processed' => $batchCount,
    'total_time' => $totalTime,
    'average_rate' => $avgRate,
    'peak_memory' => memory_get_peak_usage(true)
];

logInfo("====================================== IMPORT COMPLETED SUCCESSFULLY ======================================", $summary);

echo "\n";
echo "======================================\n";
echo "✔ IMPORT COMPLETED SUCCESSFULLY\n";
echo "======================================\n";
echo "📊 TALLYMESSAGE Found: " . number_format($tallyMsgCount) . "\n";
echo "📊 Vouchers Imported: " . number_format($voucherCount) . "\n";
echo "📊 Batches Processed: $batchCount\n";
echo "⏱️  Total Time: " . formatTime($totalTime) . "\n";
echo "⚡ Average Rate: " . number_format($avgRate, 2) . " records/sec\n";
echo "💾 Peak Memory: " . formatBytes(memory_get_peak_usage(true)) . "\n";
echo "📝 Log File: " . LOG_FILE . "\n";
echo "======================================\n";

logInfo("Import process finished successfully");

function insertInventoryAllocation($data)
{
    global $pdo;

    $stmt = $pdo->prepare("
        INSERT INTO voucher_inventory_allocations
        (voucher_id,stock_item_name, voucher_inventory_entry_id, godown_name, batch_name, quantity, amount, rate, cost_center)
        VALUES
        (:vid, :stock_item_name, :entry_id, :godown, :batch, :qty, :amount, :rate, :cost)
    ");

    $stmt->execute([
        ':vid' => $data['voucher_id'],
        ':stock_item_name' => $data['stock_item_name'],
        ':entry_id' => $data['inventory_entry_id'],
        ':godown' => $data['godown_name'],
        ':batch' => $data['batch_name'],
        ':qty' => $data['quantity'],
        ':amount' => $data['amount'],
        ':rate' => $data['rate'],
        ':cost' => $data['cost_center'],
    ]);

    return $pdo->lastInsertId();
}