# Accessibility Ignore System - Verification Guide

## Step-by-Step Testing Checklist

### Phase 1: Database Verification

#### 1.1 Check Table Structure
```sql
-- Connect to your WordPress database
-- Then run:

SHOW TABLES LIKE '%ignore%';

-- Expected output:
-- ignore_rules
-- ignore_audit_log  
-- violation_ignore_matches
```

#### 1.2 Verify ignore_rules Schema
```sql
DESCRIBE ignore_rules;

-- Expected columns:
-- id (varchar(36))
-- site_id (bigint)
-- status (varchar(20))
-- target_type (varchar(50))
-- rule_ids (text)
-- element_match (text)
-- scope (text)
-- duration (text)
-- reason_category (varchar(50))
-- note (text)
-- system_generated (tinyint)
-- created_by (bigint)
-- expires_at (datetime)
-- match_count (int)
-- created_at (datetime)
-- updated_at (datetime)
```

#### 1.3 Verify ignore_audit_log Schema
```sql
DESCRIBE ignore_audit_log;

-- Expected columns:
-- id (bigint)
-- ignore_rule_id (varchar(36))
-- event_type (varchar(50))
-- actor_user_id (bigint)
-- timestamp (datetime)
-- metadata (text)
```

#### 1.4 Verify violation_ignore_matches Schema
```sql
DESCRIBE violation_ignore_matches;

-- Expected columns:
-- violation_id (bigint)
-- ignore_rule_id (varchar(36))
-- site_id (bigint)
-- matched_at (datetime)
-- match_confidence (varchar(20))
```

---

### Phase 2: File Structure Verification

#### 2.1 Check Core Files Exist
```bash
cd /path/to/wordpress/wp-content/plugins/clearA11y

# Database schema
ls -la src/Database/Ignore_Schema.php

# Models
ls -la src/Models/Ignore_Rule.php

# Services
ls -la src/Services/Fingerprint_Service.php
ls -la src/Services/Ignore_Matcher_Service.php

# Repository
ls -la src/Database/Ignore_Rule_Repository.php

# API
ls -la src/API/Ignore_REST_Controller.php

# Admin
ls -la src/Admin/Ignores_Page.php

# Assets
ls -la assets/js/ignores-page.js
ls -la assets/css/ignores-page.css
```

**Expected Result:** All files should exist

---

### Phase 3: Backend Code Verification

#### 3.1 Check Database Migration
```bash
# Check if DB version constant is updated
grep "CLEARA11Y_DB_VERSION" cleara11y.php

# Expected: define('CLEARA11Y_DB_VERSION', '1.7.0');
```

#### 3.2 Check REST Controller Registration
```bash
# Check if Ignore_REST_Controller is initialized
grep "Ignore_REST_Controller" cleara11y.php

# Expected: new ClearA11y\API\Ignore_REST_Controller();
```

#### 3.3 Check Admin Menu Integration
```bash
# Check if ignores submenu is registered
grep -A5 "cleara11y-ignores" src/Admin/Admin.php

# Expected: add_submenu_page(..., 'cleara11y-ignores', ...);
```

---

### Phase 4: REST API Endpoint Testing

#### 4.1 List All Ignore Rules
```bash
# WordPress authenticated request
curl -X GET 'https://yoursite.com/wp-json/cleara11y/v1/ignores' \
  -H "X-WP-Nonce: YOUR_NONCE"
```

**Expected Response:**
```json
{
  "data": [],
  "total": 0,
  "page": 1,
  "per_page": 20,
  "total_pages": 0,
  "counts": {
    "active": 0,
    "expired": 0,
    "disabled": 0
  }
}
```

#### 4.2 Create an Ignore Rule
```bash
curl -X POST 'https://yoursite.com/wp-json/cleara11y/v1/ignores' \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -d '{
    "target_type": "rule_on_element",
    "rule_ids": ["color-contrast"],
    "element_match": {
      "css_selector": ".btn-primary"
    },
    "scope": {
      "scope_type": "site"
    },
    "duration": {
      "duration_type": "permanent"
    },
    "reason_category": "false_positive",
    "note": "Test ignore rule"
  }'
```

**Expected Response:**
```json
{
  "data": {
    "id": "uuid",
    "target_type": "rule_on_element",
    "status": "active",
    ...
  },
  "message": "Ignore rule created successfully."
}
```

#### 4.3 Get Single Ignore Rule
```bash
curl -X GET 'https://yoursite.com/wp-json/cleara11y/v1/ignores/{RULE_ID}' \
  -H "X-WP-Nonce: YOUR_NONCE"
```

#### 4.4 Update Ignore Rule
```bash
curl -X PUT 'https://yoursite.com/wp-json/cleara11y/v1/ignores/{RULE_ID}' \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -d '{
    "note": "Updated note"
  }'
```

#### 4.5 Disable Ignore Rule
```bash
curl -X POST 'https://yoursite.com/wp-json/cleara11y/v1/ignores/{RULE_ID}/disable' \
  -H "X-WP-Nonce: YOUR_NONCE"
```

