-- MELICO adatbázis létrehozása
CREATE DATABASE IF NOT EXISTS melico CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci;
USE melico;

-- Felhasználókat tároló tábla
CREATE TABLE IF NOT EXISTS USERS (
    id INT PRIMARY KEY AUTO_INCREMENT, -- Egyedi azonosító minden felhasználónak
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('0','1','2') NOT NULL    -- (0=vásárló,1=futár,2=admin)
);

-- Termékkategóriákat tároló tábla
CREATE TABLE IF NOT EXISTS CATEGORIES (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50),
    description TEXT
);

-- Szállítókat tároló tábla
CREATE TABLE IF NOT EXISTS SUPPLIERS (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100),
    contact VARCHAR(100),
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    description TEXT
);

-- Termékeket tároló tábla
CREATE TABLE IF NOT EXISTS PRODUCTS (
    id INT PRIMARY KEY AUTO_INCREMENT,
    category_id INT,
    supplier_id INT,
    name VARCHAR(100),
    description TEXT,
    price INT,
    image VARCHAR(255),
    stock INT,
    FOREIGN KEY (category_id) REFERENCES CATEGORIES(id), -- Idegen kulcs a kategóriához
    FOREIGN KEY (supplier_id) REFERENCES SUPPLIERS(id)   -- Idegen kulcs a szállítóhoz
);

-- Megrendeléseket tároló tábla
CREATE TABLE IF NOT EXISTS ORDERS (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,                           -- Hivatkozás a rendelést leadó felhasználóra
    date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Rendelés dátuma és ideje
    status ENUM('Megrendelve','Szállítás alatt','Kiszállítva','Lemondva') NOT NULL,
    shipping_address VARCHAR(255),         -- Szállítási cím
    FOREIGN KEY (user_id) REFERENCES USERS(id)
);

-- Rendelés tételeit tároló tábla
CREATE TABLE IF NOT EXISTS ORDER_ITEMS (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_id INT,
    product_id INT,
    quantity INT,                           -- Megrendelt mennyiség
    sale_price INT,                         -- Egységár a rendeléskor
    FOREIGN KEY (order_id) REFERENCES ORDERS(id),
    FOREIGN KEY (product_id) REFERENCES PRODUCTS(id)
);

-- Termékértékeléseket tároló tábla
CREATE TABLE IF NOT EXISTS REVIEWS (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    product_id INT,                        -- Hivatkozás a termékre
    stars ENUM('1','2','3','4','5'),       -- Értékelés csillagokban
    FOREIGN KEY (user_id) REFERENCES USERS(id),
    FOREIGN KEY (product_id) REFERENCES PRODUCTS(id)
);

-- Kuponokat tároló tábla
CREATE TABLE IF NOT EXISTS COUPONS (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20),                  -- Kupon kódja
    discount INT,                      -- Kedvezmény mértéke százalékban
    valid_until DATETIME               -- Érvényességi dátum
);

-- Felhasználók, jelszavak bcrypt-tel hash-elve
INSERT INTO USERS (id, name, email, password, role) VALUES
(1, 'Admin Péter', 'admin@melico.hu', '$2y$12$zH.V1Vz1eY1h1wB9uH5Y6OaV4zK5sdQ7HkPz1qE7E6qZ0C0bH5C6u', '2'),
(2, 'Futár Károly', 'futar@melico.hu', '$2y$12$Qe2bT3Xw1cA4vE7zR9G1mOyZ6a8xJ2qT1yC3sD7vM5bZ0nE3L4H7y', '1'),
(3, 'Vásárló Zita', 'vasarlo@melico.hu', '$2y$12$K5pZ8nL1yQ2vT6mR3G7xJ0cH4bS9aD1wE7yF2gN3kP6lM8oR5H0q', '0');

-- Kategóriák
INSERT INTO CATEGORIES (id, name, description) VALUES
(1, 'Lágy és Friss Sajtok', 'Rövid érlelésű, magas nedvességtartalmú sajtok.'),
(2, 'Félkemény Sajtok', 'Közepes ideig érlelt, jól szeletelhető sajtok.'),
(3, 'Kemény Sajtok', 'Hosszú érlelésű, alacsony nedvességtartalmú, reszelhető vagy törhető sajtok.');

