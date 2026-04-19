# GabayBatas API (v1)

This API prepares the existing PHP web project for mobile integration.

Base URL (local XAMPP example):
- http://localhost/gabaybatas/api/v1

## Endpoints

1. Health Check
- GET /index.php

2. Submit Case (Resident Mobile)
- POST /cases_submit.php
- Body (JSON or form-data):
  - name
  - barangay
  - contact
  - case_type
  - description

3. Track Case
- GET /cases_track.php?case_id={CASE_REFERENCE_OR_ID}

4. Knowledge Base
- GET /knowledge_base.php
- Optional query params:
  - category
  - search

5. Analytics Overview
- GET /analytics_overview.php

## Referral Logic Used in Submission

- minor dispute (and similar barangay-level keywords):
  - assigned_to = barangay
  - priority = Medium
- serious/legal (and similar serious keywords):
  - assigned_to = ESCALATION_QUEUE
  - priority = High
- default:
  - assigned_to = barangay
  - priority = Medium

All submitted cases start with status = Pending (if status column exists).

## Notes

- Responses are JSON.
- CORS is enabled for development (`Access-Control-Allow-Origin: *`).
- Endpoints are schema-flex where possible and adapt to available case column names.
