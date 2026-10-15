# Qualtrics Import

A REDCap External Module to import survey screening responses (or anything else) from Qualtrics with automated deduplication and sanitization.

## Features
- **Automatic Sanitization**: Detects and standardizes dates and phone numbers without configuration
- **Smart Deduplication**: Identifies repeat submissions, shared household phones, and fuzzy name matches
- **Dry-Run Preview**: Review pending responses, match decisions, and similarity scores from the project link before importing. Flag responses as "do not import" to skip them
- **Scheduled Sync**: Optional automated background sync

## Configuration

At the system level enter your API token and Data center if they are shared across projects. Otherwise they can be entered at the project level. At the project level enter your survey ID and review further settings for deduplication configuration and import mappings.

## Installing

You can install the module from the REDCap EM repo or drop it directly in your modules folder (i.e. `/modules/qualtrics_importer_v1.0.0`) manually.