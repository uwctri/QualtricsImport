<?php

namespace UWMadison\QualtricsImport;

class Deduplicator
{
    /**
     * Strip all non-alphabetic characters and convert to lowercase.
     */
    public static function stripName(?string $name): string
    {
        if (empty($name)) {
            return '';
        }
        return strtolower(preg_replace('/[^a-zA-Z]/', '', $name));
    }

    /**
     * Strip whitespace and capitalize first letter.
     */
    public static function sanitizeName(?string $name): string
    {
        if (empty($name)) {
            return '';
        }
        return ucfirst(strtolower(trim($name)));
    }

    /**
     * Compute normalized similarity score (0.0 to 1.0) between two names
     * using the Oliver/Gestalt pattern matching algorithm.
     */
    public static function nameSimilarity(?string $name1, ?string $name2): float
    {
        $n1 = self::stripName($name1);
        $n2 = self::stripName($name2);

        if ($n1 === '' || $n2 === '') {
            return 0.0;
        }
        if ($n1 === $n2) {
            return 1.0;
        }

        similar_text($n1, $n2, $percent);
        return round($percent / 100.0, 4);
    }

    /**
     * Evaluate a candidate survey response against the existing record pool.
     */
    public static function evaluateCandidate(
        array $candidate,
        array &$poolRecords,
        array $config,
        string $today
    ): array {
        $candPhone = AutoSanitizer::sanitizePhone($candidate['phone1'], true);
        $candFirst = self::sanitizeName($candidate['first_name']);
        $candLast = self::sanitizeName($candidate['last_name']);
        $respId = (string)$candidate['qualtrics_id'];

        // 1. Phone validation check
        if (strlen($candPhone) !== 10) {
            return [
                'status' => 'rejected_invalid_phone',
                'category' => 'Invalid Phone',
                'description' => "Phone number '{$candidate['phone1']}' is not a valid 10-digit NANP number",
                'candidate' => $candidate,
                'is_duplicate' => true,
                'matched_record' => null,
            ];
        }

        $case2LastThreshold = (float)($config['case2_last_sim'] ?? 0.85);
        $case2FirstThreshold = (float)($config['case2_first_sim'] ?? 0.80);
        $case4LastThreshold = (float)($config['case4_last_sim'] ?? 0.95);
        $case4FirstThreshold = (float)($config['case4_first_sim'] ?? 0.90);

        $suspectedDuplicateNote = '';
        $matchedExistingRecord = null;
        $matchedScores = [];

        // 2. Iterate through pool of records (existing + already accepted in this batch)
        foreach ($poolRecords as $existing) {
            $existPhone = $existing['phone1'];
            $existFirst = $existing['first_name'];
            $existLast = $existing['last_name'];

            // Check Case 1: Same Qualtrics ResponseId
            if ($respId !== '' && $existing['qualtrics_id'] !== '' && $respId === $existing['qualtrics_id']) {
                return [
                    'status' => 'skipped_case_1',
                    'category' => 'Already Imported',
                    'description' => "Qualtrics ResponseId '{$respId}' already exists in REDCap record {$existing['record_id']}",
                    'candidate' => $candidate,
                    'is_duplicate' => true,
                    'matched_record' => $existing,
                ];
            }

            // Check Same Phone
            if ($candPhone !== '' && $existPhone !== '' && $candPhone === $existPhone) {
                $firstSim = self::nameSimilarity($candFirst, $existFirst);
                $lastSim = self::nameSimilarity($candLast, $existLast);

                if ($lastSim >= $case2LastThreshold && $firstSim >= $case2FirstThreshold) {
                    return [
                        'status' => 'skipped_case_2',
                        'category' => 'Duplicate Submission',
                        'description' => sprintf(
                            "Duplicate candidate skipped (Phone: %s, Name: %s %s matches record %s '%s %s', scores: %.2f/%.2f)",
                            $candPhone,
                            $candFirst,
                            $candLast,
                            $existing['record_id'],
                            $existFirst,
                            $existLast,
                            $firstSim,
                            $lastSim
                        ),
                        'candidate' => $candidate,
                        'is_duplicate' => true,
                        'matched_record' => $existing,
                        'scores' => ['first' => $firstSim, 'last' => $lastSim],
                    ];
                }

                // Exact phone + different name -> Household shared phone
                $origId = $existing['record_id'];
                $noteEntry = sprintf(
                    "[%s] Duplicate phone submission rejected for candidate '%s %s' (Phone: %s)",
                    $today,
                    $candFirst,
                    $candLast,
                    $candPhone
                );

                return [
                    'status' => 'rejected_case_3',
                    'category' => 'Shared Phone Conflict',
                    'description' => sprintf(
                        "Household shared phone duplicate rejected (Phone: %s, Candidate: '%s %s' shares phone with existing record %s '%s %s')",
                        $candPhone,
                        $candFirst,
                        $candLast,
                        $origId,
                        $existFirst,
                        $existLast
                    ),
                    'candidate' => $candidate,
                    'is_duplicate' => true,
                    'matched_record' => $existing,
                    'original_record_id' => $origId,
                    'note_entry' => $noteEntry,
                    'scores' => ['first' => $firstSim, 'last' => $lastSim],
                ];
            }

            // Different phone: Check very similar name, possible duplicate
            if ($suspectedDuplicateNote === '') {
                $firstSim = self::nameSimilarity($candFirst, $existFirst);
                $lastSim = self::nameSimilarity($candLast, $existLast);

                if ($lastSim >= $case4LastThreshold && $firstSim >= $case4FirstThreshold) {
                    $suspectedDuplicateNote = sprintf(
                        "[%s] Possible duplicate: Similar name to existing record %s ('%s %s') with different phone (scores: first=%.2f, last=%.2f)",
                        $today,
                        $existing['record_id'],
                        $existFirst,
                        $existLast,
                        $firstSim,
                        $lastSim
                    );
                    $matchedExistingRecord = $existing;
                    $matchedScores = ['first' => $firstSim, 'last' => $lastSim];
                }
            }
        }

        // Suspected duplicate (accepted as new record with candidate note)
        if ($suspectedDuplicateNote !== '') {
            return [
                'status' => 'accepted_case_4',
                'category' => 'Possible Duplicate (Different Phone)',
                'description' => $suspectedDuplicateNote,
                'candidate' => $candidate,
                'is_duplicate' => false,
                'candidate_note' => $suspectedDuplicateNote,
                'matched_record' => $matchedExistingRecord,
                'scores' => $matchedScores,
            ];
        }

        // Clean New Record
        return [
            'status' => 'accepted_clean',
            'category' => 'Clean New Record',
            'description' => 'No duplicate conflicts detected',
            'candidate' => $candidate,
            'is_duplicate' => false,
            'matched_record' => null,
        ];
    }
}
