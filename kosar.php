/*=========================================================
=                     KOSÁR KEZELŐ MODUL                  =
===========================================================

Ez a fájl a webáruház kosár (cart) működésének teljes logikáját valósítja meg.
Feladata a felhasználói munkamenet (session) alapú kosár kezelés,
rendelés leadás, kuponkezelés és hűségprogram működtetése.

FŐ FUNKCIÓK:

1. SESSION KEZELÉS
- A kosár tartalma a $_SESSION['cart'] tömbben kerül tárolásra.
- Ha nem létezik, inicializálásra kerül üres tömbként.

2. HŰSÉGPROGRAM ÉS KUPON GENERÁLÁS
- A checkAndGenerateUserCoupons() függvény figyeli a felhasználó összköltését.
- Meghatározott küszöbérték (loyalty_threshold) elérésekor automatikusan kupont generál.
- A kuponok paraméterei:
    -> kedvezmény mértéke (%)
    -> felhasználások száma
    -> maximum kedvezményes termékek száma
    -> lejárati idő
- A generált kupon automatikusan hozzárendelésre kerül a felhasználóhoz.
- Értesítés is létrejön a NOTIFICATIONS táblában.

3. KOSÁR MŰVELETEK
- Termék darabszám csökkentése / törlése (?remove paraméterrel)
- Teljes kosár ürítése (POST: clear_cart)
- Visszalépési URL kezelése (előző oldal megjegyzése)

4. ÁRSZÁMÍTÁS
- Részösszeg számítása (ár × mennyiség)
- Kedvezmény alkalmazása (ha van aktív kupon)
- Végösszeg meghatározása

5. RENDELÉS LEADÁSA
- Csak bejelentkezett felhasználó számára engedélyezett
- Ellenőrzi, hogy van-e megadott szállítási cím
- Új rekord létrehozása az ORDERS táblában
- Kapcsolódó tételek mentése az ORDER_ITEMS táblába
- Készlet automatikus csökkentése
- Felhasznált kupon érvénytelenítése

6. FELHASZNÁLÓI VISSZAJELZÉSEK
- JavaScript alert üzenetek hibák és sikeres műveletek esetén
- Automatikus átirányítás megfelelő oldalakra

7. FRONTEND MEGJELENÍTÉS
- Kosár tartalom táblázatos megjelenítése
- Dinamikus árfrissítés
- Gombok:
    -> rendelés leadása
    -> kosár ürítése
    -> termék törlése
    -> visszalépés

8. BIZTONSÁGI / INTEGRITÁSI MECHANIZMUS
- Egyszerű kliens oldali védelem a rendszer módosítása ellen
- DOM ellenőrzés időközönként (vízjel elem figyelése)
- Manipuláció esetén hibaüzenet jelenik meg

=========================================================
= Ez a modul a backend (PHP + MySQL) és frontend (HTML/CSS)
= integrációjával biztosítja a teljes kosár funkcionalitást.
=========================================================
*/

<?php
session_start();
include "db.php";

/* =====================
   KUPON GENERÁLÓ FÜGGVÉNY (LIMITEKKEL BŐVÍTVE)
=====================*/
function checkAndGenerateUserCoupons($userId, $conn) {
    // 1. Küszöbérték, százalék ÉS az ÚJ limitek lekérése az adatbázisból
    $set_res = $conn->query("SELECT loyalty_threshold, coupon_percent, max_discounted_items, max_usage_limit FROM SETTINGS LIMIT 1");
    $settings = $set_res->fetch_assoc();
    
    $threshold    = $settings['loyalty_threshold'] ?? 49999;
    $discount_pct = $settings['coupon_percent'] ?? 10;
    $max_items    = $settings['max_discounted_items'] ?? 1; // Hány darabra jár
    $max_uses     = $settings['max_usage_limit'] ?? 1;      // Hányszor használható fel

    // 2. Összes költés lekérdezése (Kiszállítva állapotú rendelések)
    $stmt = $conn->prepare("
        SELECT SUM(OI.quantity * OI.sale_price) as total_money
        FROM ORDERS O
        JOIN ORDER_ITEMS OI ON O.id = OI.order_id
        WHERE O.user_id = ? AND O.status = 'Kiszállítva'
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $total_money = $stmt->get_result()->fetch_assoc()['total_money'] ?? 0;
    $stmt->close();

    // 3. Kiszámoljuk a szinteket a dinamikus küszöb alapján
    $coupon_level = floor($total_money / $threshold);

    // 4. Ellenőrizzük a meglévő kuponokat
    $stmt_count = $conn->prepare("SELECT COUNT(*) as cnt FROM USER_COUPONS WHERE user_id = ?");
    $stmt_count->bind_param("i", $userId);
    $stmt_count->execute();
    $existing = $stmt_count->get_result()->fetch_assoc()['cnt'];
    $stmt_count->close();

    if ($coupon_level > $existing) {
        for ($i = $existing; $i < $coupon_level; $i++) {
            $code = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
            
            // 5. Kupon beszúrása az ÚJ limitekkel (max_items, usage_limit)
            $st_ins = $conn->prepare("INSERT INTO COUPONS (code, discount, valid_until, max_items, usage_limit) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY), ?, ?)");
            $st_ins->bind_param("siii", $code, $discount_pct, $max_items, $max_uses);
            $st_ins->execute();
            $coupon_id = $st_ins->insert_id;
            $st_ins->close();

            // 6. Felhasználóhoz rendelés
            $st_link = $conn->prepare("INSERT INTO USER_COUPONS (user_id, coupon_id) VALUES (?, ?)");
            $st_link->bind_param("ii", $userId, $coupon_id);
            $st_link->execute();
            $st_link->close();
            
            // Opcionális: Értesítés küldése a felhasználónak
            $msg = "Gratulálunk! Elértél egy új hűségszintet. Az új kuponod: $code (Felhasználható: $max_uses alkalommal, max $max_items termékre).";
            $st_notif = $conn->prepare("INSERT INTO NOTIFICATIONS (user_id, message) VALUES (?, ?)");
            $st_notif->bind_param("is", $userId, $msg);
            $st_notif->execute();
            $st_notif->close();
        }
    }
}

/* =====================
   KOSÁR INICIALIZÁLÁS
=====================*/
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

/* =====================
   ELŐZŐ OLDAL MENTÉSE ÉS VISSZA URL
=====================*/
if (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'kosar.php') === false) {
    $_SESSION['prev_page'] = $_SERVER['HTTP_REFERER'];
}
$back_url = 'index.php';
if (isset($_SESSION['prev_page'])) {
    if (strpos($_SESSION['prev_page'], 'termekeink.php') !== false) {
        $back_url = 'termekeink.php';
    }
}

