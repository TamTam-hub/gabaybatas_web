<?php
/**
 * GabayBatas Barangay Definitions
 * Defines the valid barangay options used throughout the system
 */

define('GABAYBATAS_BARANGAYS', [
    'Barangay Muñoz',
    'Barangay San Pablo',
    'Barangay Burak',
    'Barangay 1',
    'Barangay 2',
    'Barangay 3',
    'Barangay 4',
    'Barangay 5',
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
