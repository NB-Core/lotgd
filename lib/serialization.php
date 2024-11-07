<?php

/// Create a safe function to unserialize, disregarding errors in this

function safe_unserialize($data) {
    if (!is_string($data) || trim($data) === '') {
        // Return the data as-is if it's not a string or is empty
        return $data;
    }

    // Trim whitespace
    $data = trim($data);

    // Check if the data is serialized
    if (is_serialized($data)) {
        try {
            // Suppress warnings with '@' and attempt to unserialize
            $result = @unserialize($data);

            if ($result === false && $data !== 'b:0;') {
                // Return original data if unserialization failed
                return $data;
            }

            return $result;
        } catch (\Exception $e) {
            // Return original data on exception
            return $data;
        } catch (\Error $e) {
            // Return original data on error
            return $data;
        }
    } else {
        // Data is not serialized, return it as is
        return $data;
    }
}

// Helper function to check if data is serialized
function is_serialized($data) {
    if (!is_string($data)) {
        return false;
    }

    $data = trim($data);

    if ($data === 'N;') {
        return true;
    }
    if ($data === 'b:0;') {
        return true;
    }

    $length = strlen($data);
    if ($length < 4) {
        return false;
    }

    if ($data[1] !== ':') {
        return false;
    }

    $lastChar = $data[$length - 1];
    if ($lastChar !== ';' && $lastChar !== '}') {
        return false;
    }

    $token = $data[0];
    switch ($token) {
        case 's':
            if (preg_match('/^s:[0-9]+:.*(;|})$/s', $data)) {
                return true;
            }
            break;
        case 'a':
        case 'O':
            if (preg_match("/^{$token}:[0-9]+:.*[;}]\$/s", $data)) {
                return true;
            }
            break;
        case 'b':
        case 'i':
        case 'd':
            if (preg_match("/^{$token}:[0-9.E-]+;\$/", $data)) {
                return true;
            }
            break;
    }

    return false;
}

