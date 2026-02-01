-- Add vehicle-specific fields to assets table
-- Run this migration on the production database

-- Add new vehicle fields if they don't exist
ALTER TABLE assets
ADD COLUMN IF NOT EXISTS vehicle_year YEAR DEFAULT NULL COMMENT 'Model year for vehicles',
ADD COLUMN IF NOT EXISTS engine_number VARCHAR(100) DEFAULT NULL COMMENT 'Engine number for vehicles',
ADD COLUMN IF NOT EXISTS transmission_type ENUM('MT', 'AT') DEFAULT NULL COMMENT 'Manual or Automatic transmission',
ADD COLUMN IF NOT EXISTS fuel_type ENUM('Petrol', 'Diesel') DEFAULT NULL COMMENT 'Fuel type',
ADD COLUMN IF NOT EXISTS drive_type ENUM('2WD', '4WD', '6WD') DEFAULT NULL COMMENT 'Drive configuration';

-- Create odometer readings table for tracking vehicle mileage history
CREATE TABLE IF NOT EXISTS odometer_readings (
    reading_id INT(11) NOT NULL AUTO_INCREMENT,
    asset_id INT(11) NOT NULL COMMENT 'Vehicle asset ID',
    reading_km INT(11) NOT NULL COMMENT 'Odometer reading in kilometers',
    reading_date DATE NOT NULL COMMENT 'Date of reading',
    notes VARCHAR(255) DEFAULT NULL COMMENT 'Optional notes (e.g., service, trip)',
    recorded_by INT(11) DEFAULT NULL COMMENT 'User who recorded the reading',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (reading_id),
    FOREIGN KEY (asset_id) REFERENCES assets(asset_id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_asset_date (asset_id, reading_date DESC),
    INDEX idx_reading_date (reading_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add index for vehicle-specific queries
CREATE INDEX IF NOT EXISTS idx_vehicle_year ON assets(vehicle_year);
