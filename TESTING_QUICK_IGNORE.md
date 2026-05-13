# Quick Ignore Testing - Quick Start Guide

## 🚀 Ready to Test!

The Quick Ignore functionality has been implemented and test infrastructure is in place.

## 📋 Quick Test Steps

### Option 1: Automated Test (Recommended)

**Step 1:** Run the automated test via URL
```
Visit: https://yoursite.com/wp-admin/admin.php?page=cleara11y_test_quick_ignore
```

**Step 2:** You'll see test results in your browser:
```
=== ClearA11y Quick Ignore Test ===
Test 1: Checking prerequisites...
  ✓ Class exists: ClearA11y\Models\Ignore_Rule
  ✓ Table exists: ignore_rules
  ...
=== Test Results ===
Passed: 35/35
✅ All tests passed!
```

---

### Option 2: Manual Browser Console Test

**Step 1:** Go to WordPress Admin → ClearA11y → Scans
**Step 2:** Find a violation and note its ID
**Step 3:** Open browser console (F12) and run:

```javascript
// 1. Get a nonce
const nonce = cleara11yIgnores?.nonce || wpApiSettings?.nonce;

// 2. Quick ignore a violation (replace 123 with actual violation ID)
fetch('/wp-json/cleara11y/v1/ignores/quick', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce
    },
    body: JSON.stringify({
        violation_id: 123
    })
})
.then(r => r.json())
.then(data => {
    console.log('Quick Ignore Result:', data);
    
    // 3. Undo it
    if (data.id) {
        return fetch(`/wp-json/cleara11y/v1/ignores/${data.id}/undo`, {
            method: 'POST',
            headers: { 'X-WP-Nonce': nonce }
        });
    }
})
.then(r => r.json())
.then(data => console.log('Undo Result:', data));
```

---

### Option 3: WP CLI

```bash
# From WordPress root directory:
wp eval-file wp-content/plugins/clearA11y/tests/test-quick-ignore.php
```

---

## 🧪 What Gets Tested

### 1. Prerequisites ✓
- All required classes exist
- Database tables created
- Proper table structure

### 2. REST Endpoints ✓
- `/ignores/quick` - Create quick ignore
- `/ignores/{id}/undo` - Undo quick ignore

### 3. Fingerprint Service ✓
- Generates SHA-256 hashes
- Deterministic (same input = same hash)
- Different inputs = different hashes

### 4. Database Operations ✓
- Creates ignore_rules record
- Creates audit log entry
- Creates violation match record
- Deletes rule on undo

### 5. Quick Ignore Behavior ✓
- Creates system-generated rule
- Sets target_type = 'rule_on_element'
- Sets scope = 'page'
- Sets duration = 'until_next_scan'
- Sets expires_at = 24 hours from now
- Generates fingerprints automatically

### 6. Duplicate Handling ✓
- Second quick ignore refreshes existing
- No duplicate rules created

### 7. Undo ✓
- Deletes the rule
- Logs to audit trail

---

## 📊 Expected Results

### Successful Quick Ignore Creates:

**In ignore_rules table:**
| Column | Expected Value |
|--------|----------------|
| target_type | `rule_on_element` |
| system_generated | `1` (true) |
| scope_type | `page` |
| duration_type | `until_next_scan` |
| expires_at | 24 hours from now |

**In ignore_audit_log table:**
- Event: `ignore_created`
- Actor: Your user ID
- Timestamp: Current time

**In violation_ignore_matches table:**
- violation_id: The violation you ignored
- ignore_rule_id: The generated UUID
- match_confidence: `high`

---

## 🔍 Verification Checklist

After running tests, verify:

- [ ] Automated test passes all checks
- [ ] Can see test rule in ClearA11y → Ignores → Active tab
- [ ] Test rule shows as "system-generated" (italic)
- [ ] Can undo the quick ignore
- [ ] Rule disappears after undo
- [ ] Audit log shows all actions

---

## 🐛 Common Issues

### "Test file not found"
**Fix:** Make sure `tests/test-quick-ignore.php` exists in the plugin directory.

### "No violations found"
**Fix:** Run a scan first to create test violations.

### "Permission denied"
**Fix:** Make sure you're logged in as admin.

### "Nonce verification failed"
**Fix:** Refresh the page to get a fresh nonce.

### "Database tables missing"
**Fix:** Deactivate/reactivate the plugin.

---

## 📁 Test Files Created

1. **`tests/test-quick-ignore.php`** - Main automated test
2. **`tests/README.md`** - Test documentation
3. **`QUICK_IGNORE_TEST_GUIDE.md`** - Detailed manual test guide

---

## 🎯 Next Steps After Testing

Once Quick Ignore is verified working:

1. **Add UI Button** - Add "Quick Ignore" button to violation rows
2. **Add Toast Notification** - Show "Ignored until next scan" message
3. **Add Undo Button** - In the toast notification
4. **Test Full Workflow** - End-to-end UI testing

---

## 🔗 Related Documentation

- [IGNORE_SYSTEM_VERIFICATION.md](../IGNORE_SYSTEM_VERIFICATION.md) - Full system verification
- [QUICK_IGNORE_TEST_GUIDE.md](../QUICK_IGNORE_TEST_GUIDE.md) - Detailed manual testing
- [tests/README.md](tests/README.md) - Test documentation

---

**Status:** ✅ Implementation complete, ready for testing!

**Estimated Test Time:** 5-10 minutes

**Quick Test URL:** 
```
/wp-admin/admin.php?page=cleara11y_test_quick_ignore
```
