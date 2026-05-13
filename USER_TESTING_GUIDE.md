# Quick Ignore - User Testing Guide

## 🎯 How to Test Quick Ignore as a User

### Prerequisites
1. ClearA11y plugin is activated
2. You have run at least one accessibility scan
3. You're logged into WordPress Admin

---

## 📋 Step-by-Step Test

### Step 1: Go to the Issues Page
1. Login to WordPress Admin
2. Navigate to: **ClearA11y → Issues**
3. You should see a list of accessibility issues (violations)

---

### Step 2: Find the Quick Ignore Button
Look at any issue row. You should now see these buttons:
```
[Quick Ignore] [View] [Dismiss]
```

The **Quick Ignore** button should be first, with a dismiss icon (X).

---

### Step 3: Quick Ignore an Issue
1. Click the **Quick Ignore** button on any issue
2. **Expected Result:** A toast notification appears at bottom-left:
   ```
   ✅ Issue ignored until next scan
      [Undo] [X]
   ```

---

### Step 4: Verify the Toast
The toast should:
- ✅ Appear smoothly with slide-up animation
- ✅ Stay visible (doesn't auto-hide because it has Undo button)
- ✅ Show message: "Issue ignored until next scan"
- ✅ Have an "Undo" button
- ✅ Have a close (X) button
- ✅ Be styled with blue left border

---

### Step 5: Undo the Quick Ignore
1. Click the **Undo** button in the toast
2. **Expected Result:** 
   - First toast disappears
   - New toast appears: "Quick ignore removed"
   - The issue reappears in the list

---

### Step 6: Test Multiple Quick Ignores
1. Quick ignore 2-3 different issues
2. Verify each shows a toast notification
3. Go to: **ClearA11y → Ignores**
4. Click the **Active** tab
5. **Expected:** You should see your quick ignores listed
   - Marked as "system-generated" (italic text)
   - Target type: "rule_on_element"
   - Scope: "page"
   - Duration: "until next scan"

---

### Step 7: Test Duplicate Quick Ignore
1. Find an issue you already quick ignored
2. Click **Quick Ignore** on it again
3. **Expected:** Toast shows "Quick ignore refreshed"
   - No duplicate rule created
   - Expiration time extended

---

## 🎨 What You Should See

### Toast Notification Appearance
```
┌─────────────────────────────────────────┐
│ ✅ Issue ignored until next scan  [Undo] [X] │
└─────────────────────────────────────────┘
     ↑ Blue border on left
```

### Quick Ignore Button
```
┌──────────────┐
│ X Quick Ignore│ <- Dismiss icon + text
└──────────────┘
```

---

## 🐛 Troubleshooting

### "Quick Ignore button not visible"
**Fix:** 
1. Clear browser cache
2. Hard refresh (Ctrl+Shift+R / Cmd+Shift+R)
3. Check browser console for errors

### "Toast doesn't appear"
**Fix:**
1. Open browser console (F12)
2. Look for JavaScript errors
3. Check that `/wp-json/cleara11y/v1/ignores/quick` endpoint returns 200

### "Undo button doesn't work"
**Fix:**
1. Check browser console for errors
2. Verify the ignore rule ID is being passed correctly
3. Check that `/wp-json/cleara11y/v1/ignores/{id}/undo` endpoint exists

### "No cleara11yIgnores defined"
**Fix:**
1. Make sure you're on the Issues page
2. Clear cache
3. Check that `src/Admin/Admin.php` has the localization code

---

## ✅ Success Checklist

After testing, verify:

- [ ] Quick Ignore button appears on each violation row
- [ ] Clicking Quick Ignore shows toast notification
- [ ] Toast has "Undo" button
- [ ] Clicking Undo removes the ignore
- [ ] Toast disappears when clicking X
- [ ] Issues reload after quick ignore
- [ ] Issue stats update (count decreases)
- [ ] Quick ignores appear in Ignores → Active tab
- [ ] Quick ignores marked as "system-generated"
- [ ] Duplicate quick ignore refreshes existing rule

---

## 🎬 Expected User Flow

```
User sees issue → Clicks Quick Ignore 
                        ↓
                Toast appears: "Issue ignored until next scan"
                        ↓
                Issue disappears from list
                        ↓
        User can click "Undo" to bring it back
                        ↓
        Or ignore expires automatically after next scan
```

---

## 📝 Notes

- Quick Ignore creates a **temporary** ignore rule
- It only applies to **that specific page**
- It only applies to **that specific element + rule**
- It expires **after the next scan runs**
- System-generated rules are marked as temporary
- All actions are logged in the audit trail

---

## 🚀 Ready to Test?

**Test URL:** `wp-admin/admin.php?page=cleara11y-issues`

**What to look for:**
1. Quick Ignore button on each issue row
2. Toast notification when clicked
3. Undo functionality in toast
4. Issues disappearing/reappearing

**Expected time:** 2-3 minutes

---

**Status:** ✅ Implementation complete, ready for user testing!

**Questions?** Check the browser console for errors and report any issues.