-- Szállítók
INSERT INTO SUPPLIERS (id, name, contact, email, phone, description) VALUES
(1, 'Tiszántúli Sajtműhely', 'Kovács Elemér', 'kovacs.elemer@tisza.hu', '+36 30 123 4567', 'Klasszikus, hagyományos receptúrákra építő családi vállalkozás.'),
(2, 'Bakonyi Kézműves Gazdaság', 'Nagy Anna', 'nagy.anna@bakony.hu', '+36 20 987 6543', 'Kecskesajtokra specializálódott gazdaság, természetes takarmányozással.'),
(3, 'Pannon Sajtkerék', 'Tóth Balázs', 'toth.balazs@pannon.hu', '+36 70 555 1212', 'Erős, karakteres, kékpenészes és érlelt sajtok mestere.'),
(4, 'Dél-Alföldi Érlelő', 'Kiss Katalin', 'kiss.katalin@dalfold.hu', '+36 30 222 3344', 'Hosszú érlelési idejű, kemény sajtokra koncentrál, olasz inspirációkkal.'),
(5, 'Szekszárdi Borvidék Sajt', 'Varga Gábor', 'varga.gabor@szekszard.hu', '+36 20 111 2233', 'Különleges, borral és párlattal mosott kérgű sajtok.'),
(6, 'Erdélyi Manufaktúra', 'Popescu Elena', 'elena.popescu@erdely.ro', '+40 74 123 0000', 'Hagyományos erdélyi receptek alapján készült, friss savósajtok.'),
(7, 'Chili Suli', 'Fodor Bence', 'fodor.bence@chili.hu', '+36 30 777 8899', 'Fűszeres, különleges sajtok gyártója, prémium chili felhasználásával.'),
(8, 'Zalai Tejtermék', 'Molnár Péter', 'molnar.peter@zalatej.hu', '+36 20 444 5566', 'Friss, puha sajtok specialistája (mozzarella, krémsajt).'),
(9, 'Kisalföldi Gazdaság', 'Szabó Virág', 'szabo.virag@kfold.hu', '+36 70 999 0011', 'Holland típusú sajtokat gyártó modern üzem.');

-- Termékek
INSERT INTO PRODUCTS (id, category_id, supplier_id, name, description, price, image, stock) VALUES
(1, 1, 1, 'Camembert de Normandie AOP', NULL, 2500, NULL, 85),
(2, 1, 2, 'Chèvre Frais', NULL, 3100, NULL, 60),
(3, 1, 3, 'Gorgonzola Dolce DOP', NULL, 4800, NULL, 45),
(4, 1, 2, 'Ricotta', NULL, 2900, NULL, 95),
(5, 2, 1, 'Trappista', NULL, 3300, NULL, 70),
(6, 2, 3, 'Gouda Holland', NULL, 2100, NULL, 120),
(7, 3, 4, 'Parmigiano Reggiano DOP', NULL, 6500, NULL, 30),
(8, 3, 4, 'Grana Padano DOP', NULL, 7200, NULL, 25),
(9, 2, 5, 'Edami', NULL, 4900, NULL, 40),
(10, 1, 6, 'Mozzarella di Bufala Campana DOP', NULL, 2300, NULL, 110),
(11, 3, 7, 'Pecorino Romano DOP', NULL, 5900, NULL, 50),
(12, 1, 8, 'Mascarpone', NULL, 1900, NULL, 150),
(13, 2, 9, 'Maasdam', NULL, 3800, NULL, 65),
(14, 1, 2, 'Brie de Meaux AOP', NULL, 2700, NULL, 80),
(15, 1, 3, 'Burrata', NULL, 5500, NULL, 35),
(16, 3, 7, 'Comté AOP', NULL, 6900, NULL, 20);

-- Megrendelés példa
INSERT INTO ORDERS (id, user_id, status, shipping_address) VALUES
(1, 3, 'Szállítás alatt', '1095 Budapest, Ipar utca 12.');

-- Megrendelés tételei példa
INSERT INTO ORDER_ITEMS (order_id, product_id, quantity, sale_price) VALUES
(1, 1, 2, 2500),
(1, 3, 1, 4800);

-- Kuponok példa adatokkal
INSERT INTO COUPONS (code, discount, valid_until) VALUES
('SAJT10', 10, '2026-12-31 23:59:59');
