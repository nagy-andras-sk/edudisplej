# EduDisplej Testing Guide

## Quick Start Testing

### 1. Database Setup & Auto-Fixer

**Test the Database Auto-Fixer:**
```bash
# Navigate to:
http://control.edudisplej.sk/dbjavito.php
```

**Expected Results:**
- ✓ All 6 tables should be created/verified
- ✓ All columns should be present
- ✓ Foreign keys should be established
- ✓ Default admin user created
- ✓ Default company created
- ✓ Green success messages for all operations

**If errors occur:**
- Check database credentials in `dbkonfiguracia.php`
- Ensure MySQL/MariaDB is running
- Verify database user has proper permissions
- Run `dbjavito.php` again after fixing issues

---

### 2. User Management Testing

**Access User Management:**
```
1. Login to admin panel: http://control.edudisplej.sk/admin.php
   Username: admin
   Password: admin123

2. Click "👥 Users" in navigation
```

**Test Create User:**
1. Fill in:
   - Username: `testuser`
   - Email: `test@example.com`
   - Password: `test12345678`
   - Select a company (optional)
   - Check "Administrator privileges" (optional)
2. Click "Create User"
3. ✓ User should appear in the table below

**Test Edit User:**
1. Click "✏️ Edit" next to a user
2. Change email or other details
3. Click "Update User"
4. ✓ Changes should be reflected in the user list

**Test Delete User:**
1. Click "🗑️ Delete" next to a user (not yourself!)
2. Confirm the deletion
3. ✓ User should be removed from list
4. ✗ Trying to delete yourself should show error

**Test User-Company Assignment:**
1. Edit a user
2. Select a company from dropdown
3. Update user
4. ✓ User should show assigned company in list

---

### 3. Company Management Testing

**Access Company Management:**
```
1. From admin panel, click "🏢 Companies"
```

**Test Create Company:**
1. Enter company name: `Test School`
2. Click "Create Company"
3. ✓ Company appears in table with ID and kiosk count

**Test Edit Company:**
1. Click "✏️ Edit" next to a company
2. Change name to: `Test School Updated`
3. Click "Update Company"
4. ✓ Name should be updated in table

**Test Delete Company:**
1. Click "🗑️ Delete" next to a company with no kiosks/users
2. Confirm deletion
3. ✓ Company should be removed
4. ✗ Cannot delete company with assigned kiosks/users (error message shown)

**Test Kiosk Assignment:**
1. Select a kiosk from dropdown
2. Select a company
3. Add location: `Main Building, Room 101`
4. Add comment: `Testing assignment`
5. Click "Assign Kiosk"
6. ✓ Kiosk should show new company and location

---

### 4. Admin Dashboard Testing

**Access Dashboard:**
```
http://control.edudisplej.sk/admin.php
```

**Visual Elements to Check:**

**Statistics Cards:**
- ✓ Total Kiosks count
- ✓ Online kiosks count
- ✓ Offline kiosks count
- ✓ Companies count

**Kiosks by Company:**
- ✓ Each company shows in its own section
- ✓ Kiosks grouped under correct company
- ✓ Card layout showing hostname, location, status
- ✓ "View details →" link works
- ✓ Unassigned kiosks shown separately if any exist

**Detailed Kiosk Table:**
- ✓ All columns visible: ID, Hostname, MAC, Company, Status, etc.
- ✓ Status badges colored correctly (green=online, red=offline, yellow=pending)
- ✓ Actions buttons work: View, Screenshot, Toggle Ping

---

### 5. Navigation Testing

**Test All Links:**

**From www.edudisplej.sk:**
- ✓ "Prihlásenie" (Login) → Goes to control.edudisplej.sk/admin.php
- ✓ "Záujem" (Inquiry) → Opens email client with pre-filled template
- ✓ "Vyplniť Formulár" (Fill Form) → Opens email client

**From dashboard.edudisplej.sk:**
- ✓ Auto-redirects to control.edudisplej.sk/admin.php
- ✓ Manual link works if auto-redirect disabled

