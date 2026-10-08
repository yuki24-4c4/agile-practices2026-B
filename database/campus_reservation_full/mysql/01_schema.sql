-- MySQL 8.x / MariaDB: database and four core tables
CREATE DATABASE IF NOT EXISTS campus_reservation
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE campus_reservation;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(255) NOT NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('user','admin') NOT NULL DEFAULT 'user',
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  description TEXT NULL,
  status ENUM('available','maintenance','inactive') NOT NULL DEFAULT 'available',
  PRIMARY KEY (id),
  KEY idx_items_category (category_id),
  CONSTRAINT fk_items_category FOREIGN KEY (category_id)
    REFERENCES categories(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  status ENUM('pending','approved','lent','returned','rejected','canceled') NOT NULL DEFAULT 'pending',
  PRIMARY KEY (id),
  KEY idx_reservations_user (user_id),
  KEY idx_reservations_item_dates (item_id,start_date,end_date),
  CONSTRAINT fk_reservations_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_reservations_item FOREIGN KEY (item_id)
    REFERENCES items(id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_reservations_dates CHECK (end_date >= start_date)
) ENGINE=InnoDB;
