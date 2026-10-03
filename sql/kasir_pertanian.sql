CREATE DATABASE IF NOT EXISTS kasir_pertanian
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE kasir_pertanian;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','kasir') NOT NULL DEFAULT 'kasir',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    category VARCHAR(80) NOT NULL,
    price DECIMAL(15,2) NOT NULL,
    stock INT NOT NULL DEFAULT 0,
    min_stock INT NOT NULL DEFAULT 5,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice VARCHAR(40) UNIQUE NOT NULL,
    total DECIMAL(15,2) NOT NULL,
    cashier_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (cashier_id)
    REFERENCES users(id)
);

CREATE TABLE sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    product_id INT NOT NULL,
    qty INT NOT NULL,
    price DECIMAL(15,2) NOT NULL,

    FOREIGN KEY (sale_id)
    REFERENCES sales(id),

    FOREIGN KEY (product_id)
    REFERENCES products(id)
);

INSERT INTO users
(name, username, password, role)
VALUES
(
    'Administrator',
    'admin',
    '$2y$12$SH7iSClk5LxCegemQdbu/eNmfqkfvkkO4SmCn5FoOKQB64JiAMCa2',
    'admin'
),
(
    'Kasir Toko',
    'kasir',
    '$2y$12$7v77q1ieFmPkjvyoWE1V5e9SdVu1ud4cmwJrlED9nKwP4.CqIW6QC',
    'kasir'
);

INSERT INTO products
(name, category, price, stock, min_stock)
VALUES
('Pupuk NPK 16-16-16', 'Pupuk', 125000, 30, 5),
('Pupuk Urea 50 Kg', 'Pupuk', 285000, 18, 5),
('Benih Cabai Rawit', 'Benih', 35000, 42, 10),
('Herbisida 1 Liter', 'Pestisida', 78000, 12, 5),
('Polybag 30x30', 'Perlengkapan', 1500, 300, 50);