# Quick Ignore Testing Guide

## Prerequisites
1. ClearA11y plugin must be activated
2. Database tables must exist (check with verification script)
3. You need at least one scanned violation to test with

---

## Test 1: Verify REST Endpoint is Registered

### Step 1: Check if endpoint exists
```bash
# In WordPress admin, go to:
# https://yoursite.com/wp-json/cleara11y/v1/ignores
# 
# Or test via curl (replace NONCE with actual WordPress nonce):
curl -X OPTIONS 'https://yoursite.com/wp-json/cleara11y/v1/ignores/quick' \
  -H "X-WP-Nonce: YOUR_NONCE" -v
```

**Expected:** Returns 200 or indicates POST method is allowed

---

## Test 2: Quick Ignore a Violation (Manual WordPress Test)

### Step 1: Find a violation to ignore
1. Go to WordPress Admin
2. Navigate to: ClearA11y → Scans
3. Click on any completed scan
4. Find a violation in the list

### Step 2: Get the violation ID
- Look for the violation ID (visible in the table or URL)
- Note the rule_id, selector, and page URL

### Step 3: Call Quick Ignore API
```javascript
// Run in browser console while on WordPress admin page:
fetch('/wp-json/cleara11y/v1/ignores/quick', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': cleara11yIgnores.nonce // or get from wpApiSettings.nonce
    },
    body: JSON.stringify({
        violation_id: 123 // Replace with actual violation ID
    })
})
.then(response => response.json())
.then(data => {
    console.log('Quick Ignore Result:', data);
});
```

**Expected Response:**
```json
{
    "id": "uuid-v4-here",
    "message": "Issue ignored until next scan.",
    "rule": {
        "id": "uuid-v4-here",
        "target_type": "rule_on_element",
        "rule_ids": ["color-contrast"],
        "element_match": {
            "css_selector": ".some-selector",
            "selector_fingerprint": "hash...",
            "element_fingerprint": "hash..."
        },
        "scope": {
            "scope_type": "page",
            "url": "https://example.com/page"
        },
        "duration": {
            "duration_type": "until_next_scan"
        },
        "system_generated": true,
        "status": "active",
        "expires_at": "2026-05-14 12:00:00"
    }
}
```

---

## Test 3: Verify Database Records

### Check ignore_rules table:
```sql
SELECT * FROM ignore_rules 
WHERE system_generated = 1 
ORDER BY created_at DESC 
LIMIT 1;
```

**Expected:**
- One row with system_generated = 1
- target_type = 'rule_on_element'
- scope_type = 'page'
- duration_type = 'until_next_scan'
- expires_at = 24 hours from now

### Check ignore_audit_log table:
```sql
SELECT * FROM ignore_audit_log 
WHERE event_type = 'ignore_created' 
ORDER BY timestamp DESC 
LIMIT 1;
```

**Expected:**
- Event logged with actor_user_id

### Check violation_ignore_matches table:
```sql
SELECT * FROM violation_ignore_matches 
WHERE violation_id = 123  -- Your violation ID
ORDER BY matched_at DESC;
```

**Expected:**
- Match record created with confidence = 'high'

---

## Test 4: Duplicate Quick Ignore (Refresh Behavior)

### Same violation, quick ignore again:
```javascript
fetch('/wp-json/cleara11y/v1/ignores/quick', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': cleara11yIgnores.nonce
    },
    body: JSON.stringify({
        violation_id: 123 // Same violation ID
    })
})
.then(response => response.json())
.then(data => {
    console.log('Quick Ignore Refresh Result:', data);
});
```

**Expected Response:**
```json
{
    "message": "Quick ignore refreshed.",
    "rule": {
        "id": "same-uuid-as-before",
        "expires_at": "2026-05-14 12:30:00" // Refreshed timestamp
    }
}
```

**No new record created** - existing rule updated

---

## Test 5: Undo Quick Ignore

### Undo the quick ignore:
```javascript
// Use the rule_id from Test 2
fetch('/wp-json/cleara11y/v1/ignores/{rule_id}/undo', {
    method: 'POST',
    headers: {
        'X-WP-Nonce': cleara11yIgnores.nonce
    }
})
.then(response => response.json())
.then(data => {
    console.log('Undo Result:', data);
});
```

**Expected Response:**
```json
{
    "message": "Quick ignore removed."
}
```

### Verify deletion:
```sql
SELECT * FROM ignore_rules WHERE id = 'rule-uuid';
```

**Expected:** No results (rule deleted)

---

## Test 6: View Quick Ignores in Admin

### Step 1: Navigate to Ignores page
1. WordPress Admin → ClearA11y → Ignores
2. Click "Active" tab

**Expected:**
- Quick ignore rule visible
- Marked as system-generated (italic styling)
- Shows all details correctly

### Step 2: Toggle "Hide system-generated quick ignores"
1. Check the checkbox
2. Quick ignores should disappear
3. Uncheck to show them again

