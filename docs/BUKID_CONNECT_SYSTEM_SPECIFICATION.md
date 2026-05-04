# BUKID CONNECT  
## An App-Based Farmer-to-Buyer Agricultural Marketplace  
### System Structure & Development Specification (Capstone Documentation)

---

## Document Control

| Field | Value |
|--------|--------|
| System | BUKID CONNECT |
| Type | Secure, event-driven, web-based marketplace |
| Scope | Philippine agriculture — direct farmer–buyer linkage |
| Audience | BSIT capstone (SRS/SDD alignment) |

---

## 1. System Overview

### 1.1 Purpose of the System

**Bukid Connect** is a web-based marketplace platform that enables **farmers** to list crops and manage orders, **buyers** to discover produce and complete purchases, and **administrators** to govern verification, compliance, and broadcast advisories. The system emphasises **direct trade**, **traceability-oriented workflows** (orders, visits, transaction records), and **timely communication** (messaging, notifications, alerts).

### 1.2 Problems It Solves

| Problem | System Response |
|--------|------------------|
| Limited direct market access for smallholders | Digital listings, orders, and buyer reach without exclusive reliance on traditional intermediaries |
| Information asymmetry on price and availability | Searchable listings, status-tracked orders, and optional analytics |
| Weak coordination for visits and logistics | Structured farm-visit requests, farmer approval, and delivery mode fields |
| Fragmented communication | Unified messaging and in-app notifications tied to user actions |
| Weak accountability in informal trade | User verification (farmer), audit and event logs, review/rating after delivery |

### 1.3 Importance in Philippine Agriculture

Smallholder agriculture remains central to food security and rural livelihoods. Digital marketplaces can reduce **search costs**, improve **price transparency**, and support **programs led by LGUs** through aggregated, non-personally identifiable statistics where policy allows. Bukid Connect is scoped as a **capstone-feasible** system that demonstrates digital inclusion and documented transactions relevant to **Good Agricultural Practice** and **local food systems** discourse.

### 1.4 Event-Driven Marketplace Concept

Core business occurrences are modelled as **events** (e.g. order placed, visit requested, alert broadcast). Each event leads to **detection** (validation, authorisation, inventory rules) and **actions** (persist state, notify parties, append audit/event logs). This separation supports maintainability, clearer testing, and future integration with queues or webhooks.

---

## 2. Farmer Role

### 2.A Functionalities / Features

| Area | Feature | Description |
|------|---------|-------------|
| Access | Registration & Login | Account creation with role `farmer`; session-based access |
| Access | Gmail OTP Verification | Email OTP for enrolment and login when email is unverified |
| Profile | Profile Management | Name, contact, address, optional farm name and province |
| Farm | Farm Information Setup | Farm name, province, location fields aligned with listings |
| Listings | Crop Listing Management | Create, edit visibility, pricing, units, description |
| Operations | Inventory Management | Quantity on hand; automatic deduction on order placement |
| Operations | Harvest Scheduling | Harvest / availability window via harvest date on listings |
| Commerce | Accept/Reject Orders | Confirm or cancel pending orders; status workflow to delivered |
| Commerce | Farm Visit Approval | Approve, decline, or complete visit requests |
| Communication | Real-Time Chat with Buyers | Conversations between buyer and farmer user IDs |
| Logistics | Delivery Coordination | Pickup vs delivery, notes, status updates |
| Finance | Earnings Tracking | Aggregated from completed transactions |
| Trust | Ratings & Reviews | View feedback linked to orders |
| System | Notification Centre | In-app list for orders, visits, messages, alerts |
| Insight | Analytics Dashboard | Earnings and order breakdowns (implementation scope) |
| Risk | Weather & Pest Alerts | Receive admin-broadcast advisories |
| Records | Transaction History | Completed settlement records |

### 2.B User Interface (UI) — Pages / Screens

