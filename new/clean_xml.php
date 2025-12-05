<?php
/**
 * CLEAN XML BEFORE LOADING
 * Removes invalid characters and ensures UTF-8 validity
 * Handles UTF-16 to UTF-8 conversion and control character removal
 */

// Logging configuration
define('LOG_FILE', 'xml_import.log');
define('LOG_MAX_SIZE', 10 * 1024 * 1024); // 10MB max log file size

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

// /**
//  * Log error with stack trace
//  */
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

// /**
//  * Log warning
//  */
// function logWarning($message, $context = []) {
//     logMessage('WARNING', "WARNING: $message", $context);
// }

// /**
//  * Log info
//  */
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
    
    // For files larger than 500MB, use streaming method directly
    $MAX_IN_MEMORY_SIZE = 500 * 1024 * 1024; // 500MB
    if ($fileSize > $MAX_IN_MEMORY_SIZE) {
        logInfo("File is too large for in-memory processing, using streaming method", [
            'file_size' => formatBytes($fileSize),
            'max_in_memory' => formatBytes($MAX_IN_MEMORY_SIZE)
        ]);
        if (cleanXmlFileStreaming($inputFile, $outputFile)) {
            if (validateXmlFile($outputFile)) {
                logInfo("XML cleaned successfully using streaming method", ['output' => $outputFile]);
                return true;
            } else {
                logError("XML validation failed after streaming", null, ['output' => $outputFile]);
                return false;
            }
        } else {
            logError("Streaming cleaning failed", null, ['input' => $inputFile]);
            return false;
        }
    }
    
    try {
        // Step 1: Load file
        logInfo("Loading file into memory");
        $raw = @file_get_contents($inputFile);
        if ($raw === false) {
            $error = error_get_last();
            logError("Cannot read file", null, [
                'file' => $inputFile,
                'error' => $error ? $error['message'] : 'Unknown error',
                'memory_limit' => ini_get('memory_limit')
            ]);
            return false;
        }
        
        $originalSize = strlen($raw);
        logInfo("File loaded: " . formatBytes($originalSize), ['bytes' => $originalSize]);
        
        // Step 2: Detect and convert encoding (UTF-16 to UTF-8)
        $firstBytes = substr($raw, 0, 4);
        $firstBytesHex = bin2hex($firstBytes);
        logInfo("First 4 bytes (hex): " . $firstBytesHex);
        
        if (substr($firstBytesHex, 0, 4) === 'fffe') {
            // UTF-16 Little Endian
            logInfo("Detected UTF-16 LE - converting to UTF-8");
            $raw = substr($raw, 2); // Remove BOM
            $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
            if ($raw === false) {
                logError("UTF-16 LE conversion failed");
                return false;
            }
            logInfo("Conversion complete: " . formatBytes(strlen($raw)), ['after_conversion_bytes' => strlen($raw)]);
        } elseif (substr($firstBytesHex, 0, 4) === 'feff') {
            // UTF-16 Big Endian
            logInfo("Detected UTF-16 BE - converting to UTF-8");
            $raw = substr($raw, 2); // Remove BOM
            $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');
            if ($raw === false) {
                logError("UTF-16 BE conversion failed");
                return false;
            }
            logInfo("Conversion complete: " . formatBytes(strlen($raw)), ['after_conversion_bytes' => strlen($raw)]);
        } elseif (substr($firstBytesHex, 0, 6) === 'efbbbf') {
            // UTF-8 with BOM
            logInfo("Detected UTF-8 with BOM - removing BOM");
            $raw = substr($raw, 3); // Remove UTF-8 BOM
        } else {
            logInfo("Assuming UTF-8 encoding (no BOM detected)");
        }
        
        // Check if conversion resulted in empty content
        $rawLength = strlen($raw);
        if ($rawLength == 0) {
            logError("Content is empty after encoding conversion - trying streaming method", null, ['input' => $inputFile]);
            unset($raw);
            // Try streaming method as fallback
            if (cleanXmlFileStreaming($inputFile, $outputFile)) {
                if (validateXmlFile($outputFile)) {
                    logInfo("XML cleaned successfully using streaming method", ['output' => $outputFile]);
                    return true;
                }
            }
            return false;
        }
        
        logInfo("Content length after encoding conversion: " . formatBytes($rawLength), ['bytes' => $rawLength]);
        
        // Step 3: Remove invalid XML character references BEFORE removing control chars
        // XML 1.0 spec: Only #x9, #xA, #xD, and #x20-#xD7FF, #xE000-#xFFFD are valid
        // Invalid: #x0-#x8, #xB, #xC, #xE-#x1F, #xFFFE, #xFFFF
        logInfo("Removing invalid XML character references");
        
        // More comprehensive pattern to catch all invalid decimal references
        // Matches: &#0; through &#8; and &#11; through &#31; with optional leading zeros
        // Also catches malformed ones without semicolons
        $patterns = [
            // Decimal: &#0; through &#8; (with optional leading zeros and optional semicolon)
            '/&#0*([0-8])(;?)/i',
            // Decimal: &#11; through &#31; (with optional leading zeros and optional semicolon)
            '/&#0*(1[1-9]|2[0-9]|3[01])(;?)/i',
            // Hex: &#x0; through &#x8; (with optional leading zeros and optional semicolon)
            '/&#x0*([0-8])(;?)/i',
            // Hex: &#xB; through &#x1F; (with optional leading zeros and optional semicolon)
            '/&#x0*([BbCcEeFf]|1[0-9A-Fa-f])(;?)/i',
        ];
        
        // Count invalid references before removal for logging
        $totalInvalid = 0;
        foreach ($patterns as $pattern) {
            $count = preg_match_all($pattern, $raw, $matches);
            if ($count > 0) {
                $totalInvalid += $count;
                if ($totalInvalid <= 20) { // Log first 20 matches
                    logInfo("Found invalid character references", [
                        'pattern' => $pattern,
                        'count' => $count,
                        'samples' => array_slice($matches[0], 0, 5)
                    ]);
                }
            }
        }
        
        if ($totalInvalid > 0) {
            logInfo("Total invalid character references found: " . $totalInvalid);
        }
        
        // Apply all patterns to remove invalid references
        $clean = $raw;
        foreach ($patterns as $pattern) {
            $clean = preg_replace($pattern, '', $clean);
        }
        
        // Also remove any remaining malformed references (without proper format)
        // This catches edge cases like &#4 (without semicolon) or &# 4; (with space)
        $clean = preg_replace('/&#\s*0*\s*([0-8]|1[1-9]|2[0-9]|3[01])\s*;?/i', '', $clean);
        $clean = preg_replace('/&#x\s*0*\s*([0-8]|[BbCcEeFf]|1[0-9A-Fa-f])\s*;?/i', '', $clean);
        
        // Remove null bytes and other control characters (keep \n=0x0A, \r=0x0D, \t=0x09)
        logInfo("Removing control characters (keeping \\n, \\r, \\t)");
        $clean = str_replace("\x00", '', $clean); // Remove null bytes
        
        // Count control characters before removal for logging
        $controlCharCount = 0;
        for ($i = 1; $i <= 31; $i++) {
            if ($i != 9 && $i != 10 && $i != 13) { // Skip \t, \n, \r
                $char = chr($i);
                $count = substr_count($clean, $char);
                if ($count > 0) {
                    $controlCharCount += $count;
                    if ($controlCharCount <= 10) { // Log first 10 occurrences
                        logInfo("Found control character", ['byte' => sprintf('0x%02X', $i), 'count' => $count]);
                    }
                }
            }
        }
        if ($controlCharCount > 0) {
            logInfo("Total control characters found: " . $controlCharCount);
        }
        
        // Remove control chars except \t (0x09), \n (0x0A), \r (0x0D)
        // This directly removes bytes 0x01-0x08, 0x0B, 0x0C, 0x0E-0x1F
        $clean = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F]/u', '', $clean);
        
        unset($raw); // Free memory
        
        // Step 4: Remove any remaining invalid XML characters (surrogates, etc.)
        // Remove characters in the invalid ranges
        $clean = preg_replace('/[\x{FFFE}\x{FFFF}]/u', '', $clean);
        
        // Step 4.5: Final check for any remaining invalid character references
        $finalCheckPatterns = [
            '/&#0*([0-8])(;?)/i',
            '/&#0*(1[1-9]|2[0-9]|3[01])(;?)/i',
            '/&#x0*([0-8])(;?)/i',
            '/&#x0*([BbCcEeFf]|1[0-9A-Fa-f])(;?)/i',
        ];
        
        $remainingInvalid = 0;
        foreach ($finalCheckPatterns as $pattern) {
            $count = preg_match_all($pattern, $clean, $remainingMatches);
            $remainingInvalid += $count;
        }
        
        if ($remainingInvalid > 0) {
            logWarning("Found remaining invalid character references after cleaning", [
                'count' => $remainingInvalid,
                'suggestion' => 'Applying additional aggressive cleanup pass'
            ]);
            // Try one more aggressive pass
            foreach ($finalCheckPatterns as $pattern) {
                $clean = preg_replace($pattern, '', $clean);
            }
            $clean = preg_replace('/&#\s*0*\s*([0-8]|1[1-9]|2[0-9]|3[01])\s*;?/i', '', $clean);
            $clean = preg_replace('/&#x\s*0*\s*([0-8]|[BbCcEeFf]|1[0-9A-Fa-f])\s*;?/i', '', $clean);
            
            // Verify removal
            $stillRemaining = 0;
            foreach ($finalCheckPatterns as $pattern) {
                $stillRemaining += preg_match_all($pattern, $clean);
            }
            if ($stillRemaining > 0) {
                logError("Still found invalid character references after aggressive cleanup", [
                    'count' => $stillRemaining,
                    'suggestion' => 'File may need manual inspection or different cleaning approach'
                ]);
            } else {
                logInfo("Successfully removed all invalid character references");
            }
        }
        
        // Step 5: Ensure valid UTF-8
        if (!mb_check_encoding($clean, 'UTF-8')) {
            logWarning("Invalid UTF-8 detected - forcing conversion");
            $clean = mb_convert_encoding($clean, 'UTF-8', 'UTF-8');
        }
        
        // Step 5.5: Ensure file starts with valid XML (<?xml or <)
        $trimmed = ltrim($clean);
        if (strlen($trimmed) > 0 && $trimmed[0] !== '<') {
            logWarning("File doesn't start with '<' - trimming leading whitespace");
            $clean = $trimmed;
        }
        
        // Step 6: Write cleaned file
        $cleanLength = strlen($clean);
        logInfo("Cleaned content: " . formatBytes($cleanLength), ['bytes' => $cleanLength]);
        
        if ($cleanLength == 0) {
            logError("Cleaned content is empty - trying streaming method", null, ['input' => $inputFile]);
            unset($clean);
            // Try streaming method as fallback
            if (cleanXmlFileStreaming($inputFile, $outputFile)) {
                if (validateXmlFile($outputFile)) {
                    logInfo("XML cleaned successfully using streaming method", ['output' => $outputFile]);
                    return true;
                }
            }
            return false;
        }
        
        // Log a sample of the cleaned content
        $sample = substr($clean, 0, 200);
        $sampleHex = bin2hex(substr($clean, 0, 50));
        logInfo("Sample of cleaned content (first 200 chars)", ['sample' => $sample, 'first_50_bytes_hex' => $sampleHex]);
        
        $writeResult = @file_put_contents($outputFile, $clean);
        unset($clean); // Free memory
        
        if ($writeResult === false) {
            $error = error_get_last();
            logWarning("Failed to write cleaned XML - trying streaming method", [
                'output' => $outputFile,
                'error' => $error ? $error['message'] : 'Unknown error'
            ]);
            // Try streaming method as fallback
            if (cleanXmlFileStreaming($inputFile, $outputFile)) {
                if (validateXmlFile($outputFile)) {
                    logInfo("XML cleaned successfully using streaming method", ['output' => $outputFile]);
                    return true;
                }
            }
            return false;
        }
        
        // Step 7: Validate
        logInfo("Validating cleaned XML");
        if (validateXmlFile($outputFile)) {
            logInfo("XML cleaned and validated successfully", ['output' => $outputFile]);
            return true;
        } else {
            logWarning("XML validation failed - trying streaming method", ['output' => $outputFile]);
            // Try streaming method as fallback
            if (cleanXmlFileStreaming($inputFile, $outputFile)) {
                if (validateXmlFile($outputFile)) {
                    logInfo("XML cleaned successfully using streaming method", ['output' => $outputFile]);
                    return true;
                }
            }
            logError("XML validation failed after all methods", null, ['output' => $outputFile]);
            return false;
        }
        
    } catch (Exception $e) {
        logError("Exception during XML cleaning - trying streaming method", $e, ['input' => $inputFile]);
        // Try streaming method as fallback
        if (cleanXmlFileStreaming($inputFile, $outputFile)) {
            if (validateXmlFile($outputFile)) {
                logInfo("XML cleaned successfully using streaming method after exception", ['output' => $outputFile]);
                return true;
            }
        }
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
 * Clean XML file using streaming method (for very large files)
 */
function cleanXmlFileStreaming($inputFile, $outputFile) {
    logInfo("Using streaming cleaning method", ['input' => $inputFile, 'output' => $outputFile]);
    
    $inputHandle = @fopen($inputFile, 'rb');
    if (!$inputHandle) {
        logError("Cannot open input file for streaming", null, ['file' => $inputFile]);
        return false;
    }
    
    $outputHandle = @fopen($outputFile, 'wb');
    if (!$outputHandle) {
        fclose($inputHandle);
        logError("Cannot open output file for streaming", null, ['file' => $outputFile]);
        return false;
    }
    
    // Detect encoding from BOM
    $bom = fread($inputHandle, 4);
    $isUtf16LE = (bin2hex(substr($bom, 0, 2)) === 'fffe');
    $isUtf16BE = (bin2hex(substr($bom, 0, 2)) === 'feff');
    $isUtf8BOM = (bin2hex(substr($bom, 0, 3)) === 'efbbbf');
    
    if ($isUtf16LE) {
        logInfo("Detected UTF-16 LE in streaming mode");
        // Skip BOM
        fseek($inputHandle, 2);
    } elseif ($isUtf16BE) {
        logInfo("Detected UTF-16 BE in streaming mode");
        fseek($inputHandle, 2);
    } elseif ($isUtf8BOM) {
        logInfo("Detected UTF-8 BOM in streaming mode");
        fseek($inputHandle, 3);
    } else {
        logInfo("No BOM detected, assuming UTF-8");
        fseek($inputHandle, 0);
    }
    
    // For UTF-16, we need to read in multiples of 2 bytes to avoid breaking characters
    $chunkSize = ($isUtf16LE || $isUtf16BE) ? (10 * 1024 * 1024) - ((10 * 1024 * 1024) % 2) : (10 * 1024 * 1024);
    $totalBytes = 0;
    $buffer = '';
    $fileSize = filesize($inputFile);
    $lastProgress = 0;
    $firstChunk = true;
    
    while (!feof($inputHandle)) {
        $chunk = fread($inputHandle, $chunkSize);
        if ($chunk === false || strlen($chunk) == 0) break;
        
        $totalBytes += strlen($chunk);
        
        // For UTF-16, ensure we have complete characters (even number of bytes)
        if ($isUtf16LE || $isUtf16BE) {
            if (strlen($chunk) % 2 != 0) {
                // Read one more byte to complete the character
                $extra = fread($inputHandle, 1);
                if ($extra !== false) {
                    $chunk .= $extra;
                    $totalBytes++;
                }
            }
        }
        
        // Convert encoding if needed
        if ($isUtf16LE) {
            $chunk = mb_convert_encoding($chunk, 'UTF-8', 'UTF-16LE');
            if ($chunk === false) {
                logError("UTF-16 LE conversion failed in streaming mode");
                fclose($inputHandle);
                fclose($outputHandle);
                return false;
            }
        } elseif ($isUtf16BE) {
            $chunk = mb_convert_encoding($chunk, 'UTF-8', 'UTF-16BE');
            if ($chunk === false) {
                logError("UTF-16 BE conversion failed in streaming mode");
                fclose($inputHandle);
                fclose($outputHandle);
                return false;
            }
        }
        
        // Remove invalid character references using comprehensive patterns
        $invalidRefPatterns = [
            '/&#0*([0-8])(;?)/i',  // Decimal: &#0; through &#8;
            '/&#0*(1[1-9]|2[0-9]|3[01])(;?)/i',  // Decimal: &#11; through &#31;
            '/&#x0*([0-8])(;?)/i',  // Hex: &#x0; through &#x8;
            '/&#x0*([BbCcEeFf]|1[0-9A-Fa-f])(;?)/i',  // Hex: &#xB; through &#x1F;
        ];
        
        foreach ($invalidRefPatterns as $pattern) {
            $chunk = preg_replace($pattern, '', $chunk);
        }
        
        // Also remove malformed references with spaces or missing semicolons
        $chunk = preg_replace('/&#\s*0*\s*([0-8]|1[1-9]|2[0-9]|3[01])\s*;?/i', '', $chunk);
        $chunk = preg_replace('/&#x\s*0*\s*([0-8]|[BbCcEeFf]|1[0-9A-Fa-f])\s*;?/i', '', $chunk);
        
        // Remove control characters (keep \t, \n, \r)
        $chunk = str_replace("\x00", '', $chunk);
        $chunk = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F]/u', '', $chunk);
        
        // Remove invalid XML characters
        $chunk = preg_replace('/[\x{FFFE}\x{FFFF}]/u', '', $chunk);
        
        // For first chunk, ensure it starts with valid XML
        if ($firstChunk) {
            $chunk = ltrim($chunk);
            $firstChunk = false;
        }
        
        // Write chunk
        $written = fwrite($outputHandle, $chunk);
        if ($written === false) {
            logError("Failed to write chunk in streaming mode");
            fclose($inputHandle);
            fclose($outputHandle);
            return false;
        }
        
        // Progress update (every 50MB)
        if (($totalBytes - $lastProgress) >= (50 * 1024 * 1024)) {
            $percent = ($totalBytes / $fileSize) * 100;
            logInfo("Streaming progress: " . number_format($percent, 1) . "% (" . formatBytes($totalBytes) . " / " . formatBytes($fileSize) . ")");
            $lastProgress = $totalBytes;
        }
    }
    
    fclose($inputHandle);
    fclose($outputHandle);
    
    $outputSize = filesize($outputFile);
    logInfo("Streaming cleaning completed", ['output' => $outputFile, 'size' => formatBytes($outputSize)]);
    
    if ($outputSize == 0) {
        logError("Streaming cleaning produced empty file");
        return false;
    }
    
    return true;
}

/**
 * Format bytes to human readable format
 */
// function formatBytes($bytes, $precision = 2) {
//     $units = ['B', 'KB', 'MB', 'GB', 'TB'];
//     $bytes = max($bytes, 0);
//     if ($bytes == 0) return '0 B';
    
//     // Handle very small numbers to avoid division by zero
//     if ($bytes < 1024) {
//         return $bytes . ' B';
//     }
    
//     $logResult = log($bytes);
//     if ($logResult <= 0) {
//         return $bytes . ' B';
//     }
    
//     $pow = floor($logResult / log(1024));
//     $pow = max(0, min($pow, count($units) - 1)); // Ensure pow is between 0 and max index
    
//     $divisor = pow(1024, $pow);
//     if ($divisor == 0) {
//         return $bytes . ' B'; // Fallback if division would be by zero
//     }
    
//     $bytes /= $divisor;
//     return round($bytes, $precision) . ' ' . $units[$pow];
// }

