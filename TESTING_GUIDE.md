# Testing Guide - Asset Management System

## 🎯 Current System Status

### ✅ Data Migration Complete
- **Total Assets**: 3,299 records
- **Countries**: Lesotho (all assets currently)
- **Locations**: 5 locations created
- **Status**: All assets marked as "Available"
- **QR Codes**: Generated for all assets (format: `1PWR-LSO-000001`)

### 🌐 Application Access
- **URL**: https://am.1pwrafrica.com
- **HTTPS**: ✅ Working (SSL configured)
- **Admin Login**: Ready

---

## 🧪 Testing Steps

### 1. Access the Application

**Open in your browser:**
```
https://am.1pwrafrica.com
```

**Expected:**
- Should redirect to login page
- Or show dashboard if already logged in

---

### 2. Test Login

**Credentials:**
- **Username**: `mso`
- **Email**: `mso@1pwrafrica.com`
- **Password**: `Welcome123!`

**Steps:**
1. Navigate to: `https://am.1pwrafrica.com/login.php`
2. Enter username and password
3. Click "Login"

**Expected:**
- Successful login
- Redirect to dashboard/home page
- See navigation menu with 1PWR logo

**⚠️ IMPORTANT**: Change password after first login!

---

### 3. Test Dashboard/Home Page

**After login, check:**
- [ ] Dashboard loads without errors
- [ ] Navigation menu visible (sidebar)
- [ ] 1PWR logo displays correctly
- [ ] Top navigation bar visible
- [ ] No PHP errors or warnings

---

### 4. Test Asset List/View

**Navigate to Assets page** (check sidebar menu)

**Verify:**
- [ ] Assets list displays
- [ ] Can see total count (should show ~3,299)
- [ ] Assets have names, QR codes, status
- [ ] Pagination works (if many assets)
- [ ] Search/filter functionality (if implemented)

**Sample Data to Look For:**
- Assets with names like "tablet", "Scale", "mouse", "utility trailer"
- QR codes like "1PWR-LSO-000001"
- Status: "Available"

---

### 5. Test Asset Details

**Click on any asset** to view details

**Verify:**
- [ ] Asset details page loads
- [ ] Shows all fields:
  - Name, Description
  - Serial Number
  - Manufacturer, Model
  - Location
  - Status, Condition
  - QR Code ID
  - Purchase date, price (if available)
- [ ] QR code displays (if implemented)

---

### 6. Test Asset Search/Filter

**If search/filter is available:**

- [ ] Search by name works
- [ ] Filter by status works
- [ ] Filter by location works
- [ ] Filter by country works (should show Lesotho)

---

### 7. Test Multi-Country Support

**Check:**
- [ ] Country filter/selector visible
- [ ] Can switch between Lesotho, Zambia, Benin
- [ ] Assets filtered by selected country
- [ ] Country-specific reports (if available)

**Note**: Currently all assets are in Lesotho, so other countries will be empty until more data is imported.

---

### 8. Test Navigation

**Check all menu items in sidebar:**

- [ ] Dashboard/Home
- [ ] Assets
- [ ] Inventory
- [ ] Locations
- [ ] Categories
- [ ] Reports (if available)
- [ ] Users/Admin (if available)
- [ ] Settings (if available)

**Verify:**
- [ ] All links work
- [ ] Pages load without errors
- [ ] Navigation highlights current page

---

### 9. Test Responsive Design

**Check on different screen sizes:**
- [ ] Desktop view (1920x1080)
- [ ] Tablet view (768px width)
- [ ] Mobile view (375px width)

**Verify:**
- [ ] Layout adapts correctly
- [ ] Sidebar collapses on mobile (if implemented)
- [ ] Forms are usable on mobile
- [ ] Tables scroll horizontally if needed

---

### 10. Test Forms (Create/Edit Asset)

**If create/edit forms are available:**

