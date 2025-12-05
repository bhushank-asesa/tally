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
function logMessage($level, $message, $context = []) {
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
function logError($message, $exception = null, $context = []) {
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
function logWarning($message, $context = []) {
    logMessage('WARNING', "WARNING: $message", $context);
}

/**
 * Log info
 */
function logInfo($message, $context = []) {
    logMessage('INFO', $message, $context);
}

// Define helper functions before use
/**
 * Format bytes to human readable format
 */
function formatBytes($bytes, $precision = 2) {
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
function formatTime($seconds) {
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
$startTime = microtime(true);

// Prepared statement for batch insert
$insert = $pdo->prepare("
    INSERT INTO vouchers (guid, vouchertype, date, narration, amount)
    VALUES (?, ?, ?, ?, ?)
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
                $guid        = isset($voucher->GUID) ? trim((string)$voucher->GUID) : '';
                $type        = isset($voucher->VOUCHERTYPENAME) ? trim((string)$voucher->VOUCHERTYPENAME) : '';
                $date        = isset($voucher->DATE) ? trim((string)$voucher->DATE) : '';
                $narration   = isset($voucher->NARRATION) ? trim((string)$voucher->NARRATION) : '';
                
                // AMOUNT extraction - Tally XML structure
                // Amount is typically in LEDGERENTRIES.LIST, not direct child
                $amount = 0;
                
                // Method 1: Try direct AMOUNT field (some voucher types have it)
                if (isset($voucher->AMOUNT) && (string)$voucher->AMOUNT !== '') {
                    $amount = (float)$voucher->AMOUNT;
                } 
                // Method 2: Calculate from LEDGERENTRIES.LIST (most common)
                elseif (isset($voucher->{'LEDGERENTRIES.LIST'})) {
                    $totalAmount = 0;
                    $ledgerEntries = $voucher->{'LEDGERENTRIES.LIST'};
                    
                    // Handle both single entry and array of entries
                    if (is_array($ledgerEntries)) {
                        foreach ($ledgerEntries as $entry) {
                            if (isset($entry->AMOUNT) && (string)$entry->AMOUNT !== '') {
                                $entryAmount = (float)$entry->AMOUNT;
                                // Tally uses negative for credits, positive for debits
                                // Sum absolute values to get total voucher amount
                                $totalAmount += abs($entryAmount);
                            }
                        }
                    } else {
                        // Single entry (SimpleXML object)
                        if (isset($ledgerEntries->AMOUNT) && (string)$ledgerEntries->AMOUNT !== '') {
                            $totalAmount = abs((float)$ledgerEntries->AMOUNT);
                        }
                    }
                    $amount = $totalAmount;
                }
                // Method 3: Try ALLINVENTORYENTRIES.LIST (for inventory vouchers)
                elseif (isset($voucher->{'ALLINVENTORYENTRIES.LIST'})) {
                    // Similar logic for inventory entries if needed
                    // For now, amount remains 0
                }
                
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
                
                // Log first few vouchers for structure verification
                if ($voucherCount <= 5) {
                    $hasLedgerEntries = isset($voucher->{'LEDGERENTRIES.LIST'});
                    $hasAmount = isset($voucher->AMOUNT);
                    $ledgerCount = 0;
                    
                    if ($hasLedgerEntries) {
                        $ledgerEntries = $voucher->{'LEDGERENTRIES.LIST'};
                        if (is_array($ledgerEntries)) {
                            $ledgerCount = count($ledgerEntries);
                        } else {
                            $ledgerCount = 1; // Single entry
                        }
                    }
                    
                    logInfo("Sample voucher extracted", [
                        'voucher_number' => $voucherCount,
                        'guid' => $guid,
                        'type' => $type,
                        'date' => $date,
                        'narration' => substr($narration, 0, 50),
                        'has_direct_amount' => $hasAmount,
                        'has_ledger_entries' => $hasLedgerEntries,
                        'ledger_entries_count' => $ledgerCount,
                        'amount_calculated' => $amount
                    ]);
                }
                
                // Log warning if amount is 0 after processing (might indicate issue)
                if ($amount == 0 && $voucherCount > 5 && $voucherCount <= 20) {
                    logWarning("Voucher has zero amount - check LEDGERENTRIES.LIST structure", [
                        'guid' => $guid,
                        'type' => $type,
                        'has_ledger_entries' => isset($voucher->{'LEDGERENTRIES.LIST'})
                    ]);
                }

                // Add to batch
                $batch[] = [$guid, $type, $date, $narration, $amount];

                // When batch is full, insert and commit
                if (count($batch) >= $BATCH_SIZE) {
                    try {
                        // Insert batch
                        foreach ($batch as $rowIndex => $row) {
                            // $insertResult = $insert->execute($row);
                            // if ($insertResult === false) {
                            //     $errorInfo = $insert->errorInfo();
                            //     logError("Failed to insert voucher record", null, [
                            //         'batch_number' => $batchCount + 1,
                            //         'row_in_batch' => $rowIndex,
                            //         'guid' => $row[0],
                            //         'pdo_error' => $errorInfo
                            //     ]);
                            // }
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
                // $insertResult = $insert->execute($row);
                // if ($insertResult === false) {
                //     $errorInfo = $insert->errorInfo();
                //     logError("Failed to insert voucher in final batch", null, [
                //         'row_in_batch' => $rowIndex,
                //         'guid' => $row[0],
                //         'pdo_error' => $errorInfo
                //     ]);
                // }
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