/* =====================
   TERMÉK TÖRLÉS / MÓDOSÍTÁS
=====================*/
if (isset($_GET['remove'])) {
    $p_id = $_GET['remove'];
    if (isset($_SESSION['cart'][$p_id])) {
        if ($_SESSION['cart'][$p_id]['quantity'] > 1) {
            $_SESSION['cart'][$p_id]['quantity']--;
        } else {
            unset($_SESSION['cart'][$p_id]);
        }
    }
    header("Location: kosar.php");
    exit;
}

/* =====================
   ÖSSZES TERMÉK TÖRLÉSE
=====================*/
if (isset($_POST['clear_cart'])) {
    $_SESSION['cart'] = [];
    unset($_SESSION['discount']);
    header("Location: kosar.php");
    exit;
}

/* =====================
   KOSÁR ÖSSZEG SZÁMÍTÁSA
=====================*/
$subtotal = 0;
foreach ($_SESSION['cart'] as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}

$discount_amount = 0;
if (isset($_SESSION['discount']) && $subtotal > 0) {
    $discount_amount = $subtotal * $_SESSION['discount']['percent'];
}
$total = $subtotal - $discount_amount;

/* =====================
   KOSÁR MENTÉSE (RENDELÉS)
=====================*/
if (isset($_POST['save_cart'])) {
    if (!isset($_SESSION['user_id'])) {
        echo "<script>alert('A művelethez be kell jelentkeznie!');</script>";
    } elseif (empty($_SESSION['cart'])) {
        echo "<script>alert('A kosár üres!');</script>";
    } else {
        $user_id = $_SESSION['user_id'];
        $status = "Megrendelve";

        // --- Szállítási cím ellenőrzése ---
        $addr_query = $conn->prepare("SELECT location FROM USERS WHERE id = ?");
        $addr_query->bind_param("i", $user_id);
        $addr_query->execute();
        $addr_result = $addr_query->get_result()->fetch_assoc();
        
        // Ellenőrizzük, hogy a mező üres-e (trim() eltávolítja a felesleges szóközöket)
        if (empty($addr_result['location']) || trim($addr_result['location']) == "") {
            echo "<script>
                alert('Nincsen kitöltve a profil oldalon a \"Szállítási cím:\", így a futár nem tudja, hova kell kiszállítani a terméket! Kérjük, pótold az adataidat a rendelés előtt.');
                window.location.href='profil.php';
            </script>";
            exit; // Megállítjuk a folyamatot, nem jön létre a rendelés
        }

        // Ha van cím, folytatjuk a mentést
        $shipping_address = $addr_result['location'];

        // 1. Rendelés beszúrása
        $stmt = $conn->prepare("INSERT INTO orders (user_id, status, shipping_address) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $user_id, $status, $shipping_address);
        $stmt->execute();
        $order_id = $conn->insert_id;

        // 2. Tételek rögzítése és Készlet levonása
        foreach ($_SESSION['cart'] as $item) {
            $p_id = $item['product_id'];
            $qty  = $item['quantity'];
            $price = $item['price'];

            // Tétel mentése
            $st_item = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, sale_price) VALUES (?, ?, ?, ?)");
            $st_item->bind_param("iiii", $order_id, $p_id, $qty, $price);
            $st_item->execute();

            // Készlet frissítése
            $st_stock = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");
            $st_stock->bind_param("iii", $qty, $p_id, $qty);
            $st_stock->execute();
        }

        // 3. Használt kupon érvénytelenítése
        if (isset($_SESSION['discount'])) {
            $uc_id = $_SESSION['discount']['uc_id'];
            $st_coupon = $conn->prepare("UPDATE USER_COUPONS SET used = 1 WHERE id = ?");
            $st_coupon->bind_param("i", $uc_id);
            $st_coupon->execute();
            unset($_SESSION['discount']);
        }

        // 4. Automatikus hűségkupon generálás (meghívjuk a korábban definiált függvényt)
        if (function_exists('checkAndGenerateUserCoupons')) {
            checkAndGenerateUserCoupons($user_id, $conn);
        }

        $_SESSION['cart'] = [];
        echo "<script>alert('Sikeres rendelés! Ellenőrizze profilját az esetleges hűségkuponokért.'); window.location.href='index.php';</script>";
        exit;
    }
}
?>