#### 4.6 Enable Ignore Rule
```bash
curl -X POST 'https://yoursite.com/wp-json/cleara11y/v1/ignores/{RULE_ID}/enable' \
  -H "X-WP-Nonce: YOUR_NONCE"
```

#### 4.7 Delete Ignore Rule
```bash
curl -X DELETE 'https://yoursite.com/wp-json/cleara11y/v1/ignores/{RULE_ID}' \
  -H "X-WP-Nonce: YOUR_NONCE"
```

#### 4.8 Get Audit Log
```bash
curl -X GET 'https://yoursite.com/wp-json/cleara11y/v1/ignores/{RULE_ID}/audit' \
  -H "X-WP-Nonce: YOUR_NONCE"
```

#### 4.9 Calculate Impact Preview
```bash
curl -X POST 'https://yoursite.com/wp-json/cleara11y/v1/ignores/preview' \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -d '{
    "target_type": "rule",
    "rule_ids": ["color-contrast"],
    "scope": {
      "scope_type": "site"
    }
  }'
```

**Expected Response:**
```json
{
  "data": {
    "issues": 14,
    "pages": 6
  }
}
```

#### 4.10 Quick Ignore
```bash
curl -X POST 'https://yoursite.com/wp-json/cleara11y/v1/ignores/quick' \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -d '{
    "violation_id": 123
  }'
```

---

### Phase 5: Frontend UI Verification

#### 5.1 Access Ignores Management Page
1. Login to WordPress admin
2. Navigate to: ClearA11y → Ignores
3. **Expected:**
   - Page loads without errors
   - Four tabs visible: Active, Expired, Disabled, Audit Log
   - "Create New Ignore Rule" button visible
   - Filters section visible

#### 5.2 Test Wizard Modal
1. Click "Create New Ignore Rule"
2. **Expected:**
   - Modal opens with progress bar
   - Step indicators: 1-2-3-4-5
   - Step 1: Target selection visible

#### 5.3 Test Step 1 - Target Selection
1. Select "Rule on Element"
2. **Expected:**
   - Rule IDs input field appears
   - Element matching section appears
   - CSS Selector radio option available
   - Element Fingerprint radio option available
3. Enter rule ID: `color-contrast`
4. Enter CSS selector: `.btn-primary`
5. Click "Next"
6. **Expected:**
   - Validation passes
   - Proceeds to Step 2

#### 5.4 Test Step 2 - Scope Selection
1. Select "Entire Site"
2. Click "Next"
3. **Expected:**
   - Proceeds to Step 3

#### 5.5 Test Step 3 - Duration Selection
1. Select "Permanent"
2. Click "Next"
3. **Expected:**
   - Proceeds to Step 4

#### 5.6 Test Step 4 - Reason
1. Select "False Positive" from dropdown
2. Enter note: "Test ignore rule"
3. Click "Next"
4. **Expected:**
   - Proceeds to Step 5
   - Impact preview loads

#### 5.7 Test Step 5 - Review
1. **Expected:**
   - Review sections visible (Target, Scope, Duration, Reason)
   - Impact preview shows affected issues/pages
   - "Create Rule" button enabled
2. Click "Create Rule"
3. **Expected:**
   - Button shows "Creating..."
   - Success notification appears
   - Modal closes
   - Rule appears in Active tab

#### 5.8 Test Rule Actions
1. In Active tab, locate the created rule
2. Click "View"
3. **Expected:**
   - Detail modal opens
   - All rule details visible
4. Click "Close"
5. Click "Disable"
6. **Expected:**
   - Confirmation dialog appears
   - Rule moves to Disabled tab

#### 5.9 Test Tab Navigation
1. Click "Disabled" tab
2. **Expected:**
   - Disabled rules appear
3. Click "Enable" on a disabled rule
4. **Expected:**
   - Rule moves back to Active tab

#### 5.10 Test Audit Log Tab
1. Click "Audit Log" tab
2. **Expected:**
   - Audit entries visible
   - Columns: Event, Ignore Rule, Actor, Timestamp, Details
   - All actions (create, disable, enable, delete) logged

---

### Phase 6: Integration Testing

#### 6.1 Verify Ignore Matching During Scans
1. Create an ignore rule via wizard
2. Run a scan on a page
3. **Expected:**
   - Ignored violations are separated from active violations
   - Ignored violations show which rule matched them
   - Ignore count increments

#### 6.2 Verify Quick Ignore Flow
1. On a violation row, click "Quick Ignore"
2. **Expected:**
   - Toast notification appears: "Ignored for now - This issue on this element is ignored on this page until next scan."
   - "Undo" button in toast
3. Click "Undo"
4. **Expected:**
   - Violation becomes active again
   - Toast dismisses

#### 6.3 Verify Audit Trail
1. Perform various ignore actions (create, disable, enable, delete)
2. Check Audit Log tab
3. **Expected:**
   - Every action logged with:
     - Correct event type
     - Actor user ID
     - Timestamp
     - Relevant metadata

