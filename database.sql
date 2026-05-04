-- =============================================
-- BUKID CONNECT — Full database schema
-- Import via phpMyAdmin (MySQL / MariaDB)
-- =============================================

CREATE DATABASE IF NOT EXISTS bukid_connect CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bukid_connect;

-- USERS (unified account; role = farmer | buyer | admin)
-- Logical "Farmers" and "Buyers" are views: WHERE role = 'farmer' / 'buyer'
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('farmer', 'buyer', 'admin') NOT NULL DEFAULT 'buyer',
    full_name VARCHAR(150),
    phone VARCHAR(20),
    address TEXT,
    farm_name VARCHAR(200) NULL,
    province VARCHAR(100) NULL,
    profile_photo VARCHAR(255),
    gov_id_verified TINYINT(1) DEFAULT 0,
    email_verified TINYINT(1) DEFAULT 0,
    otp_code VARCHAR(10) NULL,
    otp_expiry DATETIME NULL,
    is_active TINYINT(1) DEFAULT 1,
    failed_logins INT DEFAULT 0,
    lockout_until DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_role (role),
    INDEX idx_email (email)
) ENGINE=InnoDB;

-- Marketplace taxonomy (drives dropdowns + validation; extend via INSERT)
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

INSERT IGNORE INTO categories (name, sort_order) VALUES
('Vegetables', 10),
('Fruits', 20),
('Grains', 30),
('Livestock', 40),
('Herbs', 50),
('Root Crops', 60);

