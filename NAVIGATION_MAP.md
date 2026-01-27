# EduDisplej Navigation Map

## Website Structure

```
www.edudisplej.sk (Public Website)
├── Prihlásenie → control.edudisplej.sk/admin.php
├── Záujem → mailto:info@edudisplej.sk
└── Vyplniť Formulár → mailto:info@edudisplej.sk

dashboard.edudisplej.sk
└── Auto-redirect → control.edudisplej.sk/admin.php

control.edudisplej.sk (Admin Control Panel)
├── admin.php (Main Dashboard)
│   ├── Navigation:
│   │   ├── 👥 Users → users.php
│   │   ├── 🏢 Companies → companies.php
│   │   └── Logout → admin.php (logged out)
│   ├── Statistics Cards:
│   │   ├── Total Kiosks
│   │   ├── Online
│   │   ├── Offline
│   │   └── Companies
│   ├── Kiosks by Company (Visual Cards)
│   │   └── View details → kiosk_details.php?id=X
│   └── Detailed Kiosk Table
│       ├── 👁️ View → kiosk_details.php?id=X
│       ├── 📸 Screenshot → Request screenshot
│       └── ⚡/🐌 Toggle Ping → Change sync interval
│
├── users.php (User Management)
│   ├── Create User Form
│   ├── Edit User Form (when ?edit=X)
│   ├── User List Table
│   │   ├── ✏️ Edit → users.php?edit=X
│   │   └── 🗑️ Delete → users.php?delete=X
│   └── ← Back to Dashboard → admin.php
│
├── companies.php (Company Management)
│   ├── Create/Edit Company Form
│   ├── Company List Table
│   │   ├── ✏️ Edit → companies.php?edit=X
│   │   └── 🗑️ Delete → companies.php?delete=X
│   ├── Assign Kiosk to Company Form
│   └── ← Back to Dashboard → admin.php
│
├── kiosk_details.php (Kiosk Details)
│   ├── Kiosk Information
│   ├── Screenshot Display
│   ├── Sync Logs
│   └── ← Back to Dashboard → admin.php
│
├── userregistration.php (User Registration)
│   ├── Registration Form
│   └── Login here → admin.php
│
├── dbjavito.php (Database Auto-Fixer) ⚙️
│   ├── Check Database Structure
│   ├── Create Missing Tables
│   ├── Add Missing Columns
│   ├── Create Foreign Keys
│   ├── Results Display
│   ├── ← Back to Admin Panel → admin.php
│   └── ↻ Run Again → dbjavito.php
│
└── api.php (REST API for Kiosks)
    ├── ?action=register
    ├── ?action=sync
    ├── ?action=screenshot
    └── ?action=heartbeat
```

## User Flows

### First Time Setup Flow
```
1. Run dbjavito.php
   └── Creates database structure and default admin user
2. Login at admin.php (admin/admin123)
3. Create companies at companies.php
4. Create users at users.php
5. Assign users to companies
6. Wait for kiosks to register (or manually register)
7. Assign kiosks to companies at companies.php
```

### Daily Admin Flow
```
1. Login at admin.php
2. View kiosk status grouped by company
3. Check for offline kiosks
4. Request screenshots if needed
5. Manage users/companies as needed
```

### User Management Flow
```
admin.php → Users (👥)
  → users.php
    → Create User
    → Edit User
    → Delete User
    → Assign to Company
  → Back to admin.php
```

### Company Management Flow
```
admin.php → Companies (🏢)
  → companies.php
    → Create Company
    → Edit Company
    → Delete Company
    → Assign Kiosk
  → Back to admin.php
```

### Kiosk Monitoring Flow
```
admin.php
  → View kiosks by company (cards)
  → View details → kiosk_details.php
    → View hardware info
    → View screenshots
    → View sync logs
  → Back to admin.php
```

## Access Control

### Public Access
- www.edudisplej.sk (public website)
- dashboard.edudisplej.sk (redirects to login)
- admin.php (login page)
- userregistration.php (registration page)

### Requires Login (Admin Only)
- admin.php (dashboard)
- users.php
- companies.php
- kiosk_details.php
- dbjavito.php

### API Access (Kiosks)
- api.php (requires MAC address authentication)

## Key Features by Page

### admin.php (Dashboard)
✓ Visual overview of all kiosks
✓ Grouped by company
✓ Quick actions (screenshot, ping interval)
✓ Statistics cards
✓ Status indicators

### users.php (User Management)
✓ Create users
✓ Edit users
✓ Delete users (with protection)
✓ Assign to companies
✓ View all users

### companies.php (Company Management)
✓ Create companies
✓ Edit companies
✓ Delete companies (with validation)
✓ Assign kiosks to companies
✓ View company statistics

### dbjavito.php (Database Fixer)
✓ Auto-check structure
✓ Create missing tables
✓ Add missing columns
✓ Fix foreign keys
✓ Visual feedback
✓ Can run multiple times safely
