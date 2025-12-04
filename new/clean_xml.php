<?php
/**
 * CLEAN XML BEFORE LOADING
 * Removes invalid characters and ensures UTF-8 validity
 * Automatically uses streaming mode for large files (>500MB)
 */

// Logging configuration
// define('LOG_FILE', 'xml_import.log');
// define('LOG_MAX_SIZE', 10 * 1024 * 1024); // 10MB max log file size

/**
 * Log messages to file with timestamp
 */
// function logMessage($level, $message, $context = []) {
//     $timestamp = date('Y-m-d H:i:s');
//     $logEntry = "[$timestamp] [$level] $message";
    
//     // Add context if provided
//     if (!empty($context)) {
//         $logEntry .= " | Context: " . json_encode($context);
//     }
    
//     $logEntry .= PHP_EOL;
    
//     // Rotate log if too large
//     if (file_exists(LOG_FILE) && filesize(LOG_FILE) > LOG_MAX_SIZE) {
//         $backupFile = LOG_FILE . '.' . date('Y-m-d_His');
//         @rename(LOG_FILE, $backupFile);
//     }
    
//     // Write to log file
//     @file_put_contents(LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);
    
//     // Also output to console
//     echo $message . PHP_EOL;
// }

/**
 * Log error with stack trace
 */
// function logError($message, $exception = null, $context = []) {
//     $errorMsg = "ERROR: $message";
    
//     if ($exception instanceof Exception) {
//         $errorMsg .= " | Exception: " . $exception->getMessage();
//         $errorMsg .= " | File: " . $exception->getFile() . ":" . $exception->getLine();
//         $errorMsg .= " | Stack Trace: " . $exception->getTraceAsString();
//         $context['exception_class'] = get_class($exception);
//     }
    
//     logMessage('ERROR', $errorMsg, $context);
// }

/**
 * Log warning
 */
// function logWarning($message, $context = []) {
//     logMessage('WARNING', "WARNING: $message", $context);
// }

/**
 * Log info
 */
// function logInfo($message, $context = []) {
//     logMessage('INFO', $message, $context);
// }

