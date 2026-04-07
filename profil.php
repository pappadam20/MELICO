<?php 
/*========================================================
  PROFIL OLDAL – BACKEND RENDSZER
========================================================
  Ez a fájl felel a felhasználói profil teljes működéséért:

  Fő funkciók:
  - felhasználói session kezelése
  - profil adatok módosítása
  - jelszócsere
  - rendelés kezelés (lemondás)
  - kupon rendszer kezelése
  - értesítések kezelése
  - fiók törlés
========================================================*/

session_start();
include "db.php";



/*========================================================
  RENDSZER BEÁLLÍTÁSOK (ADMIN KONFIG)
========================================================
  Adatbázisból dinamikusan érkezik:
  - maximális kedvezményes termékek száma
========================================================*/
$settings_res = $conn->query("SELECT max_discounted_items FROM SETTINGS LIMIT 1");
$settings = $settings_res->fetch_assoc();
$max_allowed_discounted = $settings['max_discounted_items'] ?? 1;



/*========================================================
  RENDELÉS LEMONDÁSA
========================================================
  Funkció:
  - csak "Megrendelve" státuszú rendelés törölhető
  - készlet visszakerül a raktárba
  - rendelés státusz: "Lemondva"
========================================================*/
if (isset($_POST['cancel_order'])) {
    $order_id = intval($_POST['order_id']);
    $u_id = $_SESSION['user_id'];

    /* Ellenőrzés: jogosult-e a törlésre */
    $check_stmt = $conn->prepare("SELECT id FROM ORDERS WHERE id = ? AND user_id = ? AND status = 'Megrendelve'");
    $check_stmt->bind_param("ii", $order_id, $u_id);
    $check_stmt->execute();
    $res = $check_stmt->get_result();

    if ($res->num_rows > 0) {
        /* Rendelés tételeinek lekérése */
        $items_stmt = $conn->prepare("SELECT product_id, quantity FROM ORDER_ITEMS WHERE order_id = ?");
        $items_stmt->bind_param("i", $order_id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();

        /* Készlet visszatöltése */
        while ($item = $items_result->fetch_assoc()) {
            $p_id = $item['product_id'];
            $qty = $item['quantity'];
            
            $update_stock = $conn->prepare("UPDATE PRODUCTS SET stock = stock + ? WHERE id = ?");
            $update_stock->bind_param("ii", $qty, $p_id);
            $update_stock->execute();
            $update_stock->close();
        }
        $items_stmt->close();

        /* Rendelés státusz módosítása */
        $update_status = $conn->prepare("UPDATE ORDERS SET status = 'Lemondva' WHERE id = ?");
        $update_status->bind_param("i", $order_id);
        $update_status->execute();
        $update_status->close();
    }

    $check_stmt->close();
    header("Location: profil.php?tab=Orders");
    exit();
}



/*========================================================
  KUPON LEJÁRATI KEZELÉS (SESSION ALAPÚ)
========================================================*/
$discount = 0;
$expiry_timestamp = 0;

if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['coupon_expiry']) && $_SESSION['coupon_expiry'] > time()) {
        $discount = $_SESSION['coupon_discount'] ?? 0;
        $expiry_timestamp = $_SESSION['coupon_expiry'] * 1000; // JS-nek milliszekundum
    } else {
        unset($_SESSION['coupon_discount']);
        unset($_SESSION['coupon_expiry']);
    }
}



/*========================================================
  VISSZA NAVIGÁCIÓ KEZELÉSE
========================================================
  Biztonságos visszalépés csak engedélyezett oldalakra
========================================================*/
$allowed_pages = [
    'admin.php',
    'futar.php',
    'szallitas.php',
    'index.php',
    'kapcsolatfelvetel.php',
    'rolunk.php',
    'termekeink.php'
];

if (isset($_SERVER['HTTP_REFERER'])) {
    $ref_url = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH); // csak az útvonal
    $ref_page = basename($ref_url); // az oldal neve pl. index.php

    if (in_array($ref_page, $allowed_pages)) {
        $back_url = $_SERVER['HTTP_REFERER'];
    } else {
        $back_url = 'index.php';
    }
} else {
    $back_url = 'index.php';
}



/*========================================================
  BELÉPÉS ELLENŐRZÉS (SECURITY)
========================================================*/
if (!isset($_SESSION['user_id'])) {
    header("Location: signIn.php");
    exit();
}

$user_id = $_SESSION['user_id']; // Most már biztonságosan használhatjuk bárhol alatta



/*========================================================
  ÉRTESÍTÉSEK AUTOMATIKUS OLVASOTTRA ÁLLÍTÁSA
========================================================*/
$update_notif = $conn->prepare("UPDATE NOTIFICATIONS SET is_read = 1 WHERE user_id = ? AND is_read = 0");
$update_notif->bind_param("i", $user_id);
$update_notif->execute();



/*========================================================
  ÜZENET VÁLTOZÓK
========================================================*/
$success_msg = "";
$error_msg = "";



/*========================================================
  KIJELENTKEZÉS
========================================================*/
if (isset($_POST['logout']) || isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}



/*========================================================
  PROFIL ADATOK MENTÉSE
========================================================*/
if (isset($_POST['save'])) {
    $profile_name = trim($_POST['profile_name']);
    $email        = trim($_POST['email']);
    $location     = trim($_POST['location']);

    if (empty($profile_name) || empty($email)) {
        $error_msg = "A profil név és az e-mail mező nem lehet üres!";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_msg = "Érvénytelen e-mail formátum!";
    } else {
        $check_email = $conn->prepare("SELECT id FROM USERS WHERE email = ? AND id != ?");
        $check_email->bind_param("si", $email, $user_id);
        $check_email->execute();
        $res = $check_email->get_result();

        if ($res->num_rows > 0) {
            $error_msg = "Ez az e-mail cím már használatban van!";
        } else {
            $stmt = $conn->prepare("UPDATE USERS SET profile_name=?, email=?, location=? WHERE id=?");
            $stmt->bind_param("sssi", $profile_name, $email, $location, $user_id);
            
            if ($stmt->execute()) {
                $success_msg = "Adatok sikeresen frissítve!";
            } else {
                $error_msg = "Hiba történt a mentés során.";
            }
        }
    }
}



/*========================================================
  JELSZÓ CSERE
========================================================*/
if (isset($_POST['change_password'])) {
    $current_pass = $_POST['current_password'];
    $new_pass = $_POST['new_password'];

    if (strlen($new_pass) < 6) {
        $error_msg = "A jelszónak legalább 6 karakternek kell lennie!";
    } else {
        $stmt = $conn->prepare("SELECT password FROM USERS WHERE id=?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $current_hashed = $result['password'];

        if (!password_verify($current_pass, $current_hashed)) {
            $error_msg = "Hibás jelenlegi jelszó!";
        } elseif (password_verify($new_pass, $current_hashed)) {
            $error_msg = "Ez már a jelenlegi jelszó!";
        } else {
            $hashed_password = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE USERS SET password=? WHERE id=?");
            $stmt->bind_param("si", $hashed_password, $user_id);

            if ($stmt->execute()) {
                $success_msg = "Jelszó frissítve!";
            } else {
                $error_msg = "Hiba történt.";
            }
        }
    }
}
