<?php
/**
 * GabayBatas Barangay Definitions
 * Defines the valid barangay options used throughout the system
 */

define('GABAYBATAS_BARANGAYS', [
    'Barangay Muñoz',
]);

/**
 * Get all barangay options
 * @return array
 */
function gabaybatas_get_barangays() {
    return GABAYBATAS_BARANGAYS;
}

/**
 * Check if a barangay value is valid
 * @param string $barangay
 * @return bool
 */
function gabaybatas_is_valid_barangay($barangay) {
    return in_array($barangay, GABAYBATAS_BARANGAYS, true);
}
