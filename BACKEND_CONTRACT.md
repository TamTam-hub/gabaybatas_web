# GabayBatas Backend Contract for Mobile API

**Date**: April 16, 2026  
**Stack**: PHP + MySQL  
**Base URL**: `/api/v1`  
**Auth**: Bearer token (JWT or session-based)  
**Response Format**: JSON with `success` (bool), `message` (string), `generated_at` (ISO 8601), plus endpoint-specific payload fields

---

## 1. Authentication

### 1.1 Register (`POST /api/v1/auth_register.php`)

**Status**: ✅ Implemented

**Request Body**:
```json
{
  "name": "Juan Dela Cruz",
  "email": "juan@example.com",
  "password": "Password123!",
  "password_confirmation": "Password123!",
  "age": "28",
  "contact_number": "+63 912 345 6789",
  "barangay": "Barangay 01"
}
```

**Success Response (200)**:
```json
{
  "success": true,
  "message": "Account created successfully. You can now sign in.",
  "data": {
    "user_id": 42,
    "email": "juan@example.com",
    "name": "Juan Dela Cruz"
  }
}
```

**Error Response (422)**:
```json
{
  "success": false,
  "message": "Email already in use."
}
```

**Note**: 
- [ ] Confirm exact field names in database
- [ ] Confirm validation rules (password min length, email format, etc.)
- [ ] Confirm barangay field is from dropdown list

---

### 1.2 Login (`POST /api/v1/auth_login.php`)

**Status**: ✅ Implemented

**Request Body**:
```json
{
  "email": "juan@example.com",
  "password": "Password123!"
}
```

**Success Response (200)**:
```json
{
  "success": true,
  "message": "Login successful.",
  "data": {
    "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "token_type": "Bearer",
    "user": {
      "user_id": 42,
      "email": "juan@example.com",
      "name": "Juan Dela Cruz",
      "role": "resident"
    }
  }
}
```

**Error Response (401)**:
```json
{
  "success": false,
  "message": "Invalid email or password."
}
```

**Note**:
- [ ] Confirm token format (JWT expiry, refresh token strategy, etc.)
- [ ] Confirm user role field values (resident, barangay, admin)
- [ ] Confirm if session cookie is also set or token-only

---

## 2. Profile

### 2.1 Get Profile (`GET /api/v1/profile.php`)

**Status**: ✅ Implemented

**Auth**: Required (Bearer token)

**Request**: No body

**Success Response (200)**:
```json
{
  "success": true,
  "message": "Profile retrieved.",
  "data": {
    "user_id": 42,
    "name": "Juan Dela Cruz",
    "email": "juan@example.com",
    "contact_number": "+63 912 345 6789",
    "age": "28",
    "barangay": "Barangay 01",
    "role": "resident",
    "created_at": "2026-04-10T10:30:00Z"
  }
}
```

**Error Response (401)**:
```json
{
  "success": false,
  "message": "Unauthorized. Token is invalid or expired."
}
```

**Note**:
- [ ] Confirm all profile fields that should be returned
- [ ] Confirm timestamp format (ISO 8601)

---

### 2.2 Update Profile (`PUT /api/v1/profile.php`)

**Status**: ✅ Implemented

**Auth**: Required (Bearer token)

**Request Body** (all fields optional for PATCH, required for PUT):
```json
{
  "name": "Juan Dela Cruz Updated",
  "email": "juan.updated@example.com",
  "contact_number": "+63 912 999 8888",
  "age": "29",
  "barangay": "Barangay 02"
}
```

**Success Response (200)**:
```json
{
  "success": true,
  "message": "Profile updated successfully.",
  "data": {
    "user_id": 42,
    "name": "Juan Dela Cruz Updated",
    "email": "juan.updated@example.com",
    "contact_number": "+63 912 999 8888",
    "age": "29",
    "barangay": "Barangay 02"
  }
}
```

**Error Response (422)**:
```json
{
  "success": false,
  "message": "Email already in use by another account."
}
```

**Note**:
- [ ] Confirm if this is PUT (all fields) or PATCH (partial)
- [ ] Confirm which fields are immutable (user_id, role, created_at)

---

