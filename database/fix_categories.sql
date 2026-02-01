-- ============================================================
-- Fix Categories: Disaggregate from General to proper types
-- ============================================================
-- This script updates category_type based on category_name patterns
-- Run this on the production database to fix the monolithic "General" issue

-- First, let's see what we have
SELECT category_id, category_code, category_name, category_type 
FROM categories 
ORDER BY category_name;

-- ============================================================
-- RET (Renewable Energy Technology) Materials
-- Solar panels, inverters, batteries, charge controllers, cables, connectors
-- ============================================================
UPDATE categories SET 
    category_type = 'RET',
    category_code = CONCAT('RET-', LPAD(category_id, 3, '0'))
WHERE category_type = 'General' AND (
    category_name LIKE '%solar%' OR
    category_name LIKE '%panel%' OR
    category_name LIKE '%inverter%' OR
    category_name LIKE '%battery%' OR
    category_name LIKE '%batteries%' OR
    category_name LIKE '%charge controller%' OR
    category_name LIKE '%mppt%' OR
    category_name LIKE '%pv%' OR
    category_name LIKE '%photovoltaic%' OR
    category_name LIKE '%module%' OR
    category_name LIKE '%dc cable%' OR
    category_name LIKE '%solar cable%' OR
    category_name LIKE '%mc4%' OR
    category_name LIKE '%connector%' OR
    category_name LIKE '%combiner%' OR
    category_name LIKE '%junction box%' OR
    category_name LIKE '%mounting%' OR
    category_name LIKE '%racking%' OR
    category_name LIKE '%tracker%' OR
    category_name LIKE '%string%' OR
    category_name LIKE '%array%'
);

-- ============================================================
-- FAC (Facilities) Materials
-- Building materials, furniture, office equipment, fixtures
-- ============================================================
UPDATE categories SET 
    category_type = 'FAC',
    category_code = CONCAT('FAC-', LPAD(category_id, 3, '0'))
WHERE category_type = 'General' AND (
    category_name LIKE '%furniture%' OR
    category_name LIKE '%desk%' OR
    category_name LIKE '%chair%' OR
    category_name LIKE '%table%' OR
    category_name LIKE '%cabinet%' OR
    category_name LIKE '%shelf%' OR
    category_name LIKE '%shelving%' OR
    category_name LIKE '%office%' OR
    category_name LIKE '%building%' OR
    category_name LIKE '%fixture%' OR
    category_name LIKE '%lighting%' OR
    category_name LIKE '%light%' OR
    category_name LIKE '%lamp%' OR
    category_name LIKE '%air condition%' OR
    category_name LIKE '%hvac%' OR
    category_name LIKE '%fan%' OR
    category_name LIKE '%door%' OR
    category_name LIKE '%window%' OR
    category_name LIKE '%lock%' OR
    category_name LIKE '%security%' OR
    category_name LIKE '%cctv%' OR
    category_name LIKE '%camera%' OR
    category_name LIKE '%alarm%' OR
    category_name LIKE '%fence%' OR
    category_name LIKE '%gate%'
);

-- ============================================================
-- O&M (Operations & Maintenance) Materials
-- Spare parts, consumables, maintenance supplies
-- ============================================================
UPDATE categories SET 
    category_type = 'O&M',
    category_code = CONCAT('O&M-', LPAD(category_id, 3, '0'))
WHERE category_type = 'General' AND (
    category_name LIKE '%spare%' OR
    category_name LIKE '%replacement%' OR
    category_name LIKE '%consumable%' OR
    category_name LIKE '%maintenance%' OR
    category_name LIKE '%repair%' OR
    category_name LIKE '%fuse%' OR
    category_name LIKE '%breaker%' OR
    category_name LIKE '%circuit%' OR
    category_name LIKE '%switch%' OR
    category_name LIKE '%relay%' OR
    category_name LIKE '%contactor%' OR
    category_name LIKE '%terminal%' OR
    category_name LIKE '%lug%' OR
    category_name LIKE '%bolt%' OR
    category_name LIKE '%nut%' OR
    category_name LIKE '%screw%' OR
    category_name LIKE '%washer%' OR
    category_name LIKE '%gasket%' OR
    category_name LIKE '%seal%' OR
    category_name LIKE '%lubricant%' OR
    category_name LIKE '%grease%' OR
    category_name LIKE '%oil%' OR
    category_name LIKE '%filter%' OR
    category_name LIKE '%cleaning%'
);

-- ============================================================
-- Meters
-- Energy meters, prepaid meters, smart meters
-- ============================================================
UPDATE categories SET 
    category_type = 'Meters',
    category_code = CONCAT('MET-', LPAD(category_id, 3, '0'))
WHERE category_type = 'General' AND (
    category_name LIKE '%meter%' OR
    category_name LIKE '%prepaid%' OR
    category_name LIKE '%smart meter%' OR
    category_name LIKE '%energy meter%' OR
    category_name LIKE '%kwh%' OR
    category_name LIKE '%metering%' OR
    category_name LIKE '%ct%' OR
    category_name LIKE '%current transformer%'
);

-- ============================================================
-- ReadyBoards
-- Distribution boards, ready boards, electrical panels
-- ============================================================
UPDATE categories SET 
    category_type = 'ReadyBoards',
    category_code = CONCAT('RB-', LPAD(category_id, 3, '0'))
WHERE category_type = 'General' AND (
    category_name LIKE '%ready board%' OR
    category_name LIKE '%readyboard%' OR
    category_name LIKE '%distribution board%' OR
    category_name LIKE '%db box%' OR
    category_name LIKE '%panel board%' OR
    category_name LIKE '%electrical panel%' OR
    category_name LIKE '%load center%' OR
    category_name LIKE '%consumer unit%'
);

-- ============================================================
-- Tools
-- Hand tools, power tools, testing equipment
-- ============================================================
UPDATE categories SET 
    category_type = 'Tools',
    category_code = CONCAT('TOOL-', LPAD(category_id, 3, '0'))
WHERE category_type = 'General' AND (
    category_name LIKE '%tool%' OR
    category_name LIKE '%drill%' OR
    category_name LIKE '%saw%' OR
    category_name LIKE '%hammer%' OR
    category_name LIKE '%screwdriver%' OR
    category_name LIKE '%wrench%' OR
    category_name LIKE '%plier%' OR
    category_name LIKE '%cutter%' OR
    category_name LIKE '%crimper%' OR
    category_name LIKE '%stripper%' OR
    category_name LIKE '%multimeter%' OR
    category_name LIKE '%tester%' OR
    category_name LIKE '%clamp meter%' OR
    category_name LIKE '%oscilloscope%' OR
    category_name LIKE '%analyzer%' OR
    category_name LIKE '%measuring%' OR
    category_name LIKE '%ladder%' OR
    category_name LIKE '%scaffold%' OR
    category_name LIKE '%harness%' OR
    category_name LIKE '%safety%' OR
    category_name LIKE '%ppe%' OR
    category_name LIKE '%helmet%' OR
    category_name LIKE '%glove%' OR
    category_name LIKE '%boot%'
);

-- ============================================================
-- Verify the changes
-- ============================================================
SELECT category_type, COUNT(*) as count 
FROM categories 
GROUP BY category_type 
ORDER BY category_type;

-- Show remaining General categories that may need manual review
SELECT category_id, category_code, category_name, category_type 
FROM categories 
WHERE category_type = 'General'
ORDER BY category_name;
