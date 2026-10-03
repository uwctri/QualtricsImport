# Qualtrics Import

A REDCap External Module to import survey screening responses from Qualtrics with automated deduplication and sanitization.

## Features
- **Automatic Sanitization**: Detects and standardizes dates (`YYYY-MM-DD`) and phone numbers (10-digit NANP) without manual mapping rules.
- **Smart Deduplication**: Identifies repeat submissions, shared household phones, and fuzzy name matches.
- **Dry-Run Preview**: Inspect pending responses, match decisions, and similarity scores from the project link before importing.
- **Scheduled Sync**: Automated background sync via REDCap cron.

## Setup
1. Configure your Qualtrics API Token and Data Center in System or Project Settings.
2. In Project Settings, specify your **Qualtrics Survey ID** and target deduplication fields.
3. Use the **Qualtrics Import Preview** link in the project sidebar to test or run imports.
