-- AutoCare Cloud - fictional sample data (dates are relative to today)
USE autocare_cloud;

INSERT INTO vehicles (registration_number, make, model, variant, vehicle_type, manufacturing_year, purchase_date,
                      purchase_price, current_odometer, fuel_type, color, notes)
VALUES ('KLXX1234', 'Toyota', 'Glanza', 'G', 'Car', 2024, CURDATE() - INTERVAL 500 DAY, 795000, 14600, 'Petrol', 'White', 'Sample data (fictional)');
SET @car = LAST_INSERT_ID();

INSERT INTO vehicles (registration_number, make, model, variant, vehicle_type, manufacturing_year, purchase_date,
                      purchase_price, current_odometer, fuel_type, color, notes)
VALUES ('KLXX5678', 'Honda', 'Activa', '125', 'Scooter', 2023, CURDATE() - INTERVAL 700 DAY, 88000, 8200, 'Petrol', 'Grey', 'Sample data (fictional)');
SET @scooter = LAST_INSERT_ID();

INSERT INTO services (vehicle_id, service_date, odometer_reading, service_type, description, service_center,
                      labor_cost, parts_cost, next_service_date, next_service_odometer, reminder_done) VALUES
(@car,     CURDATE() - INTERVAL 200 DAY,  5000, 'General Service', 'First free service', 'Sample Motors Service Center', 1500, 2500, CURDATE() - INTERVAL 20 DAY, NULL, 1),
(@car,     CURDATE() - INTERVAL 120 DAY,  9800, 'Brake Service', 'Front brake pads replaced', 'Sample Motors Service Center', 800, 2400, NULL, NULL, 0),
(@car,     CURDATE() - INTERVAL 45 DAY,  13900, 'Oil Change', 'Engine oil and filter', 'Quick Lube Sample', 300, 1100, CURDATE() + INTERVAL 8 DAY, 14800, 0),
(@scooter, CURDATE() - INTERVAL 330 DAY,  5200, 'General Service', 'Routine service', 'Sample Two-Wheeler Care', 600, 900, CURDATE() - INTERVAL 5 DAY, 9000, 0);

INSERT INTO fuel_logs (vehicle_id, fuel_date, odometer_reading, fuel_quantity, price_per_liter, fuel_station, is_full_tank) VALUES
(@car,     CURDATE() - INTERVAL 60 DAY, 13000, 30.00, 102.00, 'Sample Fuels', 1),
(@car,     CURDATE() - INTERVAL 40 DAY, 13480, 28.00, 103.00, 'Sample Fuels', 1),
(@car,     CURDATE() - INTERVAL 20 DAY, 13800, 12.00, 104.00, 'City Petrol Pump', 0),
(@car,     CURDATE() - INTERVAL 5 DAY,  14100, 25.00, 104.00, 'Sample Fuels', 1),
(@scooter, CURDATE() - INTERVAL 30 DAY,  7900,  4.00, 103.00, 'Sample Fuels', 1),
(@scooter, CURDATE() - INTERVAL 10 DAY,  8200,  6.50, 104.00, 'Sample Fuels', 1);

INSERT INTO expenses (vehicle_id, expense_date, category, amount, description, payment_method) VALUES
(@car,     CURDATE() - INTERVAL 3 DAY,  'Parking',     50.00,  'Mall parking', 'UPI'),
(@car,     CURDATE() - INTERVAL 12 DAY, 'Toll',        120.00, 'Highway toll', 'Cash'),
(@car,     CURDATE() - INTERVAL 35 DAY, 'Accessories', 2500.00,'Seat covers', 'Card'),
(@car,     CURDATE() - INTERVAL 8 DAY,  'Cleaning',    400.00, 'Interior cleaning', 'UPI'),
(@car,     CURDATE() - INTERVAL 60 DAY, 'Maintenance', 650.00, 'Wiper blades', 'Cash'),
(@scooter, CURDATE() - INTERVAL 15 DAY, 'Accessories', 900.00, 'Helmet lock', 'Cash');

INSERT INTO insurance (vehicle_id, provider, policy_number, policy_type, start_date, expiry_date, premium, notes) VALUES
(@car,     'Sample General Insurance',    'SGI-2024-000111', 'Comprehensive', CURDATE() - INTERVAL 685 DAY, CURDATE() - INTERVAL 320 DAY, 13800, 'Previous policy'),
(@car,     'Sample General Insurance',    'SGI-2025-000123', 'Comprehensive', CURDATE() - INTERVAL 320 DAY, CURDATE() + INTERVAL 45 DAY,  14500, 'Current policy'),
(@scooter, 'Sample Two-Wheeler Insurance','STW-778899',      'Third Party',   CURDATE() - INTERVAL 400 DAY, CURDATE() - INTERVAL 35 DAY,  1200,  'Expired - renewal needed');

INSERT INTO registration (vehicle_id, registration_number, registration_date, registration_expiry, registration_authority, notes) VALUES
(@car,     'KLXX1234', CURDATE() - INTERVAL 500 DAY, CURDATE() + INTERVAL 13 YEAR, 'RTO Sample City', NULL),
(@scooter, 'KLXX5678', CURDATE() - INTERVAL 700 DAY, CURDATE() + INTERVAL 20 DAY,  'RTO Sample City', 'Sample: expiring soon');

INSERT INTO warranty (vehicle_id, provider, start_date, expiry_date, coverage, notes) VALUES
(@car, 'Sample Motors',      CURDATE() - INTERVAL 500 DAY, CURDATE() + INTERVAL 600 DAY, 'Standard 3-year / 100,000 km vehicle warranty', NULL),
(@car, 'Sample Battery Co.', CURDATE() - INTERVAL 400 DAY, CURDATE() - INTERVAL 10 DAY,  'Battery replacement warranty', 'Sample: expired');
