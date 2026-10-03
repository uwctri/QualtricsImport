<?php

namespace UWMadison\QualtricsImport;

use DateTime;

class AutoSanitizer
{
    /**
     * Core fields that should not have generic sanitization applied.
     */
    public const CORE_SYSTEM_FIELDS = [
        'record_id',
        'qualtrics_id',
        'redcap_event_name',
        'first_name',
        'middle_initial',
        'last_name',
        'display_name',
        'study_status',
        'import_date',
        'first_import_date',
    ];

    /**
     * Regex matching NANP phone field names.
     */
    private const PHONE_FIELD_PATTERN = '/(^|_)(phone\d*|cell|mobile|tel)($|_)/i';

    /**
     * Regex matching NANP phone values (e.g. (608) 555-1234, 608-555-1234, 6085551234).
     */
    private const PHONE_VALUE_PATTERN = '/^\s*(?:\+?1[-.\s]*)?(?:\([2-9]\d{2}\)|[2-9]\d{2})[-.\s]*[2-9]\d{2}[-.\s]*\d{4}\s*$/';

    /**
     * Regex matching date field names.
     */
    private const DATE_FIELD_PATTERN = '/(^|_)(date|dob|birthdate)($|_)/i';

    /**
     * Determine if a field name specifically represents a phone number.
     */
    public static function isPhoneFieldName(string $fieldName): bool
    {
        $fn = strtolower(trim($fieldName));
        $excludedSuffixes = ['_type', '_time', '_notes', '_desc', '_yn', '_flag', '_status'];
        foreach ($excludedSuffixes as $suffix) {
            if (str_ends_with($fn, $suffix)) {
                return false;
            }
        }
        if (str_starts_with($fn, 'p_screen_')) {
            return false;
        }
        return (bool)preg_match(self::PHONE_FIELD_PATTERN, $fn);
    }

    /**
     * Determine if a field name specifically represents a date field.
     */
    public static function isDateFieldName(string $fieldName): bool
    {
        $fn = strtolower(trim($fieldName));
        $excludedSuffixes = ['_time', '_notes', '_desc', '_type', '_score'];
        foreach ($excludedSuffixes as $suffix) {
            if (str_ends_with($fn, $suffix)) {
                return false;
            }
        }
        return (bool)preg_match(self::DATE_FIELD_PATTERN, $fn);
    }

    /**
     * Validate whether a string is a valid 10-digit NANP phone number.
     */
    public static function isValidPhone(?string $phone): bool
    {
        if (empty($phone)) {
            return false;
        }
        $digits = preg_replace('/\D/', '', (string)$phone);
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) !== 10) {
            return false;
        }

        // Rejection lists for invalid/dummy numbers
        $invalidNumbers = ['0000000000', '0123456789', '0987654321', '1234567890'];
        if (in_array($digits, $invalidNumbers, true)) {
            return false;
        }

        // Check for all identical digits (e.g., 5555555555)
        if (count(array_unique(str_split($digits))) === 1) {
            return false;
        }

        $areaCode = substr($digits, 0, 3);
        $exchange = substr($digits, 3, 3);

        // NANP rules: First digit cannot be 0 or 1
        if (in_array($areaCode[0], ['0', '1'], true) || in_array($exchange[0], ['0', '1'], true)) {
            return false;
        }

        // Second and third digits cannot both be 1 (e.g., N11 codes)
        if (substr($areaCode, 1, 2) === '11' || substr($exchange, 1, 2) === '11') {
            return false;
        }

        return true;
    }

    /**
     * Sanitize phone number to 10 digits. Returns empty string if invalid when validate is true.
     */
    public static function sanitizePhone($phone, bool $validate = false): string
    {
        if ($phone === null || $phone === '') {
            return '';
        }
        $digits = preg_replace('/\D/', '', (string)$phone);
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }
        if ($validate) {
            if (!self::isValidPhone($digits)) {
                return '';
            }
        }
        return $digits;
    }

    /**
     * Format a 10-digit phone number as (XXX) XXX-XXXX.
     */
    public static function formatPhone($phone): string
    {
        $digits = self::sanitizePhone($phone);
        if (strlen($digits) === 10) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 4));
        }
        return (string)$phone;
    }

    /**
     * Parse and normalize date into REDCap standard YYYY-MM-DD.
     * Returns string YYYY-MM-DD if valid, or null if cannot be parsed.
     */
    public static function sanitizeDate($val): ?string
    {
        if ($val === null) {
            return null;
        }

        if ($val instanceof DateTime) {
            return $val->format('Y-m-d');
        }

        $s = trim((string)$val);
        if ($s === '') {
            return '';
        }

        // 1. Standard ISO format: YYYY-MM-DD
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
            if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                return $s;
            }
            return null;
        }

        // 2. ISO timestamp format: YYYY-MM-DD[T ]HH:MM...
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ]\d{2}:\d{2}(?::\d{2})?.*/', $s, $m)) {
            $datePart = $m[1];
            $parts = explode('-', $datePart);
            if (checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
                return $datePart;
            }
            return null;
        }

        // 3. MM/DD/YYYY or MM-DD-YYYY or M/D/YYYY or M-D-YYYY
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $s, $m)) {
            $month = (int)$m[1];
            $day = (int)$m[2];
            $year = (int)$m[3];
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
            return null;
        }

        // 4. YYYY/MM/DD or YYYY/M/D
        if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $s, $m)) {
            $year = (int)$m[1];
            $month = (int)$m[2];
            $day = (int)$m[3];
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
            return null;
        }

        // 5. MM/DD/YY or MM-DD-YY
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2})$/', $s, $m)) {
            $month = (int)$m[1];
            $day = (int)$m[2];
            $shortYear = (int)$m[3];
            // Standard 2-digit cutoff (>= 70 -> 1900s, < 70 -> 2000s)
            $year = ($shortYear >= 70) ? (1900 + $shortYear) : (2000 + $shortYear);
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
            return null;
        }

        return null;
    }

    /**
     * Automatically detect and sanitize phone numbers and dates across record fields.
     * Optionally cross-references REDCap data dictionary metadata for element_validation_type.
     */
    public static function sanitizeDataValue(string $fieldName, $val, ?array $fieldMetadata = null)
    {
        if ($val === null || $val === '') {
            return $val;
        }

        $strVal = trim((string)$val);
        $valType = $fieldMetadata['element_validation_type'] ?? '';

        // 1. REDCap metadata hints
        if ($valType === 'phone') {
            return self::sanitizePhone($val, true);
        }
        if (str_starts_with($valType, 'date_') || str_starts_with($valType, 'datetime_')) {
            $parsed = self::sanitizeDate($val);
            return $parsed !== null ? $parsed : '';
        }

        // 2. Phone detection via field name or value pattern
        if (self::isPhoneFieldName($fieldName)) {
            return self::sanitizePhone($val, true);
        }
        if (preg_match(self::PHONE_VALUE_PATTERN, $strVal) && self::isValidPhone($strVal)) {
            return self::sanitizePhone($val, true);
        }

        // 3. Date detection via field name or value pattern
        if (self::isDateFieldName($fieldName)) {
            $parsed = self::sanitizeDate($val);
            return $parsed !== null ? $parsed : '';
        }
        if (preg_match('/[\/\-]/', $strVal)) {
            $parsed = self::sanitizeDate($val);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return $val;
    }
}
