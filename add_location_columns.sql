-- SQL script to add location fields to customer_profile table
ALTER TABLE customer_profile 
ADD COLUMN address VARCHAR(255),
ADD COLUMN city VARCHAR(255),
ADD COLUMN state VARCHAR(255),
ADD COLUMN postal_code VARCHAR(20),
ADD COLUMN country VARCHAR(100) DEFAULT 'Colombia',
ADD COLUMN latitude DECIMAL(10, 8),
ADD COLUMN longitude DECIMAL(11, 8);
