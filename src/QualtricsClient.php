<?php

namespace UWMadison\QualtricsImport;

use Exception;
use ZipArchive;

class QualtricsClient
{
    private string $apiToken;
    private string $dataCenter;
    private string $baseUrl;

    public function __construct(string $apiToken, string $dataCenter)
    {
        $this->apiToken = trim($apiToken);
        $this->dataCenter = trim($dataCenter);
        $this->baseUrl = sprintf('https://%s.qualtrics.com/API/v3/', $this->dataCenter);
    }

    /**
     * Calculate effective ISO 8601 startDate string from sinceType and lastSyncTime.
     */
    public static function calculateStartDate(string $sinceType = 'auto', ?string $lastSyncTime = null, ?int $currentTime = null): ?string
    {
        $now = $currentTime ?? time();
        $lastSyncTimestamp = (!empty($lastSyncTime) && strtotime($lastSyncTime) !== false) ? strtotime($lastSyncTime) : null;

        return match ($sinceType) {
            'past_24h' => gmdate('Y-m-d\TH:i:s\Z', $now - 86400),
            'last_sync' => $lastSyncTimestamp ? gmdate('Y-m-d\TH:i:s\Z', $lastSyncTimestamp) : gmdate('Y-m-d\TH:i:s\Z', $now - 86400),
            'past_7d' => gmdate('Y-m-d\TH:i:s\Z', $now - (7 * 86400)),
            'past_30d' => gmdate('Y-m-d\TH:i:s\Z', $now - (30 * 86400)),
            'all' => null,
            default => (function () use ($now, $lastSyncTimestamp) {
                // Default: past day (24h) or last import, whichever is less (smaller window / more recent timestamp)
                $pastDayTimestamp = $now - 86400;
                $effectiveTimestamp = ($lastSyncTimestamp !== null)
                    ? max($pastDayTimestamp, $lastSyncTimestamp)
                    : $pastDayTimestamp;
                return gmdate('Y-m-d\TH:i:s\Z', $effectiveTimestamp);
            })(),
        };
    }

