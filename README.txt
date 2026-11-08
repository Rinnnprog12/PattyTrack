PATTYTRACK - INVENTORY SYSTEM FOR ANGEL'S BURGER (TARLAC BRANCH)
WMA4B Group 3 | PHP + MySQL (XAMPP). No framework, no Composer, nothing to install.

SETUP
1. Start Apache and MySQL in the XAMPP Control Panel.
2. Copy this folder to  C:\xampp\htdocs\pattytrack
3. Open http://localhost/phpmyadmin  ->  Import  ->  choose pattytrack.sql  ->  Go.
   (Creates the "pattytrack" database, tables and sample data. Importing again resets everything.)
4. Open http://localhost/pattytrack/      (if your MySQL has a password, edit config.php)

DEMO ACCOUNTS
   admin   / admin123     Admin (Administrator)  - full access + approves purchase orders + manages accounts
   manager / manager123   Manager (Branch Manager) - daily inventory work, raises purchase orders
   staff   / staff123     Staff / Crew            - checks stock, records items used
   jramos  / staff123     second Staff account

WHO CAN DO WHAT  (matches Section D / E of the proposal)
   Feature                                   Admin   Manager   Staff
   Dashboard, low-stock alerts                 yes     yes      yes
   Products: view / search / filter / sort     yes     yes      yes
   Products: add, edit, remove                 yes     yes       -
   Categories                                  yes     yes       -
   Stock-in (record deliveries)                yes     yes       -
   Stock-out (record items used)               yes     yes      yes
   Approve staff stock corrections             yes     yes       -
   Suppliers                                   yes     yes       -
   Purchase orders: create                     yes     yes       -
   Purchase orders: APPROVE / REJECT           yes      -        -
   Purchase orders: receive into stock         yes     yes       -   (only after approval)
   Purchase orders: cancel                     any     before approval only
   Reports (dashboard summaries, export)       yes     yes       -
   Activity log                                all     all      own only
   Users: add, change role, reset password,
          deactivate                           yes      -        -
   Wrong role -> HTTP 403.  Not logged in -> login page.
   Role and account status are re-checked from the database on EVERY request, so demoting or
   deactivating someone takes effect immediately, even if they are still logged in.

PURCHASE ORDER FLOW  (Use Case: Create PO -> Admin Approves PO)
   Manager creates order  ->  "Awaiting approval"  ->  Admin approves (or rejects = Cancelled)
   ->  "Approved"  ->  Manager/Admin clicks "Receive delivery"  ->  Delivered + stock increased.
   An order created by the Admin is approved automatically.
   The status column stays Pending / Delivered / Cancelled exactly as in the data dictionary;
   "awaiting approval" is simply Pending with approved_by still empty.

DATABASE  (follows the Preliminary Data Dictionary)
   users, categories, products, suppliers, purchase_orders, purchase_order_items,
   stock_in, stock_out, activity_logs
   Extra columns beyond the dictionary: products.is_active + updated_at, suppliers.category_id +
   contact_person, purchase_orders.expected_date / note / created_by / approved_by / approved_at /
   delivered_at, stock_in.supplier_id / po_id (optional) / user_id, stock_out.note / status / user_id.
   Note: the dictionary lists the role ENUM as "Admin / Staff/Crew / Owner" but Sections D, E, H and L
   use Admin / Manager / Staff-Crew. This build uses Admin / Manager / Staff.

FILES
   config.php        database connection (PDO)
   process.php       helpers + shared business rules + every form action
   layout.php        sidebar / top bar / flash messages (menu changes by role)
   style.css         dark Angel's Burger theme
   api.php           REST API (JSON)
   login.php, index.php
   dashboard.php  products.php  product_form.php  categories.php  stock_in.php  stock_out.php
   alerts.php  suppliers.php  supplier_form.php  purchase_orders.php  po_form.php
   reports.php  activity.php  users.php
   pattytrack.sql

SECURITY  (Section J)
   - Passwords hashed with password_hash / password_verify
   - Prepared statements for all queries
   - CSRF token on every form and on API writes (POST/PUT/DELETE)
   - Input validation on the server (business rules throw InventoryException)
   - Secure logout (session destroyed, cookie cleared); session id regenerated at login
   - Role-based access control on every page and every action

BUSINESS RULES
   - Low stock = active product with current_stock <= reorder_level (dashboard, bell, alerts page).
   - Stock-out can never be larger than what is on hand ("insufficient stock" error).
   - A STAFF "Correction" is saved as pending; stock changes only when a Manager/Admin approves it.
   - Stock changes and their stock_in / stock_out record are saved in one database transaction.
   - Expiry date is recorded per delivery for reference only (no expiry alerts, per the scope).
   - "Remove" on a product hides it from use but keeps its history (nothing is hard-deleted).
   - Reports use quantity x the product's current unit cost, approved entries only.

REST API  (test with Postman - Section J)
   Base: http://localhost/pattytrack/api.php?resource=...
   1) POST  ?resource=login   body (JSON): {"username":"admin","password":"admin123"}
        -> returns {"csrf":"<token>"} and sets the session cookie (Postman keeps cookies).
   2) For every POST / PUT / DELETE add the header   X-CSRF-Token: <token>
   Endpoints
      GET    ?resource=products [&id=1] [&q=patty] [&category_id=1] [&low=1]
      POST   ?resource=products        {"name","category_id","unit","reorder_level","unit_cost","stock"}   Admin, Manager
      PUT    ?resource=products&id=1   {"name","category_id","unit","reorder_level","unit_cost"}           Admin, Manager
      DELETE ?resource=products&id=1   (removes from use, keeps history)                                  Admin, Manager
      GET    ?resource=stock_in        latest 50 deliveries                                               Admin, Manager
      POST   ?resource=stock_in        {"product_id","quantity","date"?,"expiry_date"?,"supplier_id"?}    Admin, Manager
      GET    ?resource=stock_out       latest 50 usage records
      POST   ?resource=stock_out       {"product_id","quantity","reason","note"?}                         everyone
      POST   ?resource=logout
   Errors: 401 not logged in | 403 wrong role / missing token | 404 not found | 422 rule broken
           (e.g. {"error":"Insufficient stock: only 6 pack of Mayonnaise on hand. Enter 6 or less."})

NOT INCLUDED (planned for later weeks in the proposal timeline)
   - Laravel / Eloquent migration (Weeks 7-8). This build is plain PHP so it runs on XAMPP as-is.
   - Object-oriented classes (User, Item, Transaction, StockAlert). The business rules are already
     isolated in functions that throw InventoryException, so they are easy to move into classes.
   - Charts need internet (Chart.js CDN) and the font needs Google Fonts; without internet
     everything still works, only charts and the custom font are missing.
   - "Export to PDF" opens the print dialog: choose "Save as PDF".
   - Demo passwords and sample data are for local XAMPP use only.