---

### Phase 7: Edge Cases & Error Handling

#### 7.1 Test Validation
1. Try to create rule without required fields
2. **Expected:**
   - Validation errors shown
   - Cannot proceed to next step

#### 7.2 Test Invalid UUID
```bash
curl -X GET 'https://yoursite.com/wp-json/cleara11y/v1/ignores/invalid-uuid' \
  -H "X-WP-Nonce: YOUR_NONCE"
```
**Expected:** 404 error

#### 7.3 Test Duplicate Quick Ignore
1. Quick ignore a violation
2. Try to quick ignore the same violation again
3. **Expected:**
   - Existing quick ignore is refreshed
   - No duplicate created

#### 7.4 Test Scope Filtering
1. Check "Hide system-generated quick ignores"
2. **Expected:**
   - System-generated rules hidden from table
   - Manual rules still visible

#### 7.5 Test Pagination
1. Create 25+ ignore rules
2. Navigate through pages
3. **Expected:**
   - Pagination controls work
   - Correct page numbers displayed

---

### Phase 8: Code Quality Checks

#### 8.1 Check PHP Syntax
```bash
find src/ -name "*.php" -exec php -l {} \;
```
**Expected:** No syntax errors

#### 8.2 Check JavaScript Syntax
```bash
node -c assets/js/ignores-page.js
```
**Expected:** No syntax errors

#### 8.3 Check for Security Issues
- ✅ All SQL queries use prepared statements
- ✅ All user input is sanitized/escaped
- ✅ Nonce verification on all REST endpoints
- ✅ Capability checks performed
- ✅ XSS protection in output

#### 8.4 Check WordPress Coding Standards
```bash
# If you have WordPress Coding Standards installed
phpcs --standard=WordPress src/
```

---

### Phase 9: Performance Verification

#### 9.1 Check Database Indexes
```sql
SHOW INDEX FROM ignore_rules;
SHOW INDEX FROM ignore_audit_log;
SHOW INDEX FROM violation_ignore_matches;
```

**Expected indexes:**
- `ignore_rules`: site_id, status, expires_at
- `ignore_audit_log`: ignore_rule_id, event_type, timestamp
- `violation_ignore_matches`: violation_id, ignore_rule_id, site_id

#### 9.2 Test Impact Preview Performance
1. Create a broad ignore rule
2. Run impact preview
3. **Expected:**
   - Response time < 3 seconds
   - Even with large dataset

---

### Phase 10: Documentation Verification

#### 10.1 Check Inline Documentation
- ✅ All PHP files have DocBlocks
- ✅ All methods have @param, @return tags
- ✅ Complex logic has inline comments

#### 10.2 Check User-Facing Strings
- ✅ All strings are translatable
- ✅ No hardcoded text in UI
- ✅ Consistent terminology used

---

## Final Verification Checklist

### Database
- [ ] All three tables created
- [ ] All columns present and correct types
- [ ] Indexes created
- [ ] Foreign keys (if any) working

### Backend
- [ ] Fingerprint_Service generates consistent hashes
- [ ] Ignore_Matcher_Service correctly matches violations
- [ ] Ignore_Rule_Repository creates audit entries
- [ ] REST endpoints respond correctly
- [ ] Quick ignore creates system-generated rules
- [ ] Impact preview calculates correctly

### Frontend
- [ ] Ignores page loads
- [ ] Wizard modal works end-to-end
- [ ] All wizard steps validate correctly
- [ ] Tab switching works
- [ ] Pagination works
- [ ] Rule actions work (view, enable, disable, delete)
- [ ] Audit log displays correctly
- [ ] Filters work

### Integration
- [ ] Ignored violations separated in reports
- [ ] Quick ignore button visible on violations
- [ ] Undo toast works
- [ ] Ignore matching happens post-scan

### Security
- [ ] Nonce verification on all AJAX/REST
- [ ] Capability checks on all operations
- [ ] SQL injection protection
- [ ] XSS protection in output

### Performance
- [ ] Database queries optimized
- [ ] Indexes improve performance
- [ ] Impact preview responds quickly

---

## Common Issues & Solutions

### Issue: Tables not created
**Solution:** Deactivate/reactivate plugin or check DB version

### Issue: REST endpoints return 404
**Solution:** Flush permalinks (Settings → Permalinks → Save)

### Issue: Wizard not opening
**Solution:** Check browser console for JavaScript errors

### Issue: Quick ignore not working
**Solution:** Verify violation exists and user has permissions

---

## Testing Commands Summary

```bash
# Quick syntax check
php -l src/Database/Ignore_Schema.php
node -c assets/js/ignores-page.js

# Check for tables
mysql -u user -p database -e "SHOW TABLES LIKE '%ignore%';"

# REST API test
curl -X GET 'https://site.com/wp-json/cleara11y/v1/ignores' \
  -H "X-WP-Nonce: NONCE"

# Check file permissions
chmod 644 assets/js/ignores-page.js
chmod 644 assets/css/ignores-page.css
```
