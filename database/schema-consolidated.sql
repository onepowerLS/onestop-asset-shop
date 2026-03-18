-- ============================================================
-- OneStop Asset Shop - Consolidated Database Schema
-- ============================================================
-- Replaces: npower5_asset_management + 15+ Google Sheets
-- Supports: Lesotho, Zambia, Benin operations
-- Features: QR codes, tablet workflows, multi-country inventory
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ============================================================
-- COUNTRIES & LOCATIONS (Multi-country support)
-- ============================================================

CREATE TABLE `countries` (
  `country_id` int(11) NOT NULL AUTO_INCREMENT,
  `country_code` varchar(3) NOT NULL UNIQUE COMMENT 'ISO code: LSO, ZMB, BEN',
  `country_name` varchar(100) NOT NULL,
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT current_timestamp(),
  PRIMARY KEY (`country_id`),
  INDEX `idx_country_code` (`country_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `countries` (`country_code`, `country_name`) VALUES
('LSO', 'Lesotho'),
('ZMB', 'Zambia'),
('BEN', 'Benin');

-- ============================================================
-- LOCATIONS (Hierarchical: Country > Region > Site > Building > Room)
-- ============================================================

CREATE TABLE `locations` (
  `location_id` int(11) NOT NULL AUTO_INCREMENT,
  `country_id` int(11) NOT NULL,
  `parent_location_id` int(11) DEFAULT NULL COMMENT 'For hierarchical structure',
  `location_code` varchar(50) NOT NULL UNIQUE COMMENT 'Unique code like LSO-MAS-001',
  `location_name` varchar(255) NOT NULL,
  `location_type` enum('Country','Region','Site','Building','Room','Cabinet','Other') NOT NULL,
  `address` text DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT current_timestamp(),
  `updated_at` timestamp DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`location_id`),
  FOREIGN KEY (`country_id`) REFERENCES `countries`(`country_id`) ON DELETE RESTRICT,
  FOREIGN KEY (`parent_location_id`) REFERENCES `locations`(`location_id`) ON DELETE SET NULL,
  INDEX `idx_country` (`country_id`),
  INDEX `idx_location_code` (`location_code`),
  INDEX `idx_parent` (`parent_location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ITEM CLASSIFICATION (Industry-standard 4-tier model)
-- ============================================================
-- item_class: top-level classification per IAS 16 / IAS 2
--   FixedAsset  = PP&E, useful life >1yr, capitalized & depreciated
--   Material    = consumed in construction/installation projects
--   Consumable  = operational supplies, low value, expensed on use
--   Inventory   = finished goods, spare parts, held for deployment
-- ============================================================

-- ============================================================
-- CATEGORIES (Hierarchical: item_class > category > subcategory)
-- ============================================================

CREATE TABLE `categories` (
  `category_id` int(11) NOT NULL AUTO_INCREMENT,
  `parent_category_id` int(11) DEFAULT NULL COMMENT 'For hierarchical categories',
  `category_code` varchar(50) NOT NULL UNIQUE COMMENT 'Like FA-VEH-001, MAT-ELE-002',
  `category_name` varchar(255) NOT NULL,
  `item_class` enum('FixedAsset','Material','Consumable','Inventory') NOT NULL,
  `department_scope` enum('RET','FAC','O&M','General','All') DEFAULT 'All' COMMENT 'Originating department for legacy mapping',
  `capitalization_threshold` decimal(10,2) DEFAULT NULL COMMENT 'Min value to capitalize as FixedAsset (currency-neutral)',
  `useful_life_years` int(11) DEFAULT NULL COMMENT 'Expected useful life for depreciation (FixedAsset only)',
  `depreciation_method` enum('StraightLine','DecliningBalance','UnitsOfProduction','None') DEFAULT 'None',
  `reorder_enabled` tinyint(1) DEFAULT 0 COMMENT 'Whether reorder-point tracking applies',
  `description` text DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT current_timestamp(),
  PRIMARY KEY (`category_id`),
  FOREIGN KEY (`parent_category_id`) REFERENCES `categories`(`category_id`) ON DELETE SET NULL,
  INDEX `idx_category_code` (`category_code`),
  INDEX `idx_item_class` (`item_class`),
  INDEX `idx_department_scope` (`department_scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEED CATEGORIES (Standard hierarchy for 1PWR)
-- ============================================================

-- Fixed Assets
INSERT INTO `categories` (`category_code`, `category_name`, `item_class`, `department_scope`, `useful_life_years`, `depreciation_method`, `description`) VALUES
('FA-VEH', 'Vehicles', 'FixedAsset', 'All', 10, 'StraightLine', 'Cars, trucks, motorbikes'),
('FA-HVY', 'Heavy Equipment', 'FixedAsset', 'All', 15, 'StraightLine', 'Generators, compressors, cranes'),
('FA-TLM', 'Tools & Machinery', 'FixedAsset', 'All', 7, 'StraightLine', 'Welding machines, power tools above capitalization threshold'),
('FA-ITE', 'IT Equipment', 'FixedAsset', 'All', 4, 'StraightLine', 'Laptops, servers, networking, tablets'),
('FA-FNF', 'Furniture & Fixtures', 'FixedAsset', 'All', 10, 'StraightLine', 'Desks, chairs, shelving, signage'),
('FA-INF', 'Installed Infrastructure', 'FixedAsset', 'All', 25, 'StraightLine', 'Commissioned mini-grid components, installed meters, distribution equipment');

-- Materials (construction/installation inputs)
INSERT INTO `categories` (`category_code`, `category_name`, `item_class`, `department_scope`, `description`) VALUES
('MAT-ELE', 'Electrical', 'Material', 'RET', 'Wire, conduit, connectors, breakers, junction boxes'),
('MAT-STR', 'Structural', 'Material', 'RET', 'Utility poles, brackets, foundations, mounting hardware'),
('MAT-SOL', 'Solar', 'Material', 'RET', 'Panels, inverters, charge controllers, combiners'),
('MAT-BAT', 'Battery Storage', 'Material', 'RET', 'Battery cells, racks, BMS components'),
('MAT-FAC', 'Facilities', 'Material', 'FAC', 'Building materials, plumbing, fencing, painting'),
('MAT-ONM', 'O&M Components', 'Material', 'O&M', 'Pre-assembled replacement modules for field maintenance');

-- Consumables (operational supplies)
INSERT INTO `categories` (`category_code`, `category_name`, `item_class`, `department_scope`, `reorder_enabled`, `description`) VALUES
('CON-SAF', 'Safety & PPE', 'Consumable', 'All', 1, 'Hard hats, gloves, vests, goggles, harnesses'),
('CON-OFC', 'Office Supplies', 'Consumable', 'All', 1, 'Paper, toner, pens, labels'),
('CON-MNT', 'Maintenance Supplies', 'Consumable', 'O&M', 1, 'Lubricants, fasteners, tape, cable ties, sealant'),
('CON-CLN', 'Cleaning Supplies', 'Consumable', 'FAC', 1, 'Detergent, brooms, waste bags'),
('CON-HND', 'Hand Tools', 'Consumable', 'All', 0, 'Screwdrivers, pliers, wrenches below capitalization threshold');

-- Inventory (finished goods & spare parts)
INSERT INTO `categories` (`category_code`, `category_name`, `item_class`, `department_scope`, `reorder_enabled`, `description`) VALUES
('INV-MTR', 'Meters', 'Inventory', 'General', 1, 'Produced/procured meters for customer deployment'),
('INV-RDB', 'Ready Boards', 'Inventory', 'General', 1, 'Pre-assembled customer connection boards'),
('INV-SPR', 'Spare Parts', 'Inventory', 'O&M', 1, 'Replacement components for installed infrastructure'),
('INV-WIP', 'Work-in-Progress', 'Inventory', 'General', 0, 'Partially assembled items awaiting completion'),
('INV-KIT', 'Kits & Assemblies', 'Inventory', 'RET', 1, 'Pre-packaged installation kits for field deployment');

-- ============================================================
-- ASSETS (Consolidated master table)
-- ============================================================

CREATE TABLE `assets` (
  `asset_id` int(11) NOT NULL AUTO_INCREMENT,
  `qr_code_id` varchar(100) UNIQUE COMMENT 'Unique QR identifier for scanning',
  `asset_tag` varchar(100) UNIQUE COMMENT 'Human-readable tag like 1PWR-FA-001234',
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `item_class` enum('FixedAsset','Material','Consumable','Inventory') NOT NULL COMMENT 'Denormalized from category for fast filtering',
  `category_id` int(11) DEFAULT NULL,
  `serial_number` varchar(250) DEFAULT NULL,
  `manufacturer` varchar(255) DEFAULT NULL,
  `model` varchar(255) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `purchase_price` decimal(10,2) DEFAULT NULL,
  `current_value` decimal(10,2) DEFAULT NULL COMMENT 'Net book value for FixedAssets, unit cost for others',
  `salvage_value` decimal(10,2) DEFAULT NULL COMMENT 'Residual value at end of useful life (FixedAsset only)',
  `warranty_expiry` date DEFAULT NULL,
  `condition_status` enum('New','Good','Fair','Poor','Damaged','Retired') DEFAULT 'Good',
  `status` enum('Available','Allocated','CheckedOut','InProject','Consumed','Deployed','Missing','WrittenOff','Retired') DEFAULT 'Available',
  `location_id` int(11) DEFAULT NULL COMMENT 'Current physical location',
  `country_id` int(11) NOT NULL COMMENT 'Which country this item belongs to',
  `quantity` int(11) DEFAULT 1 COMMENT 'For bulk/countable items (Materials, Consumables, Inventory)',
  `unit_of_measure` varchar(50) DEFAULT 'EA' COMMENT 'EA, KG, M, L, etc.',
  `notes` text DEFAULT NULL,
  `created_at` timestamp DEFAULT current_timestamp(),
  `updated_at` timestamp DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`asset_id`),
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`category_id`) ON DELETE SET NULL,
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`location_id`) ON DELETE SET NULL,
  FOREIGN KEY (`country_id`) REFERENCES `countries`(`country_id`) ON DELETE RESTRICT,
  INDEX `idx_qr_code` (`qr_code_id`),
  INDEX `idx_asset_tag` (`asset_tag`),
  INDEX `idx_item_class` (`item_class`),
  INDEX `idx_status` (`status`),
  INDEX `idx_country` (`country_id`),
  INDEX `idx_location` (`location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- INVENTORY LEVELS (Stock tracking per country/location)
-- ============================================================

CREATE TABLE `inventory_levels` (
  `inventory_id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  `country_id` int(11) NOT NULL,
  `quantity_on_hand` int(11) NOT NULL DEFAULT 0,
  `quantity_allocated` int(11) NOT NULL DEFAULT 0,
  `quantity_available` int(11) GENERATED ALWAYS AS (`quantity_on_hand` - `quantity_allocated`) STORED,
  `reorder_level` int(11) DEFAULT NULL COMMENT 'Alert when stock falls below this',
  `last_counted_at` timestamp NULL DEFAULT NULL,
  `last_counted_by` int(11) DEFAULT NULL COMMENT 'User ID who did last stock take',
  `updated_at` timestamp DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`inventory_id`),
  FOREIGN KEY (`asset_id`) REFERENCES `assets`(`asset_id`) ON DELETE CASCADE,
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`location_id`) ON DELETE CASCADE,
  FOREIGN KEY (`country_id`) REFERENCES `countries`(`country_id`) ON DELETE RESTRICT,
  UNIQUE KEY `unique_asset_location` (`asset_id`, `location_id`),
  INDEX `idx_country_location` (`country_id`, `location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- EMPLOYEES (From existing system)
-- ============================================================

CREATE TABLE `employees` (
  `employee_id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `country_id` int(11) NOT NULL COMMENT 'Which country they work in',
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT current_timestamp(),
  PRIMARY KEY (`employee_id`),
  FOREIGN KEY (`country_id`) REFERENCES `countries`(`country_id`) ON DELETE RESTRICT,
  INDEX `idx_country` (`country_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DEPARTMENTS
-- ============================================================

CREATE TABLE `departments` (
  `department_id` int(11) NOT NULL AUTO_INCREMENT,
  `short_name` varchar(50) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `country_id` int(11) DEFAULT NULL COMMENT 'NULL = applies to all countries',
  `active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`department_id`),
  FOREIGN KEY (`country_id`) REFERENCES `countries`(`country_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TRANSACTIONS (Unified audit trail for all actions)
-- ============================================================

CREATE TABLE `transactions` (
  `transaction_id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_type` enum('CheckOut','CheckIn','StockIngestion','StockTake','Transfer','Allocation','Return','WriteOff','QRScan','Consume','Deploy') NOT NULL,
  `asset_id` int(11) NOT NULL,
  `quantity` int(11) DEFAULT 1,
  `from_location_id` int(11) DEFAULT NULL,
  `to_location_id` int(11) DEFAULT NULL,
  `from_country_id` int(11) DEFAULT NULL,
  `to_country_id` int(11) DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL COMMENT 'Who received/returned the asset',
  `performed_by` int(11) NOT NULL COMMENT 'User ID who performed the action',
  `qr_code_scanned` varchar(100) DEFAULT NULL COMMENT 'QR code that triggered this transaction',
  `device_type` enum('Desktop','Tablet','Mobile') DEFAULT 'Desktop' COMMENT 'How the transaction was performed',
  `notes` text DEFAULT NULL,
  `transaction_date` timestamp DEFAULT current_timestamp(),
  PRIMARY KEY (`transaction_id`),
  FOREIGN KEY (`asset_id`) REFERENCES `assets`(`asset_id`) ON DELETE RESTRICT,
  FOREIGN KEY (`from_location_id`) REFERENCES `locations`(`location_id`) ON DELETE SET NULL,
  FOREIGN KEY (`to_location_id`) REFERENCES `locations`(`location_id`) ON DELETE SET NULL,
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`employee_id`) ON DELETE SET NULL,
  INDEX `idx_transaction_type` (`transaction_type`),
  INDEX `idx_transaction_date` (`transaction_date`),
  INDEX `idx_asset` (`asset_id`),
  INDEX `idx_qr_scan` (`qr_code_scanned`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ALLOCATIONS (Check-out/Check-in tracking)
-- ============================================================

CREATE TABLE `allocations` (
  `allocation_id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `allocated_by` int(11) NOT NULL COMMENT 'User ID who performed allocation',
  `allocation_date` timestamp DEFAULT current_timestamp(),
  `expected_return_date` date DEFAULT NULL,
  `actual_return_date` timestamp NULL DEFAULT NULL,
  `status` enum('Active','Returned','Overdue') DEFAULT 'Active',
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`allocation_id`),
  FOREIGN KEY (`asset_id`) REFERENCES `assets`(`asset_id`) ON DELETE RESTRICT,
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`employee_id`) ON DELETE RESTRICT,
  INDEX `idx_status` (`status`),
  INDEX `idx_employee` (`employee_id`),
  INDEX `idx_asset` (`asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- REQUESTS (Replaces Google Forms: RET Items, FAC Items, Meters, etc.)
-- ============================================================

CREATE TABLE `requests` (
  `request_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_number` varchar(50) UNIQUE NOT NULL COMMENT 'Auto-generated like REQ-2026-001',
  `item_class` enum('FixedAsset','Material','Consumable','Inventory') NOT NULL COMMENT 'What class of item is being requested',
  `department_scope` enum('RET','FAC','O&M','General','All') DEFAULT 'General' COMMENT 'Requesting department context',
  `requested_by` int(11) NOT NULL COMMENT 'Employee ID',
  `requested_for_country` int(11) NOT NULL,
  `requested_for_location` int(11) DEFAULT NULL,
  `priority` enum('Low','Normal','High','Urgent') DEFAULT 'Normal',
  `status` enum('Draft','Submitted','Approved','Rejected','Fulfilled','Cancelled') DEFAULT 'Draft',
  `description` text DEFAULT NULL,
  `requested_date` timestamp DEFAULT current_timestamp(),
  `required_date` date DEFAULT NULL,
  `fulfilled_date` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`request_id`),
  FOREIGN KEY (`requested_by`) REFERENCES `employees`(`employee_id`) ON DELETE RESTRICT,
  FOREIGN KEY (`requested_for_country`) REFERENCES `countries`(`country_id`) ON DELETE RESTRICT,
  FOREIGN KEY (`requested_for_location`) REFERENCES `locations`(`location_id`) ON DELETE SET NULL,
  INDEX `idx_request_number` (`request_number`),
  INDEX `idx_item_class` (`item_class`),
  INDEX `idx_status` (`status`),
  INDEX `idx_country` (`requested_for_country`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- REQUEST ITEMS (Line items for each request)
-- ============================================================

CREATE TABLE `request_items` (
  `request_item_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `asset_id` int(11) DEFAULT NULL COMMENT 'If requesting specific asset',
  `category_id` int(11) DEFAULT NULL COMMENT 'If requesting by category',
  `item_description` varchar(255) NOT NULL,
  `quantity_requested` int(11) NOT NULL DEFAULT 1,
  `quantity_fulfilled` int(11) DEFAULT 0,
  `unit_of_measure` varchar(50) DEFAULT 'EA',
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`request_item_id`),
  FOREIGN KEY (`request_id`) REFERENCES `requests`(`request_id`) ON DELETE CASCADE,
  FOREIGN KEY (`asset_id`) REFERENCES `assets`(`asset_id`) ON DELETE SET NULL,
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`category_id`) ON DELETE SET NULL,
  INDEX `idx_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- QR CODE LABELS (Tracking printed labels)
-- ============================================================

CREATE TABLE `qr_labels` (
  `label_id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) NOT NULL,
  `qr_code_id` varchar(100) NOT NULL UNIQUE,
  `label_printed_at` timestamp NULL DEFAULT NULL,
  `printed_by` int(11) DEFAULT NULL COMMENT 'User ID',
  `printer_model` varchar(100) DEFAULT 'Brother PT-P710BT',
  `label_format` varchar(50) DEFAULT 'Standard',
  `last_scanned_at` timestamp NULL DEFAULT NULL,
  `scan_count` int(11) DEFAULT 0,
  PRIMARY KEY (`label_id`),
  FOREIGN KEY (`asset_id`) REFERENCES `assets`(`asset_id`) ON DELETE CASCADE,
  INDEX `idx_qr_code` (`qr_code_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- STOCK TAKES (Guided audit mode for tablets)
-- ============================================================

CREATE TABLE `stock_takes` (
  `stock_take_id` int(11) NOT NULL AUTO_INCREMENT,
  `stock_take_number` varchar(50) UNIQUE NOT NULL COMMENT 'ST-2026-001',
  `location_id` int(11) NOT NULL,
  `country_id` int(11) NOT NULL,
  `conducted_by` int(11) NOT NULL COMMENT 'User ID',
  `started_at` timestamp DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  `status` enum('InProgress','Completed','Cancelled') DEFAULT 'InProgress',
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`stock_take_id`),
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`location_id`) ON DELETE RESTRICT,
  FOREIGN KEY (`country_id`) REFERENCES `countries`(`country_id`) ON DELETE RESTRICT,
  INDEX `idx_status` (`status`),
  INDEX `idx_location` (`location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stock_take_items` (
  `stock_take_item_id` int(11) NOT NULL AUTO_INCREMENT,
  `stock_take_id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `expected_quantity` int(11) DEFAULT 0,
  `counted_quantity` int(11) NOT NULL,
  `variance` int(11) GENERATED ALWAYS AS (`counted_quantity` - `expected_quantity`) STORED,
  `qr_code_scanned` varchar(100) DEFAULT NULL,
  `scanned_at` timestamp DEFAULT current_timestamp(),
  PRIMARY KEY (`stock_take_item_id`),
  FOREIGN KEY (`stock_take_id`) REFERENCES `stock_takes`(`stock_take_id`) ON DELETE CASCADE,
  FOREIGN KEY (`asset_id`) REFERENCES `assets`(`asset_id`) ON DELETE RESTRICT,
  INDEX `idx_stock_take` (`stock_take_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- USERS (System users - separate from employees)
-- ============================================================

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL UNIQUE,
  `email` varchar(255) NOT NULL UNIQUE,
  `password_hash` varchar(255) NOT NULL,
  `employee_id` int(11) DEFAULT NULL COMMENT 'Link to employee if applicable',
  `role` enum('Admin','Manager','Operator','Viewer') DEFAULT 'Operator',
  `country_access` varchar(20) DEFAULT NULL COMMENT 'Comma-separated country codes, NULL = all',
  `active` tinyint(1) DEFAULT 1,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`),
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`employee_id`) ON DELETE SET NULL,
  INDEX `idx_username` (`username`),
  INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- LEGACY MAPPING (category_type -> item_class + department_scope)
-- ============================================================
-- Use this reference when migrating existing Firestore data:
--
--   OLD category_type  ->  NEW item_class    department_scope
--   ─────────────────      ──────────────    ────────────────
--   RET                    Material          RET
--   FAC                    Material          FAC
--   O&M                    Consumable/       O&M
--                          Material (check)
--   General                (varies)          General
--   Meters                 Inventory         General
--   ReadyBoards            Inventory         General
--   Tools                  FixedAsset or     All
--                          Consumable (by value)
--   Other                  (manual review)   General
--
-- Decision rule for Tools:
--   IF purchase_price >= capitalization_threshold THEN FixedAsset
--   ELSE Consumable
-- ============================================================

-- ============================================================
-- END OF SCHEMA
-- ============================================================