1. Farmer Login Page  
2. OTP Verification Screen  
3. Farmer Dashboard  
4. Add / Edit Crop Listing (modal or page)  
5. Inventory / My Listings Page  
6. Orders Page (pending → delivered workflow)  
7. Visit Schedule / List (calendar-style presentation optional)  
8. Chat Interface  
9. Earnings & Analytics Page  
10. Notifications Page  
11. Farmer Profile / Settings  

### 2.C Dashboard Components

- Total Orders (period or all-time)  
- Pending Orders  
- Total Earnings (from delivered/settled logic)  
- Crop Availability (low-stock emphasis)  
- Upcoming Visits  
- Market Demand Trends (proxy: orders/views if instrumented)  
- Weather / Pest Alerts (latest relevant broadcasts)  

### 2.D Navigation Menu (Example)

- Dashboard  
- Crops (Listings / Inventory)  
- Orders  
- Visits  
- Messages  
- Analytics  
- Notifications  
- Settings  
- Logout  

### 2.E Actions and Permissions

| Action | Permission |
|--------|------------|
| Manage own listings | Farmer (own `farmer_id`) |
| Update order status for own sales | Farmer (orders where `farmer_id` = self) |
| Approve/decline visits on own farm | Farmer |
| Chat with counterparties | Farmer (within allowed threads) |
| Broadcast system alerts | Denied |
| Manage other users | Denied |

### 2.F Notifications and Alerts

- New order on farmer’s crop  
- Order cancelled by buyer (when allowed)  
- Visit requested / status changed  
- New chat message  
- Low inventory (if threshold implemented)  
- Admin weather/pest/market alert fan-out  
- Verification status update  

### 2.G Reports and Analytics

- Sales by crop and period  
- Order fulfilment (acceptance rate, cancellations)  
- Visit outcomes  
- Rating summary  
- Inventory movement vs listings  

---

## 3. Buyer Role

### 3.A Functionalities / Features

| Area | Feature |
|------|---------|
| Access | Registration & Login |
| Access | OTP Authentication |
| Profile | Buyer Profile Management |
| Discovery | Browse Crop Listings |
| Discovery | Search & Filter Products |
| Commerce | Place Orders |
| Commerce | Advance Crop Pre-Orders |
| Engagement | Request Farm Visits |
| Communication | Real-Time Messaging |
| Trust | Ratings & Reviews |
| Engagement | Favorite Farmers |
| Logistics | Delivery Tracking (status timeline) |
| Commerce | Order Tracking |
| System | Notifications |
| Records | Transaction History |

### 3.B User Interface (UI) — Pages / Screens

1. Buyer Login Page  
2. Marketplace Homepage  
3. Product Listing / Search Results  
4. Crop Details (card/modal)  
5. Order Checkout  
6. Visit Request Page / Modal  
7. Chat Interface  
8. Buyer Dashboard  
9. Notifications Page  
10. Transaction History Page  
11. Buyer Profile Settings  

### 3.C Dashboard Components

- Recent Orders  
- Pending Deliveries  
- Favorite Farmers  
- Order Statistics  
- Recommended Crops (rule-based or popularity)  
- Notifications  

### 3.D Navigation Menu (Example)

- Marketplace  
- Orders  
- Visits  
- Messages  
- Favorites  
- Notifications  
- Profile / Settings  
- Logout  

### 3.E Actions and Permissions

| Action | Permission |
|--------|------------|
| Browse and search | Buyer (authenticated) |
| Place/cancel own pending orders | Buyer |
| Request visits | Buyer |
| Message farmers | Buyer (contextual) |
| Rate after delivered order | Buyer (policy-based) |
| Admin functions | Denied |

### 3.F Notifications and Alerts

- Order accepted/rejected or status updates  
- Visit approved/declined  
- New message  
- Broadcast alerts  
- Listing changes affecting interest  

### 3.G Reports and Analytics (User-Visible)

- Purchase history  
- Spending summary by period  
- Visit history  

---

## 4. Admin Role

### 4.A Functionalities / Features