function cleanXmlFile($inputFile, $outputFile)
{
    logInfo("Starting XML cleaning process", ['input' => $inputFile, 'output' => $outputFile]);

    if (!file_exists($inputFile)) {
        logError("Input file not found", null, ['file' => $inputFile]);
        return false;
    }

    $fileSize = filesize($inputFile);
    logInfo("File size: " . formatBytes($fileSize), ['size_bytes' => $fileSize]);

    // Try standard method first (more reliable for XML)
    // With 4GB memory limit, should handle 2GB files
    logInfo("Attempting standard cleaning method");
    
    try {
        $raw = @file_get_contents($inputFile);
        if ($raw !== false) {
            $originalSize = strlen($raw);
            logInfo("File loaded into memory successfully", [
                'size' => formatBytes($originalSize),
                'bytes' => $originalSize
            ]);
            
            // Check if file is binary or has encoding issues
            $isBinary = false;
            $nullByteCount = substr_count($raw, "\x00");
            $printableChars = 0;
            for ($i = 0; $i < min(1000, strlen($raw)); $i++) {
                $char = $raw[$i];
                if (ctype_print($char) || in_array($char, ["\n", "\r", "\t"])) {
                    $printableChars++;
                }
            }
            $printableRatio = $printableChars / min(1000, strlen($raw));
            
            logInfo("File analysis", [
                'null_bytes_in_first_1kb' => substr_count(substr($raw, 0, 1000), "\x00"),
                'printable_ratio' => round($printableRatio * 100, 2) . '%',
                'is_likely_binary' => ($printableRatio < 0.7 || $nullByteCount > 100)
            ]);
            
            // Log first 200 chars (but handle binary safely)
            $originalPreview = '';
            for ($i = 0; $i < min(200, strlen($raw)); $i++) {
                $char = $raw[$i];
                if (ctype_print($char) || in_array($char, ["\n", "\r", "\t", " "])) {
                    $originalPreview .= $char;
                } else {
                    $originalPreview .= '[' . bin2hex($char) . ']';
                }
            }
            logInfo("First 200 chars of original file (hex for non-printable)", ['preview' => $originalPreview]);
            
            // Find where XML actually starts (skip leading null bytes/binary data)
            $xmlStartPos = 0;
            $foundXmlStart = false;
            // Search first 50KB for XML start
            $searchLimit = min(50000, strlen($raw));
            for ($i = 0; $i < $searchLimit; $i++) {
                if ($raw[$i] === '<') {
                    $xmlStartPos = $i;
                    $foundXmlStart = true;
                    logInfo("Found '<' character at position", [
                        'position' => $i,
                        'hex_context' => bin2hex(substr($raw, max(0, $i-5), 30))
                    ]);
                    break;
                }
            }
            
            // If we found XML start, trim everything before it
            if ($foundXmlStart && $xmlStartPos > 0) {
                logInfo("Trimming leading non-XML content", [
                    'xml_start_position' => $xmlStartPos,
                    'bytes_trimmed' => formatBytes($xmlStartPos),
                    'remaining_size' => formatBytes(strlen($raw) - $xmlStartPos)
                ]);
                $raw = substr($raw, $xmlStartPos);
                $originalSize = strlen($raw);
            } elseif (!$foundXmlStart) {
                logError("CRITICAL: No '<' character found in first 50KB - file may not be XML", null, [
                    'first_100_bytes_hex' => bin2hex(substr($raw, 0, 100)),
                    'searched_bytes' => $searchLimit
                ]);
                // Try to continue anyway - maybe XML is further in
            }
            
            // Check if file has XML declaration (after trimming)
            $trimmed = ltrim($raw, " \t\n\r");
            $hasXmlDecl = (strpos($trimmed, '<?xml') === 0);
            $startsWithBracket = (strpos($trimmed, '<') === 0);
            
            logInfo("XML structure check", [
                'has_xml_declaration' => $hasXmlDecl,
                'starts_with_angle_bracket' => $startsWithBracket,
                'first_20_chars_hex' => bin2hex(substr($raw, 0, 20)),
                'first_20_chars_ascii' => substr($raw, 0, 20)
            ]);
            
            // If still no XML found, try minimal cleaning
            if (!$hasXmlDecl && !$startsWithBracket) {
                logWarning("XML structure not found - attempting minimal cleaning");
                // Just remove null bytes and BOM
                $clean = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
                $clean = str_replace("\x00", '', $clean);
                unset($raw);
            } else {
                // Fix BOM issues FIRST (before other processing)
                // But preserve XML declaration
                $clean = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
                $afterBomSize = strlen($clean);
                logInfo("After BOM removal", [
                    'size' => formatBytes($afterBomSize),
                    'removed' => formatBytes($originalSize - $afterBomSize)
                ]);
                unset($raw); // Free memory immediately

                // Remove control characters BUT preserve XML declaration and structure
                // Only remove null bytes and truly problematic control chars
                // Keep \n, \r, \t as they're valid in XML
                $clean = str_replace("\x00", '', $clean); // Remove null bytes
                $clean = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F]/u', '', $clean); // Remove other control chars
            }
            
            // Ensure XML declaration is preserved
            if (strpos(trim($clean), '<?xml') !== 0 && strpos(trim($clean), '<') === 0) {
                // If XML declaration is missing but file starts with <, it might have been removed
                // Try to preserve the structure
                logWarning("XML declaration may have been affected, attempting to preserve structure");
            }

            // Remove invalid char refs like &#4; (but preserve valid ones)
            // Only if we have content
            if (strlen($clean) > 0) {
                $clean = preg_replace('/&#x?0*4;/i', '', $clean);
                
                // Ensure UTF-8
                if (!mb_check_encoding($clean, 'UTF-8')) {
                    logWarning("Invalid UTF-8 detected, forcing conversion");
                    $clean = mb_convert_encoding($clean, 'UTF-8', 'UTF-8');
                }
            }

            // Check if cleaned content is not empty
            $cleanLength = strlen($clean);
            logInfo("Cleaned content length: " . formatBytes($cleanLength), ['bytes' => $cleanLength]);
            
            // Log first 100 chars to see what we have
            if ($cleanLength > 0) {
                $preview = substr($clean, 0, 100);
                logInfo("First 100 chars of cleaned content", ['preview' => $preview]);
            }
            
            if ($cleanLength == 0) {
                logError("Cleaned content is empty - cleaning may have removed everything", null, [
                    'output' => $outputFile,
                    'suggestion' => 'Original file may not have XML declaration or may be binary'
                ]);
                unset($clean);
                // Fall through to streaming
            } else {
                $writeResult = @file_put_contents($outputFile, $clean);
                if ($writeResult === false) {
                    $error = error_get_last();
                    logError("Failed to write cleaned XML to output file", null, [
                        'output' => $outputFile,
                        'content_length' => $cleanLength,
                        'error' => $error ? $error['message'] : 'Unknown error',
                        'disk_space' => disk_free_space(dirname($outputFile))
                    ]);
                    unset($clean);
                    return false;
                }
                
                // Verify file was written
                $writtenSize = filesize($outputFile);
                logInfo("File written, size: " . formatBytes($writtenSize), [
                    'expected' => $cleanLength,
                    'actual' => $writtenSize
                ]);
                
                if ($writtenSize == 0) {
                    logError("File written but size is 0 - write may have failed", null, [
                        'output' => $outputFile,
                        'expected_size' => $cleanLength
                    ]);
                    unset($clean);
                    // Fall through to streaming
                } else {
                    unset($clean); // Free memory

                    // Validate output
                    logInfo("Validating cleaned XML");
                    if (validateXmlFile($outputFile)) {
                        logInfo("Clean XML saved and validated successfully", ['output' => $outputFile]);
                        return true;
                    } else {
                        logWarning("Validation failed, trying streaming method");
                        // Fall through to streaming
                    }
                }
            }
        } else {
            $error = error_get_last();
            logWarning("Standard method failed (memory limit?)", [
                'error' => $error ? $error['message'] : 'Unknown error',
                'memory_limit' => ini_get('memory_limit'),
                'memory_usage' => memory_get_usage(true)
            ]);
        }
    } catch (Exception $e) {
        logError("Exception in standard cleaning method", $e, ['input' => $inputFile]);
    }

    // Fallback to streaming if standard method failed
    logInfo("Using streaming cleaning method as fallback");
    return cleanXmlFileStreaming($inputFile, $outputFile);
}