CREATE TABLE IF NOT EXISTS crops (
    id INT AUTO_INCREMENT PRIMARY KEY,
    farmer_id INT NOT NULL,
    crop_name VARCHAR(150) NOT NULL,
    category VARCHAR(100),
    description TEXT,
    price_per_unit DECIMAL(10,2) NOT NULL,
    unit VARCHAR(50) DEFAULT 'kg',
    quantity_available DECIMAL(10,2) NOT NULL,
    harvest_date DATE,
    location VARCHAR(200),
    image VARCHAR(255),
    status ENUM('available', 'pre-order', 'sold_out', 'inactive') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (farmer_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_farmer (farmer_id),
    INDEX idx_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    buyer_id INT NOT NULL,
    farmer_id INT NOT NULL,
    crop_id INT NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    total_price DECIMAL(10,2) NOT NULL,
    order_type ENUM('instant', 'pre-order') DEFAULT 'instant',
    delivery_type ENUM('pickup', 'delivery') DEFAULT 'pickup',
    delivery_address TEXT,
    status ENUM('pending', 'confirmed', 'packed', 'in_transit', 'delivered', 'cancelled') DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (buyer_id) REFERENCES users(id),
    FOREIGN KEY (farmer_id) REFERENCES users(id),
    FOREIGN KEY (crop_id) REFERENCES crops(id),
    INDEX idx_buyer (buyer_id),
    INDEX idx_farmer (farmer_id),
    INDEX idx_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    buyer_id INT NOT NULL,
    farmer_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(8) DEFAULT 'PHP',
    status ENUM('recorded', 'disputed') DEFAULT 'recorded',
    notes VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (buyer_id) REFERENCES users(id),
    FOREIGN KEY (farmer_id) REFERENCES users(id),
    UNIQUE KEY uq_order (order_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS farm_visits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    buyer_id INT NOT NULL,
    farmer_id INT NOT NULL,
    visit_date DATE NOT NULL,
    visit_time TIME NOT NULL,
    purpose VARCHAR(255),
    status ENUM('pending', 'approved', 'declined', 'rescheduled', 'completed') DEFAULT 'pending',
    group_size INT DEFAULT 1,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (buyer_id) REFERENCES users(id),
    FOREIGN KEY (farmer_id) REFERENCES users(id),
    INDEX idx_farmer_date (farmer_id, visit_date, visit_time)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id),
    FOREIGN KEY (receiver_id) REFERENCES users(id),
    INDEX idx_pair (sender_id, receiver_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    buyer_id INT NOT NULL,
    farmer_id INT NOT NULL,
    order_id INT NULL,
    rating INT NOT NULL,
    review TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (buyer_id) REFERENCES users(id),
    FOREIGN KEY (farmer_id) REFERENCES users(id),
    CONSTRAINT chk_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('weather', 'pest', 'market', 'general') DEFAULT 'general',
    target_province VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES users(id),
    INDEX idx_alert_province (target_province)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(64) NOT NULL,
    title VARCHAR(200) NOT NULL,
    body TEXT,
    link VARCHAR(500) NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_read (user_id, is_read)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    buyer_id INT NOT NULL,
    farmer_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (farmer_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_fav (buyer_id, farmer_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(255) NOT NULL,
    details TEXT,
    ip_address VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS event_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(100) NOT NULL,
    actor_user_id INT NULL,
    entity_type VARCHAR(64) NULL,
    entity_id INT NULL,
    payload_json TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type (event_type)
) ENGINE=InnoDB;

-- Manual phpMyAdmin accounts: use bcrypt (see INSERT below) or plain text is accepted on first login
--   and auto-upgraded by php/auth.php. Set email_verified = 1 or sign-in will require email OTP:
--   UPDATE users SET email_verified = 1 WHERE id IN (1,2,3);
--
-- Seed (passwords: Admin@123, Farmer@123, Buyer@123 — bcrypt)
INSERT IGNORE INTO users (id, username, email, password, role, full_name, is_active, gov_id_verified, email_verified, province, farm_name)
VALUES
(1, 'admin', 'admin@bukidconnect.ph', 'Admin@123', 'admin', 'System Administrator', 1, 1, 1, NULL, NULL),
(2, 'juan_farm', 'juan@bukidconnect.ph', 'Farmer@123', 'farmer', 'Juan dela Cruz', 1, 1, 1, 'Laguna', 'Dela Cruz Organic Farm'),
(3, 'maria_buyer', 'maria@bukidconnect.ph', 'Buyer@123', 'buyer', 'Maria Santos', 1, 0, 1, 'Metro Manila', NULL);

INSERT IGNORE INTO crops (farmer_id, crop_name, category, description, price_per_unit, unit, quantity_available, harvest_date, location, status)
VALUES
(2, 'Fresh Lettuce', 'Vegetables', 'Hydroponic romaine, crisp heads.', 120.00, 'kg', 80.00, DATE_ADD(CURDATE(), INTERVAL 5 DAY), 'Laguna, Calabarzon', 'available'),
(2, 'Cherry Tomatoes', 'Vegetables', 'Sweet grape tomatoes, farm pick.', 180.00, 'kg', 45.00, DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'Laguna, Calabarzon', 'available');

-- Extra demo accounts (passwords: Farmer@123, Buyer@123 — same bcrypt as seeds above)
INSERT IGNORE INTO users (id, username, email, password, role, full_name, is_active, gov_id_verified, email_verified, province, farm_name)
VALUES
(4, 'pedro_highland', 'pedro@bukidconnect.ph', 'Farmer@123', 'farmer', 'Pedro Baguio', 1, 1, 1, 'Benguet', 'Highland Roots Co-op'),
(5, 'carlos_resto', 'carlos@bukidconnect.ph', 'Buyer@123', 'buyer', 'Carlos Reyes', 1, 1, 1, 'Cebu City', NULL);

INSERT IGNORE INTO crops (farmer_id, crop_name, category, description, price_per_unit, unit, quantity_available, harvest_date, location, status)
VALUES
(4, 'Sagada Oranges', 'Fruits', 'Sweet highland oranges, hand-sorted.', 95.00, 'kg', 200.00, DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'Benguet, CAR', 'available'),
(4, 'Native Potatoes', 'Root Crops', 'Yellow flesh; great for restaurants and chips.', 55.00, 'kg', 350.00, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'Atok, Benguet', 'available'),
(4, 'Fresh Broccoli', 'Vegetables', 'Crisp crowns; cold-chain from harvest.', 220.00, 'kg', 60.00, DATE_ADD(CURDATE(), INTERVAL 4 DAY), 'La Trinidad, Benguet', 'pre-order'),
(2, 'Japanese Cucumber', 'Vegetables', 'Long variety, thin skin, fewer seeds.', 95.00, 'kg', 55.00, DATE_ADD(CURDATE(), INTERVAL 6 DAY), 'Laguna, Calabarzon', 'available');

INSERT IGNORE INTO ratings (buyer_id, farmer_id, order_id, rating, review)
VALUES
(3, 2, NULL, 5, 'Fresh lettuce and fair pricing. Malapit lang ang pickup.'),
(5, 4, NULL, 4, 'Oranges were sweet; sana mas madalas ang delivery sa Cebu.');

INSERT IGNORE INTO alerts (admin_id, title, message, type, target_province)
VALUES
(1, 'Market advisory — Cordillera routes', 'Check PAGASA and LGU advisories before scheduling bulk hauls. Secure tie-downs on mountain roads.', 'weather', NULL);

-- Logical role views (spec: farmers / buyers as views on unified users)
DROP VIEW IF EXISTS v_farmers;
DROP VIEW IF EXISTS v_buyers;
CREATE VIEW v_farmers AS
  SELECT * FROM users WHERE role = 'farmer';
CREATE VIEW v_buyers AS
  SELECT * FROM users WHERE role = 'buyer';

-- Upgrade path: if `alerts` already exists without target_province, run:
-- ALTER TABLE alerts ADD COLUMN target_province VARCHAR(100) NULL AFTER type;
