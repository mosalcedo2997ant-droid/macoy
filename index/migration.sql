USE gio_san;

-- Run this ONCE in phpMyAdmin (SQL tab) bago i-upload yung bagong PHP files
ALTER TABLE bookings ADD COLUMN booking_time TIME NULL AFTER booking_date;
ALTER TABLE bookings ADD COLUMN phone VARCHAR(20) NULL AFTER booking_time;
