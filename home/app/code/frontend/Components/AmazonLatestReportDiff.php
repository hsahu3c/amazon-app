<?php

// --- Configuration ---
$file1_path = BP . DS . "var/file/$userId/"; // Path to your first JSON file
$file2_path = BP . DS . "var/file/$userId/"; // Path to your second JSON file
$output_file_path = 'diff_objects_single_array.json'; // Path for the output JSON file

// --- Functions ---

/**
 * Reads a JSON file, decodes it, and extracts objects,
 * mapping them by their browseNodeId for quick lookup.
 *
 * @param string $filePath The path to the JSON file.
 * @return array An associative array where keys are browseNodeIds and values are the full objects.
 * @throws Exception If the file cannot be read or JSON cannot be decoded.
 */
function parseJsonFileAndMapById(string $filePath): array
{
    if (!file_exists($filePath)) {
        throw new Exception("File not found: " . $filePath);
    }

    $jsonContent = file_get_contents($filePath);
    if ($jsonContent === false) {
        throw new Exception("Could not read file: " . $filePath);
    }

    $data = json_decode($jsonContent, true); // Decode as associative array
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("JSON decode error in " . $filePath . ": " . json_last_error_msg());
    }

    if (!is_array($data)) {
        throw new Exception("JSON root is not an array in " . $filePath . ". Expected an array of objects.");
    }

    $mappedObjects = [];
    foreach ($data as $object) {
        if (isset($object['browseNodeId'])) {
            $mappedObjects[$object['browseNodeId']] = $object;
        } else {
            // Optional: Log or handle objects without browseNodeId if they shouldn't exist
            // error_log("Warning: Object without 'browseNodeId' found in " . $filePath);
        }
    }
    return $mappedObjects;
}

// --- Main Logic ---
try {
    echo "Processing " . $file1_path . "...\n";
    $objects_file1 = parseJsonFileAndMapById($file1_path);
    echo "Found " . count($objects_file1) . " objects with 'browseNodeId' in " . $file1_path . "\n";

    echo "Processing " . $file2_path . "...\n";
    $objects_file2 = parseJsonFileAndMapById($file2_path);
    echo "Found " . count($objects_file2) . " objects with 'browseNodeId' in " . $file2_path . "\n";

    // Get browseNodeIds from each file
    $browseNodeIds_file1 = array_keys($objects_file1);
    $browseNodeIds_file2 = array_keys($objects_file2);

    // Find browseNodeIds unique to file 1
    $unique_to_file1_ids = array_diff($browseNodeIds_file1, $browseNodeIds_file2);
    echo "Found " . count($unique_to_file1_ids) . " 'browseNodeId's unique to " . $file1_path . "\n";

    // Find browseNodeIds unique to file 2
    $unique_to_file2_ids = array_diff($browseNodeIds_file2, $browseNodeIds_file1);
    echo "Found " . count($unique_to_file2_ids) . " 'browseNodeId's unique to " . $file2_path . "\n";

    $all_unique_objects = [];

    // Collect full objects for browseNodeIds unique to file 1
    foreach ($unique_to_file1_ids as $id) {
        $all_unique_objects[] = $objects_file1[$id];
    }

    // Collect full objects for browseNodeIds unique to file 2
    foreach ($unique_to_file2_ids as $id) {
        $all_unique_objects[] = $objects_file2[$id];
    }

    // Save the results to a new JSON file as a single array
    $outputJsonContent = json_encode($all_unique_objects, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($outputJsonContent === false) {
        throw new Exception("Error encoding output JSON: " . json_last_error_msg());
    }

    if (file_put_contents($output_file_path, $outputJsonContent) === false) {
        throw new Exception("Could not write to output file: " . $output_file_path);
    }

    echo "\nComparison complete! All unique objects saved to: " . $output_file_path . "\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1); // Exit with an error code
}

?>