**Create New Asset:**
- [ ] Form loads
- [ ] All required fields marked
- [ ] Can enter data
- [ ] Validation works (required fields, formats)
- [ ] Submit button works
- [ ] Success message displays
- [ ] Asset appears in list after creation

**Edit Asset:**
- [ ] Edit form loads with existing data
- [ ] Can modify fields
- [ ] Save changes works
- [ ] Updated data displays correctly

---

### 11. Test QR Code Functionality

**Check:**
- [ ] QR codes display for assets
- [ ] QR code format: `1PWR-LSO-000001`
- [ ] Can scan QR codes (if scanner available)
- [ ] QR code links to asset details (if implemented)

**Note**: Physical QR code printing with Brother PT-P710BT will be tested separately.

---

### 12. Test Inventory Management

**If inventory features are available:**

- [ ] View inventory levels
- [ ] Stock taking functionality
- [ ] Check-in/Check-out
- [ ] Allocation to employees
- [ ] Transaction history

---

### 13. Test Data Quality

**Review sample assets:**

**Check for:**
- [ ] Assets with "Unknown" names (need cleanup)
- [ ] Missing serial numbers (some may be null)
- [ ] Missing asset tags (some may be null)
- [ ] Incomplete location data
- [ ] Duplicate entries (should be minimal due to duplicate detection)

**Sample Query to Check:**
```sql
SELECT name, COUNT(*) as count 
FROM assets 
WHERE name = 'Unknown' OR name = ''
GROUP BY name;
```

---

### 14. Test Error Handling

**Try:**
- [ ] Invalid login credentials (should show error)
- [ ] Access protected page without login (should redirect)
- [ ] Invalid form data (should show validation errors)
- [ ] Non-existent asset ID (should show 404 or error)

---

### 15. Test Performance

**Check:**
- [ ] Page load times (< 3 seconds)
- [ ] Asset list loads quickly
- [ ] Search results appear quickly
- [ ] No timeout errors

**With 3,299 assets:**
- List view should paginate or limit results
- Search should be indexed/fast

---

## 📊 Test Results Template

### Login Test
- [ ] ✅ Pass / ❌ Fail
- Notes: _______________

### Dashboard Test
- [ ] ✅ Pass / ❌ Fail
- Notes: _______________

### Asset List Test
- [ ] ✅ Pass / ❌ Fail
- Notes: _______________

### Asset Details Test
- [ ] ✅ Pass / ❌ Fail
- Notes: _______________

### Navigation Test
- [ ] ✅ Pass / ❌ Fail
- Notes: _______________

### Forms Test
- [ ] ✅ Pass / ❌ Fail
- Notes: _______________

### QR Code Test
- [ ] ✅ Pass / ❌ Fail
- Notes: _______________

### Performance Test
- [ ] ✅ Pass / ❌ Fail
- Notes: _______________

---

## 🐛 Known Issues to Watch For

1. **Data Quality**
   - Some assets may have "Unknown" or empty names
   - Some serial numbers may be null
   - Location data may need cleanup

2. **Missing Features** (May not be implemented yet)
   - Advanced search/filtering
   - Bulk operations
   - Export functionality
   - Reports and analytics
   - QR code scanning integration

3. **Google Sheets Data**
   - Still pending import
   - Will add more records when imported

---

## 🚀 Next Steps After Testing

1. **Document any bugs or issues found**
2. **Fix critical issues first** (login, data display, navigation)
3. **Data cleanup** (fix "Unknown" names, missing data)
4. **Import Google Sheets data** (when available)
5. **User training** on how to use the system
6. **QR code hardware integration** (Brother printer, Symcode scanner)

---

## 📝 Testing Notes

**Date**: _______________
**Tester**: _______________
**Browser**: _______________
**OS**: _______________

**Issues Found:**
1. _______________
2. _______________
3. _______________

**Recommendations:**
1. _______________
2. _______________
3. _______________
