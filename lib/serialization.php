<?php

/// Create a safe function to unserialize, disregarding errors in this

function safe_unserialize($data) {
    if (!is_string($data) || trim($data) === '') {
        // Return the data as-is if it's not a string or is empty
        return $data;
    }

    $data = trim($data);

    // Check if the data is serialized and valid
    if (is_valid_serialized($data)) {
        // Set a custom error handler to suppress warnings/notices
        set_error_handler(function() {
            // Do nothing
            return true;
        });

        try {
            $result = unserialize($data, ['allowed_classes' => false]);
            restore_error_handler();

            return $result !== false || $data === 'b:0;' ? $result : $data;
        } catch (\Throwable $e) {
            restore_error_handler();
            // Return original data on exception/error
            return $data;
        }
    } else {
        // Data is not serialized or is invalid, return it as is
        return $data;
    }
}


// Helper function to check if data is serialized
function is_valid_serialized($data) {
    // Basic checks
    if (!is_string($data) || trim($data) === '') {
        return false;
    }

    $data = trim($data);

    // Early return if data is 'N;' (serialized null) or 'b:0;' (serialized false)
    if ($data === 'N;' || $data === 'b:0;') {
        return true;
    }

    // Check if the data matches the serialized data pattern
    $pattern = '/^([adObis]):/';
    if (!preg_match($pattern, $data, $matches)) {
        return false;
    }

    // Attempt to unserialize with error suppression
    set_error_handler(function() {
        // Suppress errors
        return true;
    });

    $result = @unserialize($data, ['allowed_classes' => false]);
    restore_error_handler();

    // If unserialize didn't return false or is 'b:0;', it's valid
    return $result !== false || $data === 'b:0;';
}