- Secure Admin Login  
- User Account Management (activate/deactivate non-admin)  
- Farmer Verification (`gov_id_verified`)  
- Buyer Monitoring (account status, audit context)  
- Crop Listing Monitoring (moderation)  
- Transaction Monitoring  
- Weather & Pest Alert Broadcasting  
- Audit Log Monitoring  
- Report Generation (exports)  
- Analytics & Insights (KPIs)  
- Role & Permission Management (conceptual tiers)  
- Backup & Recovery (operational procedure)  
- Security Monitoring (failed logins, lockouts)  

### 4.B User Interface (UI) — Pages / Screens

1. Admin Login Page  
2. Admin Dashboard  
3. User Management Page  
4. Crop Monitoring Page  
5. Transactions Page  
6. Reports Page (or export actions)  
7. Alert Broadcasting Page  
8. Audit Logs Page  
9. Analytics Dashboard  
10. Settings Panel  

### 4.C Dashboard Components

- Total Users  
- Active Farmers / Active Buyers (define metric, e.g. verified or recent login)  
- Total Transactions / Revenue (delivered orders)  
- Pending Verifications  
- System Alerts  
- Security-relevant log indicators  
- Marketplace Statistics  

### 4.D Navigation Menu (Example)

- Dashboard  
- Users  
- Crop Listings  
- Transactions  
- Reports  
- Alerts  
- Audit Logs  
- Analytics  
- Settings  
- Logout  

### 4.E Actions and Permissions

| Action | Typical Access |
|--------|----------------|
| Verify farmers | Admin |
| Moderate listings | Admin |
| Broadcast alerts | Admin |
| View audit/event logs | Admin |
| Toggle user active (non-admin) | Admin |

### 4.F Notifications and Alerts

- Verification queue thresholds  
- Suspicious activity (manual review)  
- Integration or delivery failures (if external payment added)  
- Backup job outcomes (operational)  

### 4.G Reports and Analytics

- User growth and verification funnel  
- Orders by status and region  
- Security report (lockouts, OTP failures)  
- Marketplace KPIs  

---

## 5. Event-Driven System Logic

**Pattern:** Event → Detection → Action  

| Event | Detection | Action |
|-------|-----------|--------|
| Buyer places order | Stock, price, role checks | Persist order; reduce inventory; notify farmer & buyer; event log |
| Buyer requests farm visit | Conflict check on slot | Create visit (pending); notify farmer; event log |
| Farmer updates inventory | Quantity vs threshold | Update listing; optional buyer notifications on shortfall |
| Admin broadcasts weather alert | Valid admin session | Store alert; fan-out in-app notifications |
| Failed login attempts | Counter per user/IP | Lockout after threshold; audit log |
| Payment confirmation received | Idempotent validation | Update order/transaction; notify parties (when integrated) |

---

## 6. Security Architecture

| Control | Implementation Notes |
|---------|----------------------|
| BCrypt password hashing | `password_hash` / `password_verify` |
| Gmail OTP verification | OTP stored with expiry; email channel (SMTP in production) |
| Session timeout | Idle timeout enforced server-side |
| SQL injection protection | Prepared statements throughout |
| HTTPS encryption | Required in production deployment |
| Input validation | Server-side validation and sanitisation for display |
| Role-based access control | Session role checked per endpoint |
| Failed login lockout | Increment counter; time-based lockout |
| Audit trails | `audit_logs` for security-relevant actions |
| Secure REST-style API | Session cookie with same-origin policy; CSRF consideration for cookie auth |

---

## 7. Database Structure

### 7.1 Logical Entities

The specification lists **Users**, **Farmers**, **Buyers** as distinct actors. In the reference implementation, **Farmers** and **Buyers** are represented by **`users`** rows with `role = 'farmer'` or `role = 'buyer'` (single table inheritance). Optional normalisation: future `farmer_profiles` / `buyer_profiles` tables keyed by `user_id`.

### 7.2 Physical Tables (Implemented)