/**
 * Streaming mode for large files - processes in chunks
 * Simpler approach: read, clean, write without complex splitting
 */
function cleanXmlFileStreaming($inputFile, $outputFile, $chunkSize = 10485760) // 10MB chunks
{
    logInfo("Starting streaming XML cleaning", [
        'input' => $inputFile,
        'output' => $outputFile,
        'chunk_size' => $chunkSize
    ]);

    if (!file_exists($inputFile)) {
        logError("Input file not found for streaming", null, ['file' => $inputFile]);
        return false;
    }

    $inputHandle = @fopen($inputFile, 'rb');
    if (!$inputHandle) {
        $error = error_get_last();
        logError("Cannot open input file for streaming", null, [
            'file' => $inputFile,
            'error' => $error ? $error['message'] : 'Unknown error'
        ]);
        return false;
    }

    $outputHandle = @fopen($outputFile, 'wb');
    if (!$outputHandle) {
        fclose($inputHandle);
        $error = error_get_last();
        logError("Cannot create output file for streaming", null, [
            'file' => $outputFile,
            'error' => $error ? $error['message'] : 'Unknown error'
        ]);
        return false;
    }

    $totalBytes = filesize($inputFile);
    $processedBytes = 0;
    $isFirstChunk = true;
    $lastProgress = 0;
    $chunkCount = 0;

    logInfo("File size: " . formatBytes($totalBytes), ['total_bytes' => $totalBytes]);

    try {
        while (!feof($inputHandle)) {
            $chunk = @fread($inputHandle, $chunkSize);
            
            if ($chunk === false) {
                $error = error_get_last();
                logError("Error reading chunk from input file", null, [
                    'chunk_number' => $chunkCount,
                    'processed_bytes' => $processedBytes,
                    'error' => $error ? $error['message'] : 'Unknown error'
                ]);
                break;
            }
            
            if ($chunk === '') {
                break;
            }

            $chunkCount++;

            // Remove BOM only from first chunk
            // BUT preserve XML declaration - be very careful with first chunk
            if ($isFirstChunk) {
                // Remove BOM but preserve <?xml declaration
                $chunk = preg_replace('/^\xEF\xBB\xBF/', '', $chunk);
                
                // Ensure XML declaration is preserved in first chunk
                if (strpos(trim($chunk), '<?xml') !== 0) {
                    logWarning("XML declaration not found in first chunk - file may be corrupted");
                }
                
                $isFirstChunk = false;
            }

            // Clean this chunk (simple - just remove control chars)
            // Don't do complex processing that might break XML
            // Be careful not to remove < or > characters
            $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $chunk);
            $clean = preg_replace('/&#x?0*4;/i', '', $clean);
            
            // Write immediately
            $writeResult = @fwrite($outputHandle, $clean);
            if ($writeResult === false) {
                $error = error_get_last();
                logError("Error writing chunk to output file", null, [
                    'chunk_number' => $chunkCount,
                    'error' => $error ? $error['message'] : 'Unknown error'
                ]);
                break;
            }
            
            $processedBytes += strlen($chunk);

            // Progress update every 10MB
            if (($processedBytes - $lastProgress) >= (10 * 1048576)) {
                $percent = ($totalBytes > 0) ? ($processedBytes / $totalBytes * 100) : 0;
                logInfo(sprintf("Progress: %.1f%% (%s / %s)", 
                    $percent,
                    formatBytes($processedBytes),
                    formatBytes($totalBytes)
                ), ['percent' => $percent, 'processed' => $processedBytes]);
                $lastProgress = $processedBytes;
            }
        }

        fclose($inputHandle);
        fclose($outputHandle);

        logInfo("Streaming processing completed", [
            'chunks_processed' => $chunkCount,
            'bytes_processed' => $processedBytes
        ]);

        // Validate the cleaned XML
        logInfo("Validating cleaned XML");
        if (!validateXmlFile($outputFile)) {
            logWarning("Cleaned XML validation failed, trying standard method");
            // Fallback: use standard method
            return cleanXmlFileStandard($inputFile, $outputFile);
        }

        logInfo("Clean XML saved and validated", [
            'output' => $outputFile,
            'processed' => formatBytes($processedBytes)
        ]);
        return true;
        
    } catch (Exception $e) {
        @fclose($inputHandle);
        @fclose($outputHandle);
        logError("Exception during streaming XML cleaning", $e, [
            'chunk_number' => $chunkCount,
            'processed_bytes' => $processedBytes
        ]);
        return false;
    }
}

