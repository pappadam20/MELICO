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



/*========================================================
  FELHASZNÁLÓ ADATOK LEKÉRÉSE
========================================================*/
$stmt = $conn->prepare("SELECT name, profile_name, location, email, role FROM USERS WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    session_destroy();
    header("Location: signIn.php");
    exit();
}

$isAdmin = ($user['role'] == '2');



/*========================================================
  KUPON RENDSZER (SZEMÉLYES + GLOBÁLIS)
========================================================*/

/* Személyes kupon */
$personal_coupon = null;
if ($user['role'] == '0') {
    $personal_coupon_query = "
        SELECT C.id, C.code, C.discount, C.valid_until 
        FROM USER_COUPONS UC
        JOIN COUPONS C ON UC.coupon_id = C.id
        WHERE UC.user_id = ? AND UC.used = 0 AND C.valid_until >= NOW()
        ORDER BY UC.assigned_at DESC LIMIT 1";

    $p_stmt = $conn->prepare($personal_coupon_query);
    $p_stmt->bind_param("i", $user_id);
    $p_stmt->execute();
    $personal_coupon = $p_stmt->get_result()->fetch_assoc();
    $p_stmt->close();
}


// --- GLOBÁLIS AKTÍV KUPON LEKÉRÉSE ---
$now = date("Y-m-d H:i:s");
$global_coupon_query = "SELECT id, code, discount, valid_until FROM COUPONS 
                        WHERE valid_until >= ? 
                        ORDER BY id DESC LIMIT 1";
$g_stmt = $conn->prepare($global_coupon_query);
$g_stmt->bind_param("s", $now);
$g_stmt->execute();
$global_coupon = $g_stmt->get_result()->fetch_assoc();

$is_global_used = false;
if ($global_coupon) {
    // Ellenőrizzük, hogy a felhasználó felhasználta-e már ezt a konkrét globális kupont
    $check_used = $conn->prepare("SELECT id FROM USER_COUPONS WHERE user_id = ? AND coupon_id = ? AND used = 1");
    $check_used->bind_param("ii", $user_id, $global_coupon['id']);
    $check_used->execute();
    if ($check_used->get_result()->num_rows > 0) {
        $is_global_used = true;
    }
}

// 5. RENDELÉSEK LEKÉRÉSE
$order_query = $conn->prepare("
    SELECT 
        O.id, 
        O.date, 
        O.status, 
        SUM(OI.quantity * OI.sale_price) AS total_price,
        GROUP_CONCAT(CONCAT(P.name, ' (', C.name, ') - ', OI.quantity, ' db') SEPARATOR '<br>') AS items_details
    FROM ORDERS O
    LEFT JOIN ORDER_ITEMS OI ON O.id = OI.order_id
    LEFT JOIN PRODUCTS P ON OI.product_id = P.id
    LEFT JOIN CATEGORIES C ON P.category_id = C.id
    WHERE O.user_id = ? 
    GROUP BY O.id 
    ORDER BY O.date DESC
");
$order_query->bind_param("i", $user_id);
$order_query->execute();
$orders = $order_query->get_result();

// =================== 5.5 ÉRTESÍTÉSEK LEKÉRÉSE ===================
$notif_stmt = $conn->prepare("SELECT * FROM NOTIFICATIONS WHERE user_id = ? ORDER BY created_at DESC");
$notif_stmt->bind_param("i", $user_id);
$notif_stmt->execute();
$notif_res = $notif_stmt->get_result();

$unread_count = 0;
$all_notifs = [];
while($n = $notif_res->fetch_assoc()){
    if($n['is_read'] == 0) $unread_count++;
    $all_notifs[] = $n;
}
// ===============================================================

// =================== 5.6 KUPONOK LEKÉRÉSE ===================
// Csak azokat a kuponokat kérjük le, amiket a user MEGKAPOTT, de MÉG NEM VÁLTOTT BE (used = 0)
$coupon_stmt = $conn->prepare("
    SELECT C.id, C.code, C.discount, C.valid_until 
    FROM USER_COUPONS UC 
    JOIN COUPONS C ON UC.coupon_id = C.id 
    WHERE UC.user_id = ? AND UC.used = 0 AND C.valid_until >= NOW()
    ORDER BY C.valid_until ASC
");
$coupon_stmt->bind_param("i", $user_id);
$coupon_stmt->execute();
$coupons_res = $coupon_stmt->get_result();
$my_active_coupons = $coupons_res->fetch_all(MYSQLI_ASSOC);

// ===============================================================

// 6. FELHASZNÁLÓ FIÓK TÖRLÉSE
if (isset($_POST['delete_account'])) {
    $current_pass = $_POST['current_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';

    if ($current_pass !== $confirm_pass) {
        $error_msg = "A jelszavak nem egyeznek!"; // Hiba, ha nem egyezik
    } else {
        // Lekérjük a jelenlegi jelszót az adatbázisból
        $stmt = $conn->prepare("SELECT password FROM USERS WHERE id=?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $hashed_password = $result['password'];

        if (!password_verify($current_pass, $hashed_password)) {
            $error_msg = "Hibás jelszó! A fiók nem lett törölve.";
        } else {
            // Fiók törlése
            $stmt = $conn->prepare("DELETE FROM USERS WHERE id=?");
            $stmt->bind_param("i", $user_id);
            if ($stmt->execute()) {
                session_destroy();
                header("Location: index.php?msg=account_deleted");
                exit();
            } else {
                $error_msg = "Hiba történt a fiók törlése közben.";
            }
        }
    }
}
?>
