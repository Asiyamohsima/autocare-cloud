-- =====================================================
-- AutoCare Cloud - Database Schema
-- Database: autocare_cloud
-- =====================================================

CREATE DATABASE IF NOT EXISTS autocare_cloud
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE autocare_cloud;

-- -----------------------------------------------------
-- vehicles
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS vehicles (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  registration_number VARCHAR(20)  NOT NULL,
  make                VARCHAR(50)  NOT NULL,
  model               VARCHAR(50)  NOT NULL,
  variant             VARCHAR(50)  DEFAULT NULL,
  vehicle_type        ENUM('Car','Bike','Scooter','SUV','Other') NOT NULL DEFAULT 'Car',
  manufacturing_year  SMALLINT UNSIGNED DEFAULT NULL,
  purchase_date       DATE         DEFAULT NULL,
  purchase_price      DECIMAL(12,2) DEFAULT NULL,
  current_odometer    INT UNSIGNED NOT NULL DEFAULT 0,
  fuel_type           ENUM('Petrol','Diesel','Electric','Hybrid','CNG') NOT NULL DEFAULT 'Petrol',
  color               VARCHAR(30)  DEFAULT NULL,
  notes               TEXT         DEFAULT NULL,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vehicles_registration (registration_number),
  KEY idx_vehicles_make_model (make, model)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- services
-- total_cost is calculated by MySQL (labor + parts)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS services (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  vehicle_id            INT UNSIGNED NOT NULL,
  service_date          DATE         NOT NULL,
  odometer_reading      INT UNSIGNED NOT NULL,
  service_type          ENUM('General Service','Oil Change','Brake Service','Tire Replacement',
                             'Battery Replacement','AC Service','Engine Repair',
                             'Electrical Repair','Other') NOT NULL DEFAULT 'General Service',
  description           TEXT         DEFAULT NULL,
  service_center        VARCHAR(100) DEFAULT NULL,
  labor_cost            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  parts_cost            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total_cost            DECIMAL(10,2) GENERATED ALWAYS AS (labor_cost + parts_cost) STORED,
  next_service_date     DATE         DEFAULT NULL,
  next_service_odometer INT UNSIGNED DEFAULT NULL,
  reminder_done         TINYINT(1)   NOT NULL DEFAULT 0,
  notes                 TEXT         DEFAULT NULL,
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_services_vehicle_date (vehicle_id, service_date),
  KEY idx_services_next_date (next_service_date),
  CONSTRAINT fk_services_vehicle FOREIGN KEY (vehicle_id)
    REFERENCES vehicles (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_services_costs CHECK (labor_cost >= 0 AND parts_cost >= 0)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- expenses
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS expenses (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  vehicle_id     INT UNSIGNED NOT NULL,
  expense_date   DATE         NOT NULL,
  category       ENUM('Maintenance','Fuel','Insurance','Repairs','Parking','Toll',
                      'Accessories','Cleaning','Other') NOT NULL DEFAULT 'Other',
  amount         DECIMAL(10,2) NOT NULL,
  description    VARCHAR(255) DEFAULT NULL,
  payment_method ENUM('Cash','Card','UPI','Other') NOT NULL DEFAULT 'Cash',
  notes          TEXT         DEFAULT NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_expenses_vehicle_date (vehicle_id, expense_date),
  KEY idx_expenses_category (category),
  CONSTRAINT fk_expenses_vehicle FOREIGN KEY (vehicle_id)
    REFERENCES vehicles (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_expenses_amount CHECK (amount >= 0)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- fuel_logs
-- total_cost is calculated by MySQL (quantity x price)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS fuel_logs (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  vehicle_id       INT UNSIGNED NOT NULL,
  fuel_date        DATE         NOT NULL,
  odometer_reading INT UNSIGNED NOT NULL,
  fuel_quantity    DECIMAL(8,2) NOT NULL,
  price_per_liter  DECIMAL(8,2) NOT NULL,
  total_cost       DECIMAL(10,2) GENERATED ALWAYS AS (ROUND(fuel_quantity * price_per_liter, 2)) STORED,
  fuel_station     VARCHAR(100) DEFAULT NULL,
  is_full_tank     TINYINT(1)   NOT NULL DEFAULT 1,
  notes            TEXT         DEFAULT NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fuel_vehicle_odometer (vehicle_id, odometer_reading),
  KEY idx_fuel_date (fuel_date),
  CONSTRAINT fk_fuel_vehicle FOREIGN KEY (vehicle_id)
    REFERENCES vehicles (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_fuel_values CHECK (fuel_quantity > 0 AND price_per_liter >= 0)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- documents (metadata only - files live in Amazon S3)
-- ON DELETE RESTRICT: a vehicle cannot be deleted while it
-- still has documents, so S3 files are never orphaned.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS documents (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  vehicle_id    INT UNSIGNED NOT NULL,
  document_name VARCHAR(150) NOT NULL,
  document_type ENUM('Insurance','Registration','Pollution Certificate','Driving License',
                     'Warranty','Service Bill','Purchase Invoice','Vehicle Image','Other')
                NOT NULL DEFAULT 'Other',
  s3_key        VARCHAR(500) NOT NULL,
  file_size     INT UNSIGNED NOT NULL DEFAULT 0,
  file_type     VARCHAR(100) NOT NULL,
  expiry_date   DATE         DEFAULT NULL,
  uploaded_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_documents_s3_key (s3_key),
  KEY idx_documents_vehicle_type (vehicle_id, document_type),
  KEY idx_documents_expiry (expiry_date),
  CONSTRAINT fk_documents_vehicle FOREIGN KEY (vehicle_id)
    REFERENCES vehicles (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- insurance
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS insurance (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  vehicle_id    INT UNSIGNED NOT NULL,
  provider      VARCHAR(100) NOT NULL,
  policy_number VARCHAR(50)  NOT NULL,
  policy_type   ENUM('Comprehensive','Third Party','Other') NOT NULL DEFAULT 'Comprehensive',
  start_date    DATE         NOT NULL,
  expiry_date   DATE         NOT NULL,
  premium       DECIMAL(10,2) DEFAULT NULL,
  notes         TEXT         DEFAULT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_insurance_vehicle (vehicle_id),
  KEY idx_insurance_expiry (expiry_date),
  CONSTRAINT fk_insurance_vehicle FOREIGN KEY (vehicle_id)
    REFERENCES vehicles (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_insurance_dates CHECK (expiry_date >= start_date)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- warranty
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS warranty (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  vehicle_id  INT UNSIGNED NOT NULL,
  provider    VARCHAR(100) NOT NULL,
  start_date  DATE         NOT NULL,
  expiry_date DATE         NOT NULL,
  coverage    TEXT         DEFAULT NULL,
  notes       TEXT         DEFAULT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_warranty_vehicle (vehicle_id),
  KEY idx_warranty_expiry (expiry_date),
  CONSTRAINT fk_warranty_vehicle FOREIGN KEY (vehicle_id)
    REFERENCES vehicles (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_warranty_dates CHECK (expiry_date >= start_date)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- registration
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS registration (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  vehicle_id             INT UNSIGNED NOT NULL,
  registration_number    VARCHAR(20)  NOT NULL,
  registration_date      DATE         DEFAULT NULL,
  registration_expiry    DATE         DEFAULT NULL,
  registration_authority VARCHAR(100) DEFAULT NULL,
  notes                  TEXT         DEFAULT NULL,
  created_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_registration_vehicle (vehicle_id),
  KEY idx_registration_expiry (registration_expiry),
  CONSTRAINT fk_registration_vehicle FOREIGN KEY (vehicle_id)
    REFERENCES vehicles (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;