**From control.edudisplej.sk (Admin Panel):**
- ✓ 👥 Users → Goes to users.php
- ✓ 🏢 Companies → Goes to companies.php
- ✓ Logout → Destroys session and returns to login
- ✓ All kiosk action links work

**From Users Page:**
- ✓ "← Back to Dashboard" → Returns to admin.php
- ✓ Edit/Delete buttons work

**From Companies Page:**
- ✓ "← Back to Dashboard" → Returns to admin.php
- ✓ Edit/Delete buttons work

**From Kiosk Details Page:**
- ✓ "← Back to Dashboard" → Returns to admin.php

---

### 6. Security Testing

**Password Requirements:**
- ✗ Password less than 8 characters → Should show error
- ✓ Password 8+ characters → Should work

**Session Security:**
- ✗ Access users.php without login → Should redirect to admin.php
- ✗ Access companies.php without login → Should redirect to admin.php
- ✓ Login as admin → Should have access to all pages
- ✓ Login as non-admin user → Should redirect (isadmin check)

**SQL Injection Protection:**
- All inputs use prepared statements
- Test with: `' OR '1'='1` in username → Should not work

**Self-Protection:**
- ✗ Try to delete your own user account → Should show error

---

### 7. Database Structure Verification

**Run this SQL to verify structure:**
```sql
USE edudisplej_sk;

-- Check all tables exist
SHOW TABLES;
-- Should show: companies, kiosk_group_assignments, kiosk_groups, kiosks, sync_logs, users

-- Check users table structure
DESCRIBE users;
-- Should include: id, username, password, email, isadmin, company_id, created_at, last_login

-- Check foreign keys
SELECT 
    TABLE_NAME,
    COLUMN_NAME,
    CONSTRAINT_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM
    INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE
    REFERENCED_TABLE_SCHEMA = 'edudisplej_sk'
    AND REFERENCED_TABLE_NAME IS NOT NULL;
-- Should show all foreign key relationships
```

---

## Common Issues and Solutions

### Issue: Cannot login
**Solution:**
1. Run `dbjavito.php` to create default admin user
2. Default credentials: admin / admin123
3. Check database connection in `dbkonfiguracia.php`

### Issue: Links not working
**Solution:**
1. Verify web server virtual hosts are configured correctly
2. Check that each folder maps to correct subdomain:
   - www_edudisplej_sk → www.edudisplej.sk
   - control_edudisplej_sk → control.edudisplej.sk
   - dashboard_edudisplej_sk → dashboard.edudisplej.sk

### Issue: Database errors
**Solution:**
1. Run `dbjavito.php` to auto-fix structure
2. Check MySQL error logs
3. Verify database credentials
4. Ensure user has proper permissions (GRANT ALL)

### Issue: Screenshot not working
**Solution:**
1. Check kiosk sync service is running
2. Verify API connectivity
3. Check permissions on screenshots folder
4. Review sync_logs table for errors

---

## Success Criteria

All features working correctly when:
- ✅ Database auto-fixer completes without errors
- ✅ Can create, edit, delete users
- ✅ Can assign users to companies
- ✅ Can create, edit, delete companies
- ✅ Can assign kiosks to companies
- ✅ Dashboard shows kiosks grouped by company
- ✅ All navigation links work correctly
- ✅ No broken links anywhere
- ✅ Security validations working (password length, self-deletion prevention, etc.)
- ✅ All foreign key relationships established

---

## Next Steps After Testing

1. **Change default admin password** immediately
2. Configure HTTPS/SSL for production
3. Set up regular database backups
4. Configure firewall rules
5. Test kiosk registration and sync
6. Monitor sync_logs for any issues

---

## Support

If you encounter any issues:
1. Check browser console for JavaScript errors
2. Check PHP error logs
3. Review MySQL error logs
4. Re-run `dbjavito.php`
5. Verify all prerequisites are installed