## 3. Cases

### 3.1 Submit Case (`POST /api/v1/cases_submit.php`)

**Status**: ✅ Existing (confirmed working)

**Auth**: Optional (can submit as guest or logged-in resident)

**Request Body**:
```json
{
  "name": "Juan Dela Cruz",
  "barangay": "Barangay 01",
  "contact": "+63 912 345 6789",
  "case_type": "Dispute",
  "description": "My neighbor's fence is blocking my driveway."
}
```

**Success Response (200)**:
```json
{
  "success": true,
  "message": "Case submitted successfully.",
  "data": {
    "case_id": 101,
    "reference_number": "GB-2026-04-001",
    "status": "pending",
    "created_at": "2026-04-16T14:30:00Z"
  }
}
```

**Error Response (422)**:
```json
{
  "success": false,
  "message": "Description is required and must be at least 10 characters."
}
```

**Note**:
- [ ] Confirm exact case_type values (list of allowed)
- [ ] Confirm barangay field format (name or ID)
- [ ] Confirm reference_number format

---

### 3.2 Track Case (`GET /api/v1/cases_track.php`)

**Status**: ✅ Existing (confirmed working)

**Auth**: Optional

**Query Parameters**:
- `case_id` (int, required) OR `reference_number` (string, required)

**Example Request**:
```
GET /api/v1/cases_track.php?case_id=101
GET /api/v1/cases_track.php?reference_number=GB-2026-04-001
```

**Success Response (200)**:
```json
{
  "success": true,
  "message": "Case details retrieved.",
  "data": {
    "case_id": 101,
    "reference_number": "GB-2026-04-001",
    "name": "Juan Dela Cruz",
    "barangay": "Barangay 01",
    "contact": "+63 912 345 6789",
    "case_type": "Dispute",
    "description": "My neighbor's fence is blocking my driveway.",
    "status": "in_progress",
    "updates": [
      {
        "timestamp": "2026-04-16T14:30:00Z",
        "status": "pending",
        "note": "Case received and logged."
      },
      {
        "timestamp": "2026-04-16T15:00:00Z",
        "status": "in_progress",
        "note": "Assigned to barangay officer."
      }
    ],
    "created_at": "2026-04-16T14:30:00Z"
  }
}
```

**Error Response (404)**:
```json
{
  "success": false,
  "message": "Case not found."
}
```

**Note**:
- [ ] Confirm status field values (pending, in_progress, resolved, closed, etc.)
- [ ] Confirm updates array includes all history

---

### 3.3 List Cases (`GET /api/v1/cases_list.php`)

**Status**: ✅ Implemented

**Auth**: Required (Bearer token) - returns only user's cases

**Query Parameters**:
- `status` (optional, string): filter by status
- `limit` (optional, int, default 10): pagination limit
- `offset` (optional, int, default 0): pagination offset

**Example Request**:
```
GET /api/v1/cases_list.php?status=in_progress&limit=10&offset=0
```

**Success Response (200)**:
```json
{
  "success": true,
  "message": "Cases retrieved.",
  "data": {
    "total": 3,
    "cases": [
      {
        "case_id": 101,
        "reference_number": "GB-2026-04-001",
        "case_type": "Dispute",
        "status": "in_progress",
        "created_at": "2026-04-16T14:30:00Z",
        "updated_at": "2026-04-16T15:00:00Z"
      },
      {
        "case_id": 100,
        "reference_number": "GB-2026-04-002",
        "case_type": "Injury",
        "status": "resolved",
        "created_at": "2026-04-15T10:00:00Z",
        "updated_at": "2026-04-16T12:00:00Z"
      }
    ]
  }
}
```

**Error Response (401)**:
```json
{
  "success": false,
  "message": "Unauthorized."
}
```

**Note**:
- [ ] Confirm this endpoint exists or should be created
- [ ] Confirm pagination strategy (limit/offset vs cursor)

---

## 4. Knowledge Base / Info

### 4.1 Search Knowledge Base (`GET /api/v1/knowledge_base.php`)

**Status**: ✅ Existing (confirmed working)

**Auth**: Optional

**Query Parameters**:
- `search` (optional, string): search term
- `category` (optional, string): filter by category

