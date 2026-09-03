# S ASIA Sales Report - Upload Feature

## Overview
Web form interface for uploading Order History and Tax Invoice data files (Excel/CSV) with a beautiful dashboard-style UI.

## Files Created

### 1. `upload.php` (Root Directory)
**Purpose**: Main web interface for file uploads  
**Location**: `/data-automation/upload.php`

Features:
- Dashboard-style header with logo
- Sidebar navigation (desktop) + hamburger menu (mobile)
- Two file upload zones (Order History & Tax Invoice)
- Drag-and-drop file upload support
- CSV delimiter configuration
- Real-time progress bar
- Results display with success/error messages
- Fully responsive design (768px breakpoint)

Design Elements:
- Matches root index.php styling (colors, fonts, CSS variables)
- Red gradient theme (#E0202E primary color)
- Plus Jakarta Sans font (Google Fonts)
- 256px sidebar (76px when collapsed)
- 64px fixed topbar

Authentication:
- Requires session with `$_SESSION['admin_id']`
- Idle timeout: 2 hours
- Redirects to index.php if not logged in

### 2. `process.php` (Root Directory)
**Purpose**: Backend processor for file uploads  
**Location**: `/data-automation/process.php`

Features:
- Handles multipart file uploads (POST requests)
- Supports .xlsx (Excel) and .csv (CSV) formats
- Custom CSV delimiter configuration
- Automatic file type detection
- Transaction-based database inserts

Processing:
1. Validates authentication and request method
2. Reads file using PhpOffice\PhpSpreadsheet (Composer dependency)
3. Detects file type (Order History vs Tax Invoice) by header row
4. Maps columns from uploaded file to database columns
5. Determines company and currency from order ID prefix:
   - MYHQ, MYHQCSB, MYBTCSB, MYBT → Company MYHQ, Currency MYR
   - SG* → Company SGHQ, Currency SGD
6. Inserts/updates records in orders table
7. Returns JSON response with success counts

Helper Functions:
- `parseDatetime()`: Converts various date formats (Excel, CSV, etc.)
- `parseAmount()`: Converts amount strings to decimal
- `determineCompanyAndCurrency()`: Maps order ID to company/currency

Database Operations:
```sql
INSERT INTO orders (company_id, order_id, order_datetime, member_code, ...) 
VALUES (...) 
ON DUPLICATE KEY UPDATE updated_at = NOW()
```

### 3. `includes/sidebar.php` (Updated)
**Location**: `/data-automation/includes/sidebar.php`

Changes:
- Added `$icoUpload` icon definition (SVG)
- Added "Upload Reports" link to Tools section
- Link: `<?= navItem('upload.php', $icoUpload, 'Upload Reports') ?>`
- Navigation appears in both desktop sidebar and mobile drawer

## Database Schema Integration

### Required Tables
- `companies` - Stores company codes and currencies
- `orders` - Main orders table for imported data
- `admin_users` - Admin authentication

### Column Mappings (orders table)
| Excel Header | Database Column | Data Type |
|---|---|---|
| Order ID | order_id | VARCHAR(80) |
| Date & Time | order_datetime | DATETIME |
| Member ID | member_code | VARCHAR(50) |
| Member Type | member_type | VARCHAR(50) |
| Member Name | member_name | VARCHAR(150) |
| Order Type | order_type | VARCHAR(100) |
| Sub Total | sub_total | DECIMAL(15,2) |
| Shipping Fee | shipping_fee | DECIMAL(15,2) |
| Voucher Discount | voucher_discount | DECIMAL(15,2) |
| Discount | discount | DECIMAL(15,2) |
| Convenience Fee | convenience_fee | DECIMAL(15,2) |
| Order Total | order_total | DECIMAL(15,2) |
| GST | gst | DECIMAL(15,2) |
| Total BV | total_bv | DECIMAL(15,2) |
| Total PV | total_pv | DECIMAL(15,2) |
| Total TP | total_tp | DECIMAL(15,2) |
| Payment Mode | payment_mode | VARCHAR(100) |
| Order Status | order_status | VARCHAR(50) |
| Delivery Status | delivery_status | VARCHAR(50) |
| Payment Gateway | payment_gateway | VARCHAR(100) |
| Payment Gateway ID | payment_gateway_id | VARCHAR(150) |

Automatic Columns:
- `company_id` - Determined from order ID prefix
- `currency_code` - Determined from order ID prefix (MYR or SGD)
- `created_at` - Set to current timestamp
- `updated_at` - Set to current timestamp

## Configuration

### Database Connection
Uses `config/db.php` constants:
```php
DB_HOST = 'localhost'
DB_PORT = 3306
DB_NAME = 'data_automation'
DB_USER = 'root'
DB_PASS = '' (empty)
DB_CHARSET = 'utf8mb4'
```

### Composer Dependencies
Required in `composer.json`:
```json
"openspout/openspout": "^3.0 || ^4.0"
```

Ensures `vendor/autoload.php` is installed.

## Usage

### For End Users
1. Navigate to: `http://localhost/data-automation/upload.php`
2. Login if not already authenticated
3. Drag files to upload zones or click to browse:
   - Order History file (.xlsx or .csv)
   - Tax Invoice file (.xlsx or .csv)
4. Set CSV delimiter if using CSV files (default: comma)
5. Click "Upload Files" button
6. Monitor progress bar
7. View results on completion

### For Developers
Frontend (upload.php):
```javascript
// Upload triggers POST to process.php with FormData
fetch('process.php', {
  method: 'POST',
  body: formData  // Files + delimiter
})
// Returns JSON: {status, order_history: {success, error}, tax_invoice: {success, error}}
```

Backend (process.php):
```php
// Returns:
{
  "status": "success",
  "order_history": {"success": 150},
  "tax_invoice": {"success": 200}
}
```

## Error Handling

### Client-Side (upload.php)
- File type validation (accepts .xlsx and .csv only)
- Drag-over visual feedback
- Error alerts with clear messages
- Disabled upload button until files selected
- Network error catching

### Server-Side (process.php)
- Authentication check (401 if not logged in)
- Request method validation (POST only)
- Database connection error handling
- Transaction rollback on failure
- Empty file detection
- Unknown file format detection

## Security Features

### Authentication
- Session-based (requires admin login)
- Idle timeout (2 hours)
- CSRF protection (inherited from index.php)

### Input Validation
- File MIME type checking
- File size limits (via HTTP server config)
- SQL injection prevention (parameterized queries)
- Character encoding (utf8mb4)

### Database
- Foreign key constraints
- Transaction support
- Duplicate key handling (idempotent imports)

## Responsive Design

### Desktop (>768px)
- Full sidebar visible (256px)
- Standard layout
- Sidebar collapses to 76px on toggle

### Mobile (<768px)
- Hidden sidebar, shown via hamburger menu
- Drawer overlay with touch close
- Single-column layout
- Full-width upload zones
- Touch-friendly buttons

## Styling

CSS Custom Properties (root variables):
```css
--red: #E0202E           /* Primary color */
--red-dark: #8E1620      /* Hover state */
--teal: #00B4B4          /* Secondary color */
--ink: #1B1B1F           /* Text color */
--gray-*: Various gray shades
--radius-lg: 18px        /* Card radius */
--topbar-h: 64px         /* Fixed header height */
--sidebar-w: 256px       /* Sidebar width */
```

All colors match index.php dashboard design for visual consistency.

## Future Enhancements

Potential improvements:
1. Batch processing for large files
2. File preview before upload
3. Column mapping customization UI
4. Import history/audit log
5. Scheduled imports via cron
6. Export functionality
7. Data validation rules configuration
8. Duplicate detection before import

## Troubleshooting

### "File already imported" message
- File hash matches previously imported file
- Solution: Upload with different filename or check import_batches table

### "Unknown file format" error
- Excel/CSV headers don't match expected format
- Solution: Verify column headers match schema

### Session expired
- User idle for 2+ hours
- Solution: Re-login via index.php

### Database connection error
- config/db.php constants incorrect
- MySQL server not running
- Solution: Check Laragon services and database credentials

### 404 on upload.php
- File not in root directory
- Wrong URL path
- Solution: Verify upload.php is at `/data-automation/upload.php`