| Table | Purpose | PK | Main FKs |
|-------|---------|-----|----------|
| `users` | All accounts | `id` | — |
| `crops` | Crop listings | `id` | `farmer_id` → `users.id` |
| `orders` | Purchase orders | `id` | `buyer_id`, `farmer_id`, `crop_id` |
| `transactions` | Completed sale records | `id` | `order_id`, `buyer_id`, `farmer_id` |
| `farm_visits` | Visit requests | `id` | `buyer_id`, `farmer_id` |
| `messages` | Chat messages | `id` | `sender_id`, `receiver_id` |
| `ratings` | Reviews | `id` | `buyer_id`, `farmer_id`, optional `order_id` |
| `alerts` | Admin broadcasts (weather/pest/market) | `id` | `admin_id` |
| `notifications` | In-app notifications | `id` | `user_id` |
| `favorites` | Buyer → farmer favourites | `id` | `buyer_id`, `farmer_id` |
| `audit_logs` | Security/admin audit | `id` | `user_id` nullable |
| `event_logs` | Event-driven trail | `id` | optional actor/entity refs |

### 7.3 Relationships (Summary)

- One **user** may have many **crops** (as farmer).  
- One **order** links one **buyer**, one **farmer**, one **crop**.  
- One **transaction** typically links one **delivered** order (unique on `order_id`).  
- **Messages** link two **users**.  
- **Ratings** link **buyer**, **farmer**, optional **order**.  
- **Alerts** are authored by **admin** users; **notifications** target any **user**.

Full DDL: see **`database.sql`** in the project root.

---

## 8. Technology Stack (BSIT Capstone–Suitable)

| Layer | Recommendation |
|--------|------------------|
| Frontend | HTML5, CSS3, JavaScript (modular files); optional progressive enhancement |
| Backend | PHP 7.4+ with MySQLi |
| Database | MySQL / MariaDB (XAMPP) |
| Framework | Plain PHP endpoints or Laravel for larger teams |
| Authentication | PHP sessions + bcrypt; OTP via SMTP |
| Hosting | Shared PHP hosting, VPS, or institutional server with HTTPS |
| API | REST-like JSON over POST/GET |
| Security Tools | OWASP ZAP, PHPStan/Psalm or equivalent static checks |

---

## 9. System Architecture

| Layer | Responsibility |
|-------|----------------|
| Frontend Layer | UI shell, role-based navigation, calls JSON endpoints |
| Backend Layer | Authentication, authorisation, business rules, event logging |
| Database Layer | Relational integrity, constraints, indexes |
| API Layer | Stateless JSON operations behind session auth |
| Cloud Hosting | TLS termination, environment configuration, backups |
| Authentication Service | Login, OTP, lockout, session lifecycle |
| Notification Service | Persist in-app notifications; email for OTP/alerts (production) |

---

## 10. Testing Strategy

| Type | Focus |
|------|--------|
| Unit Testing | Pricing, inventory maths, OTP expiry, status transitions |
| Integration Testing | Order placement, cancellation stock restore, admin verify |
| User Acceptance Testing | Scripts per role (farmer, buyer, admin) |
| Security Testing | Auth bypass, IDOR on orders/messages, injection, XSS |
| Performance Testing | Browse/search under concurrent load |

---

## 11. Expected Output & Benefits

| Stakeholder | Benefit |
|-------------|---------|
| Farmers | Broader reach, structured orders, feedback, documented sales |
| Buyers | Transparent sourcing, tracking, direct communication |
| LGUs | Potential aggregate statistics for agricultural planning (privacy-compliant) |
| Agriculture Sector | Digital trace of flows and advisories |
| Philippine Economy | Efficiency in matching supply and demand at farm gate |

---

## 12. Future Enhancements

- AI-assisted crop price prediction  
- Personalised recommendations  
- GPS-enriched delivery tracking  
- AI chatbot (Filipino/English) for FAQs  
- IoT sensors for storage/cold chain telemetry  
- Native mobile applications (iOS/Android)  

---

## Alignment with Repository Layout

| Artifact | Location |
|----------|----------|
| Database DDL & seeds | `database.sql` |
| PHP API & includes | `php/`, `php/includes/` |
| Frontend | `html/en/index.html` (SPA), `css/`, `js/` |
| Configuration | `php/includes/db.php` |

---

*End of specification.*