    /**
     * Test connection to Qualtrics API.
     * Returns array ['success' => bool, 'message' => string].
     */
    public function testConnection(): array
    {
        try {
            $response = $this->request('GET', 'surveys?limit=1');
            if (isset($response['result'])) {
                return ['success' => true, 'message' => 'Connected successfully to Qualtrics API.'];
            }
            return ['success' => false, 'message' => 'Qualtrics returned unexpected response structure.'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Connection failed: ' . $e->getMessage()];
        }
    }

    /**
     * Retrieve list of accessible surveys from Qualtrics.
     * Returns array of ['id' => 'SV_...', 'name' => '...'].
     */
    public function getSurveys(): array
    {
        $response = $this->request('GET', 'surveys');
        $elements = $response['result']['elements'] ?? [];
        $surveys = [];
        foreach ($elements as $el) {
            $surveys[] = [
                'id' => $el['id'] ?? '',
                'name' => $el['name'] ?? '',
                'isActive' => $el['isActive'] ?? false,
            ];
        }
        return $surveys;
    }

    /**
     * Retrieve question DataExportTag mapping for a survey definition.
     * Maps QID (e.g., 'QID29') => DataExportTag (e.g., 'first_name').
     */
    public function getSurveyQuestionTags(string $surveyId): array
    {
        $response = $this->request('GET', "survey-definitions/{$surveyId}");
        $questions = $response['result']['Questions'] ?? [];
        $tagMap = [];

        foreach ($questions as $qid => $q) {
            $tag = trim((string)($q['DataExportTag'] ?? ''));
            if ($tag === '') {
                continue;
            }
            $qidClean = strtoupper(trim((string)$qid));
            $tagMap[$qidClean] = $tag;

            // Also map any SubQuestions if present
            if (!empty($q['SubQuestions']) && is_array($q['SubQuestions'])) {
                foreach ($q['SubQuestions'] as $subId => $subQ) {
                    $subTag = trim((string)($subQ['DataExportTag'] ?? ''));
                    if ($subTag !== '') {
                        $tagMap[strtoupper((string)$subId)] = $subTag;
                    }
                }
            }
        }

        return $tagMap;
    }

    /**
     * Perform asynchronous export of survey responses from Qualtrics.
     *
     * @param string $surveyId Qualtrics Survey ID (e.g., SV_...)
     * @param string|null $startDate Optional ISO 8601 date string for delta sync
     * @return array Array of survey response rows
     */
    public function exportResponses(string $surveyId, ?string $startDate = null): array
    {
        $payload = [
            'format' => 'json',
            'compress' => true,
        ];
        if (!empty($startDate)) {
            $payload['startDate'] = $startDate;
        }

        // Fetch survey question DataExportTags for automatic column resolution
        $tagMap = [];
        try {
            $tagMap = $this->getSurveyQuestionTags($surveyId);
        } catch (Exception) {
            // Gracefully continue without tag translation if survey-definitions is not accessible
            $tagMap = [];
        }

        // Step 1: Initiate export job
        $initRes = $this->request('POST', "surveys/{$surveyId}/export-responses", $payload);
        $progressId = $initRes['result']['progressId'] ?? null;
        if (empty($progressId)) {
            throw new Exception("Qualtrics export initiation failed: progressId not returned.");
        }

        // Step 2: Poll progress until complete (with timeout safety)
        $maxAttempts = 40;
        $attempt = 0;
        $isComplete = false;
        $fileId = null;

        while ($attempt < $maxAttempts) {
            usleep(1000000); // 1.0 second delay between checks
            $attempt++;

            $progressRes = $this->request('GET', "surveys/{$surveyId}/export-responses/{$progressId}");
            $status = $progressRes['result']['status'] ?? '';
            $percent = $progressRes['result']['percentComplete'] ?? 0;

            if ($status === 'complete' || $percent >= 100) {
                $isComplete = true;
                $fileId = $progressRes['result']['fileId'] ?? null;
                break;
            }

            if ($status === 'failed') {
                throw new Exception("Qualtrics export job failed on remote server.");
            }
        }

        if (!$isComplete) {
            throw new Exception("Qualtrics export timed out after {$maxAttempts} seconds.");
        }

        // Step 3: Download exported file
        $downloadEndpoint = $fileId
            ? "surveys/{$surveyId}/export-responses/{$fileId}/file"
            : "surveys/{$surveyId}/export-responses/{$progressId}/file";

        $zipData = $this->downloadFile($downloadEndpoint);
        if (empty($zipData)) {
            throw new Exception("Downloaded Qualtrics export file was empty.");
        }

        // Step 4: Decompress ZIP and parse JSON
        return $this->extractResponsesFromZip($zipData, $tagMap);
    }

    /**
     * Unpack ZIP archive in memory and parse JSON responses.
     */
    private function extractResponsesFromZip(string $zipContent, array $tagMap = []): array
    {
        $tempZip = tempnam(sys_get_temp_dir(), 'qtr_');
        file_put_contents($tempZip, $zipContent);

        $zip = new ZipArchive();
        if ($zip->open($tempZip) !== true) {
            @unlink($tempZip);
            throw new Exception("Failed to open downloaded Qualtrics export ZIP archive.");
        }

        $jsonStr = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (str_ends_with(strtolower($filename), '.json')) {
                $jsonStr = $zip->getFromIndex($i);
                break;
            }
        }
        $zip->close();
        @unlink($tempZip);

        if (empty($jsonStr)) {
            throw new Exception("No JSON file found in Qualtrics export archive.");
        }

        $decoded = json_decode($jsonStr, true);
        if (!is_array($decoded)) {
            throw new Exception("Failed to decode JSON response from Qualtrics export.");
        }

        $rawResponses = $decoded['responses'] ?? $decoded;
        return self::normalizeResponses($rawResponses, $tagMap);
    }