/**
 * Standard cleaning method with increased memory handling
 */
function cleanXmlFileStandard($inputFile, $outputFile) {
    logInfo("Using standard cleaning method (fallback)", [
        'input' => $inputFile,
        'output' => $outputFile
    ]);
    
    try {
        // Read in larger chunks if possible
        $memoryLimit = ini_get('memory_limit');
        $memoryBytes = parseMemoryLimit($memoryLimit);
        $maxReadSize = min($memoryBytes * 0.5, 2 * 1024 * 1024 * 1024); // Use max 50% of memory or 2GB
        
        logInfo("Memory configuration", [
            'memory_limit' => $memoryLimit,
            'memory_bytes' => $memoryBytes,
            'max_read_size' => $maxReadSize
        ]);
        
        $raw = @file_get_contents($inputFile);
        if ($raw === false) {
            $error = error_get_last();
            logError("Cannot read file (file may be too large for available memory)", null, [
                'file' => $inputFile,
                'file_size' => file_exists($inputFile) ? filesize($inputFile) : 0,
                'memory_limit' => $memoryLimit,
                'error' => $error ? $error['message'] : 'Unknown error'
            ]);
            return false;
        }

    // Fix BOM issues FIRST
    $clean = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    unset($raw); // Free memory

    // Remove control characters (but preserve XML structure)
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $clean);

    // Remove invalid char refs like &#4;
    $clean = preg_replace('/&#x?0*4;/i', '', $clean);
    
    // Verify XML declaration is present
    if (strpos(trim($clean), '<?xml') !== 0 && strpos(trim($clean), '<') === 0) {
        logWarning("XML declaration may be missing in standard fallback method");
    }

        // Ensure UTF-8
        if (!mb_check_encoding($clean, 'UTF-8')) {
            logWarning("Invalid UTF-8 detected, forcing conversion");
            $clean = mb_convert_encoding($clean, 'UTF-8');
        }

        // Check if cleaned content is not empty
        $cleanLength = strlen($clean);
        logInfo("Cleaned content length (fallback): " . formatBytes($cleanLength), ['bytes' => $cleanLength]);
        
        if ($cleanLength == 0) {
            logError("Cleaned content is empty in fallback method - original file may be corrupted or cleaning too aggressive", null, [
                'input' => $inputFile,
                'output' => $outputFile
            ]);
            unset($clean);
            return false;
        }
        
        $writeResult = @file_put_contents($outputFile, $clean);
        if ($writeResult === false) {
            $error = error_get_last();
            logError("Failed to write cleaned XML file", null, [
                'output' => $outputFile,
                'content_length' => $cleanLength,
                'error' => $error ? $error['message'] : 'Unknown error',
                'disk_space' => disk_free_space(dirname($outputFile))
            ]);
            unset($clean);
            return false;
        }
        
        // Verify file was written
        $writtenSize = filesize($outputFile);
        logInfo("File written (fallback), size: " . formatBytes($writtenSize), [
            'expected' => $cleanLength,
            'actual' => $writtenSize
        ]);
        
        if ($writtenSize == 0) {
            logError("File written but size is 0 in fallback method", null, [
                'output' => $outputFile,
                'expected_size' => $cleanLength
            ]);
            unset($clean);
            return false;
        }
        
        unset($clean); // Free memory

        logInfo("Clean XML saved using standard method", ['output' => $outputFile]);
        return true;
        
    } catch (Exception $e) {
        logError("Exception in standard cleaning method (fallback)", $e);
        return false;
    }
}