**Example Request**:
```
GET /api/v1/knowledge_base.php?search=property+dispute&category=civil
```

**Success Response (200)**:
```json
{
  "success": true,
  "message": "Articles retrieved.",
  "data": {
    "total": 5,
    "articles": [
      {
        "article_id": 1,
        "title": "Understanding Property Disputes",
        "category": "civil",
        "content": "Property disputes occur when...",
        "icon": "property",
        "created_at": "2026-04-01T10:00:00Z"
      },
      {
        "article_id": 2,
        "title": "Steps to File a Property Complaint",
        "category": "civil",
        "content": "To file a property complaint...",
        "icon": "form",
        "created_at": "2026-04-02T10:00:00Z"
      }
    ]
  }
}
```

**Error Response (200, empty)**:
```json
{
  "success": true,
  "message": "No articles found.",
  "data": {
    "total": 0,
    "articles": []
  }
}
```

**Note**:
- [ ] Confirm all category values
- [ ] Confirm icon field values (or if it's derived from category)
- [ ] Confirm content includes full text or truncated

---

## 5. Notifications

### 5.1 List Notifications (`GET /api/v1/notifications.php`)

**Status**: ✅ Implemented

**Auth**: Required (Bearer token)

**Query Parameters**:
- `limit` (optional, int, default 10)
- `offset` (optional, int, default 0)
- `unread_only` (optional, bool, default false)

**Example Request**:
```
GET /api/v1/notifications.php?unread_only=true&limit=10
```

**Success Response (200)**:
```json
{
  "success": true,
  "message": "Notifications retrieved.",
  "data": {
    "total": 3,
    "unread_count": 2,
    "notifications": [
      {
        "notification_id": 1,
        "title": "Case Status Update",
        "message": "Your case GB-2026-04-001 has been updated to in_progress.",
        "type": "case_update",
        "related_case_id": 101,
        "read": false,
        "created_at": "2026-04-16T15:00:00Z"
      },
      {
        "notification_id": 2,
        "title": "New Message",
        "message": "Barangay Officer has sent you a message.",
        "type": "message",
        "related_chat_id": 5,
        "read": false,
        "created_at": "2026-04-16T14:30:00Z"
      }
    ]
  }
}
```

**Error Response (401)**:
```json
{
  "success": false,
  "message": "Unauthorized."
}
```

**Note**:
- [ ] Confirm notification types (case_update, message, alert, etc.)
- [ ] Confirm which fields are always present vs conditional

---

### 5.2 Mark Notification as Read (`PATCH /api/v1/notifications.php`)

**Status**: ✅ Implemented

**Auth**: Required (Bearer token)

**Request**: No body

**Success Response (200)**:
```json
{
  "success": true,
  "message": "Notification marked as read."
}
```

---

## 6. Chat / Messages

### 6.1 List Chat Threads (`GET /api/v1/chat_threads.php`)

**Status**: ✅ Implemented

**Auth**: Required (Bearer token)

**Query Parameters**:
- `limit` (optional, int, default 10)
- `offset` (optional, int, default 0)

**Example Request**:
```
GET /api/v1/chat_threads.php?limit=10&offset=0
```

**Success Response (200)**:
```json
{
  "success": true,
  "message": "Chat threads retrieved.",
  "data": {
    "total": 2,
    "threads": [
      {
        "thread_id": 5,
        "case_id": 101,
        "case_reference": "GB-2026-04-001",
        "participant_name": "Barangay Officer John",
        "last_message": "Can you provide more details?",
        "last_message_at": "2026-04-16T15:30:00Z",
        "unread_count": 1
      },
      {
        "thread_id": 4,
        "case_id": 100,
        "case_reference": "GB-2026-04-002",
        "participant_name": "Barangay Clerk Maria",
        "last_message": "Your case has been resolved.",
        "last_message_at": "2026-04-16T12:00:00Z",
        "unread_count": 0
      }
    ]
  }
}
```

**Note**:
- [ ] Confirm if threads are case-based or can be standalone

---

### 6.2 Get Chat Thread Messages (`GET /api/v1/chat_thread_messages.php`)

**Status**: ✅ Implemented

**Auth**: Required (Bearer token)

**Query Parameters**:
- `limit` (optional, int, default 20)
- `offset` (optional, int, default 0)

**Example Request**:
```
GET /api/v1/chat_thread_messages.php?thread_id=5&limit=20
```

**Success Response (200)**:
```json
{
  "success": true,
  "message": "Messages retrieved.",
  "data": {
    "thread_id": 5,
    "total": 4,
    "messages": [
      {
        "message_id": 10,
        "sender_id": 1,
        "sender_name": "Barangay Officer John",
        "sender_role": "barangay",
        "content": "Hello, we received your case.",
        "created_at": "2026-04-16T14:35:00Z"
      },
      {
        "message_id": 11,
        "sender_id": 42,
        "sender_name": "Juan Dela Cruz",
        "sender_role": "resident",
        "content": "Thank you. What happens next?",
        "created_at": "2026-04-16T14:40:00Z"
      }
    ]
  }
}
```

---

### 6.3 Send Chat Message (`POST /api/v1/chat_thread_send.php`)

**Status**: ✅ Implemented

**Auth**: Required (Bearer token)

**Request Body**:
```json
{
  "content": "Can you provide more details about the incident?"
}
```

**Success Response (201)**:
```json
{
  "success": true,
  "message": "Message sent.",
  "data": {
    "message_id": 12,
    "thread_id": 5,
    "sender_id": 42,
    "sender_name": "Juan Dela Cruz",
    "content": "Can you provide more details about the incident?",
    "created_at": "2026-04-16T15:45:00Z"
  }
}
```

**Error Response (400)**:
```json
{
  "success": false,
  "message": "Message content cannot be empty."
}
```

---

## 7. Analytics (Optional for Resident App)

### 7.1 Analytics Overview (`GET /api/v1/analytics_overview.php`)

**Status**: ✅ Existing (confirmed working)

**Auth**: Optional (returns public summary or user-specific per role)

**Query Parameters**: None

**Success Response (200)**:
```json
{
  "success": true,
  "message": "Analytics retrieved.",
  "data": {
    "total_cases": 150,
    "pending_cases": 45,
    "in_progress_cases": 32,
    "resolved_cases": 73,
    "avg_resolution_time_days": 12,
    "top_case_types": [
      {
        "case_type": "Dispute",
        "count": 45
      },
      {
        "case_type": "Injury",
        "count": 32
      }
    ]
  }
}
```

---

## 8. Health Check (Utility)

### 8.1 Health (`GET /api/v1/index.php`)

**Status**: ✅ Existing (utility endpoint, likely in index.php)

**Auth**: None

**Success Response (200)**:
```json
{
  "success": true,
  "message": "Backend is operational.",
  "version": "1.0.0"
}
```

---

## Summary Checklist

### Existing Endpoints (✅ Working)
- [x] POST /api/v1/cases_submit.php
- [x] GET /api/v1/cases_track.php
- [x] GET /api/v1/knowledge_base.php
- [x] GET /api/v1/analytics_overview.php

### Implemented Endpoints (✅ Working)
- [x] POST /api/v1/auth_register.php
- [x] POST /api/v1/auth_login.php
- [x] GET /api/v1/profile.php
- [x] PUT /api/v1/profile.php
- [x] GET /api/v1/cases_list.php
- [x] GET /api/v1/notifications.php
- [x] PATCH /api/v1/notifications.php
- [x] GET /api/v1/chat_threads.php
- [x] GET /api/v1/chat_thread_messages.php
- [x] POST /api/v1/chat_thread_send.php

### Verification Needed
- [ ] Exact field names for all responses
- [ ] Exact status values for cases
- [ ] Token format and expiry strategy
- [ ] Error response format consistency
- [ ] Pagination strategy (limit/offset or cursor)
- [ ] CORS headers if frontend is separate domain

---

## Next Steps for Backend Team

1. Review this contract against actual implementation.
2. Fill in all `[ ]` checkboxes with confirmed values.
3. Verify the implemented filenames against the Flutter service layer.
4. Return updated contract to Flutter team.
5. Run Flutter smoke test to validate the mobile integration.