    /**
     * Normalize raw Qualtrics response items and optionally translate QID keys to DataExportTags.
     */
    public static function normalizeResponses(array $rawResponses, array $tagMap = []): array
    {
        $normalizedRows = [];

        // Build uppercase lookup for tag map
        $tagLookup = [];
        foreach ($tagMap as $k => $v) {
            $tagLookup[strtoupper(trim((string)$k))] = trim((string)$v);
        }

        foreach ($rawResponses as $idx => $item) {
            if (!is_array($item)) {
                continue;
            }

            // Qualtrics v3 JSON format often nests survey answer fields under 'values'
            $row = [];
            if (isset($item['values']) && is_array($item['values'])) {
                $row = $item['values'];
                if (isset($item['responseId'])) {
                    $row['ResponseId'] = $item['responseId'];
                }
            } else {
                $row = $item;
            }

            // Ensure ResponseId is set
            if (!isset($row['ResponseId']) && isset($item['responseId'])) {
                $row['ResponseId'] = $item['responseId'];
            }

            // Normalize common metadata keys for consistent PascalCase access
            if (isset($row['startDate']) && !isset($row['StartDate'])) {
                $row['StartDate'] = $row['startDate'];
            }
            if (isset($row['endDate']) && !isset($row['EndDate'])) {
                $row['EndDate'] = $row['endDate'];
            }
            if (isset($row['recordedDate']) && !isset($row['RecordedDate'])) {
                $row['RecordedDate'] = $row['recordedDate'];
            }
            if (isset($row['finished']) && !isset($row['Finished'])) {
                $row['Finished'] = $row['finished'];
            }
            if (isset($row['progress']) && !isset($row['Progress'])) {
                $row['Progress'] = $row['progress'];
            }

            // Skip header or non-response rows
            $respId = (string)($row['ResponseId'] ?? '');
            if (empty($respId) || !str_starts_with($respId, 'R_')) {
                continue;
            }

            // Translate QID keys using DataExportTag mapping if available
            if (!empty($tagLookup)) {
                foreach ($row as $k => $v) {
                    $kUpper = strtoupper(trim((string)$k));
                    $baseQid = null;
                    $sub = '';

                    if (isset($tagLookup[$kUpper])) {
                        $tag = $tagLookup[$kUpper];
                        if (!isset($row[$tag]) || trim((string)$row[$tag]) === '') {
                            $row[$tag] = $v;
                        }
                        continue;
                    }

                    // Check for QID_TEXT or QID_<suffix> (e.g. QID29_TEXT or QID10_1)
                    if (preg_match('/^(Q(?:ID)?\d+)_(.+)$/i', $k, $m)) {
                        $rawBase = strtoupper($m[1]);
                        $sub = $m[2];

                        // Normalize Q29 <-> QID29
                        $candQids = [$rawBase];
                        if (str_starts_with($rawBase, 'QID')) {
                            $candQids[] = 'Q' . substr($rawBase, 3);
                        } else {
                            $candQids[] = 'QID' . substr($rawBase, 1);
                        }

                        foreach ($candQids as $cand) {
                            if (isset($tagLookup[$cand])) {
                                $baseQid = $cand;
                                break;
                            }
                        }

                        if ($baseQid !== null) {
                            $tag = $tagLookup[$baseQid];
                            if (strtoupper($sub) === 'TEXT') {
                                $row[$tag . '_TEXT'] = $v;
                                // Respondent text entries take precedence over choice codes if non-empty
                                if (trim((string)$v) !== '') {
                                    $row[$tag] = $v;
                                }
                            } else {
                                $row["{$tag}_{$sub}"] = $v;
                                if (is_numeric($sub)) {
                                    $row["{$tag}___{$sub}"] = $v;
                                }
                            }
                        }
                    }
                }
            }

            $normalizedRows[] = $row;
        }

        return $normalizedRows;
    }

    /**
     * Execute standard Qualtrics API v3 HTTP request.
     */
    private function request(string $method, string $endpoint, ?array $body = null): array
    {
        $url = $this->baseUrl . ltrim($endpoint, '/');
        $ch = curl_init();

        $headers = [
            'X-API-TOKEN: ' . $this->apiToken,
            'Accept: application/json',
        ];

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                $jsonBody = json_encode($body);
                $headers[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
            }
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $rawResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false) {
            throw new Exception("Qualtrics API request error: " . $curlError);
        }

        $decoded = json_decode($rawResponse, true);
        if ($httpCode >= 400) {
            $msg = $decoded['meta']['error']['errorMessage'] ?? "HTTP Error {$httpCode}";
            throw new Exception("Qualtrics API Error: {$msg}");
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Download binary file from Qualtrics API.
     */
    private function downloadFile(string $endpoint): string
    {
        $url = $this->baseUrl . ltrim($endpoint, '/');
        $ch = curl_init();

        $headers = [
            'X-API-TOKEN: ' . $this->apiToken,
            'Accept: application/octet-stream',
        ];

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $binaryData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($binaryData === false || $httpCode >= 400) {
            throw new Exception("Failed to download Qualtrics export file (HTTP {$httpCode}): {$curlError}");
        }

        return $binaryData;
    }
}