---

## Test 7: Test Error Cases

### A. Invalid violation ID:
```javascript
fetch('/wp-json/cleara11y/v1/ignores/quick', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': cleara11yIgnores.nonce
    },
    body: JSON.stringify({
        violation_id: 999999
    })
})
.then(response => response.json())
.then(data => {
    console.log('Error Test:', data);
});
```

**Expected:**
```json
{
    "code": "violation_not_found",
    "message": "Violation not found.",
    "status": 404
}
```

### B. Try to undo non-quick-ignore:
```javascript
// Create a regular rule first, then try to undo it
fetch('/wp-json/cleara11y/v1/ignores/{regular_rule_id}/undo', {
    method: 'POST',
    headers: {
        'X-WP-Nonce': cleara11yIgnores.nonce
    }
})
.then(response => response.json())
.then(data => {
    console.log('Should fail:', data);
});
```

**Expected:**
```json
{
    "code": "not_quick_ignore",
    "message": "This is not a quick ignore rule.",
    "status": 400
}
```

---

## Test 8: Test Fingerprint Generation

Quick ignore should generate fingerprints automatically:

```sql
SELECT 
    id,
    rule_ids,
    element_match->>'$.css_selector' as selector,
    element_match->>'$.selector_fingerprint' as selector_fp,
    element_match->>'$.element_fingerprint' as element_fp
FROM ignore_rules 
WHERE system_generated = 1 
LIMIT 1;
```

**Expected:**
- css_selector: The actual CSS selector from violation
- selector_fingerprint: SHA-256 hash of normalized selector
- element_fingerprint: SHA-256 hash of element data

---

## Test 9: Verify Audit Trail

All quick ignore actions should be logged:

```sql
SELECT 
    event_type,
    actor_user_id,
    timestamp,
    metadata
FROM ignore_audit_log 
ORDER BY timestamp DESC 
LIMIT 10;
```

**Expected events for quick ignore:**
1. `ignore_created` - When quick ignore created
2. `ignore_updated` - When refreshed (duplicate ignore)
3. `ignore_deleted` - When undone

---

## Quick Test Script (Browser Console)

Copy-paste this into WordPress admin browser console:

```javascript
async function testQuickIgnore(violationId) {
    const baseUrl = '/wp-json/cleara11y/v1/ignores';
    const nonce = cleara11yIgnores?.nonce || wpApiSettings?.nonce;
    
    if (!nonce) {
        console.error('Nonce not found. Are you logged in?');
        return;
    }
    
    console.log('=== Quick Ignore Test ===');
    console.log('Testing violation ID:', violationId);
    
    try {
        // Test 1: Create quick ignore
        console.log('\n1. Creating quick ignore...');
        const createResponse = await fetch(`${baseUrl}/quick`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce
            },
            body: JSON.stringify({ violation_id: violationId })
        });
        const createData = await createResponse.json();
        console.log('✓ Created:', createData);
        
        const ruleId = createData.id || createData.rule?.id;
        if (!ruleId) {
            console.error('No rule ID returned');
            return;
        }
        
        // Test 2: Try duplicate (should refresh)
        console.log('\n2. Testing duplicate (should refresh)...');
        const refreshResponse = await fetch(`${baseUrl}/quick`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce
            },
            body: JSON.stringify({ violation_id: violationId })
        });
        const refreshData = await refreshResponse.json();
        console.log('✓ Refreshed:', refreshData);
        
        // Test 3: Undo quick ignore
        console.log('\n3. Undoing quick ignore...');
        const undoResponse = await fetch(`${baseUrl}/${ruleId}/undo`, {
            method: 'POST',
            headers: {
                'X-WP-Nonce': nonce
            }
        });
        const undoData = await undoResponse.json();
        console.log('✓ Undone:', undoData);
        
        console.log('\n=== All tests passed! ===');
        
    } catch (error) {
        console.error('✗ Test failed:', error);
    }
}

// Run with: testQuickIgnore(123) // Replace 123 with actual violation ID
```

---

## Success Criteria

✅ **Pass if:**
- REST endpoint accessible
- Creates system-generated rule with correct configuration
- Generates fingerprints automatically
- Sets expiration to 24 hours
- Duplicates refresh existing rule
- Undo deletes the rule
- All actions logged in audit
- Visible in Ignores admin page

❌ **Fail if:**
- 404 errors
- Rule not created in database
- Wrong configuration (not page scope, not rule_on_element)
- No fingerprints generated
- Audit log missing entries
- Cannot undo

---

## Common Issues

**Issue:** "Violation not found"
- **Solution:** Make sure violation exists in scan_items table

**Issue:** "Nonce verification failed"
- **Solution:** Use correct nonce from wp_localize_script or generate fresh one

**Issue:** Rule created but fingerprints empty
- **Solution:** Check Fingerprint_Service is generating hashes correctly

**Issue:** Undo not working
- **Solution:** Verify rule has system_generated = 1