/**
 * Validate XML file is readable
 */
function validateXmlFile($xmlFile) {
    // Check file exists and has content
    if (!file_exists($xmlFile)) {
        logError("XML validation failed: File doesn't exist", null, ['file' => $xmlFile]);
        return false;
    }
    
    $fileSize = filesize($xmlFile);
    if ($fileSize == 0) {
        logError("XML validation failed: File is empty", null, ['file' => $xmlFile]);
        return false;
    }
    
    // Check first few bytes for XML declaration
    $handle = @fopen($xmlFile, 'r');
    if ($handle) {
        $firstBytes = @fread($handle, 100);
        fclose($handle);
        
        // Should start with <?xml or <
        $trimmed = trim($firstBytes);
        if (strpos($trimmed, '<?xml') !== 0 && strpos($trimmed, '<') !== 0) {
            logError("XML validation failed: File doesn't appear to be valid XML", null, [
                'file' => $xmlFile,
                'first_bytes' => substr($firstBytes, 0, 50)
            ]);
            return false;
        }
    } else {
        logWarning("Cannot open file for validation check", ['file' => $xmlFile]);
    }
    
    // Try XMLReader
    $reader = @new XMLReader();
    if (!$reader) {
        logWarning("XMLReader not available, skipping deep validation");
        return true; // Assume OK if we can't validate
    }
    
    $result = @$reader->open($xmlFile);
    if (!$result) {
        $error = libxml_get_last_error();
        logError("Cannot open XML file with XMLReader", null, [
            'file' => $xmlFile,
            'libxml_error' => $error ? $error->message : 'Unknown error'
        ]);
        return false;
    }
    
    // Try to read first element
    $readResult = @$reader->read();
    $reader->close();
    
    if ($readResult === false) {
        $error = libxml_get_last_error();
        logError("XML parsing failed - file may be corrupted", null, [
            'file' => $xmlFile,
            'libxml_error' => $error ? $error->message : 'Unknown error',
            'line' => $error ? $error->line : null,
            'column' => $error ? $error->column : null
        ]);
        return false;
    }
    
    logInfo("XML validation passed", ['file' => $xmlFile, 'file_size' => $fileSize]);
    return true;
}

/**
 * Parse memory limit string to bytes
 */
function parseMemoryLimit($limit) {
    $limit = trim($limit);
    $last = strtolower($limit[strlen($limit)-1]);
    $value = (int)$limit;
    
    switch($last) {
        case 'g': $value *= 1024;
        case 'm': $value *= 1024;
        case 'k': $value *= 1024;
    }
    
    return $value;
}

/**
 * Clean a chunk of XML data
 * Preserves XML structure while removing invalid characters
 */
function cleanChunk($data) {
    // Remove control characters (but keep \n, \r, \t)
    // Be careful not to break XML structure
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $data);
    
    // Remove invalid char refs like &#4; (but preserve valid ones)
    // Only remove if it's a standalone invalid reference
    $clean = preg_replace('/&#x?0*4;/i', '', $clean);
    
    return $clean;
}

/**
 * Format bytes to human readable format
 */
// function formatBytes($bytes, $precision = 2) {
//     $units = ['B', 'KB', 'MB', 'GB', 'TB'];
//     $bytes = max($bytes, 0);
//     if ($bytes == 0) return '0 B';
//     $pow = floor(log($bytes) / log(1024));
//     $pow = min($pow, count($units) - 1);
//     $bytes /= pow(1024, $pow);
//     return round($bytes, $precision) . ' ' . $units[$pow];
// }

