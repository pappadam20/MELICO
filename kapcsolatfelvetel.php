/*=============== BACKEND + SESSION KEZELÉS ===============*/
/*
  - Adatbázis kapcsolat betöltése
  - Session indítása (ha még nincs elindítva)
*/
<?php
include "db.php";

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}



/*=============== KUPON LOGIKA ===============*/
/*
  Cél:
  - Ellenőrzi, van-e aktív kupon a felhasználónál
  - Ha lejárt -> törli a sessionből
  - Ha aktív -> átadja a kedvezményt és lejárati időt JS-nek
*/
$discount = 0;
$expiry_timestamp = 0;

if (isset($_SESSION['user_id'])) {

   // Kupon érvényesség ellenőrzése
    if (isset($_SESSION['coupon_expiry']) && $_SESSION['coupon_expiry'] > time()) {
        $discount = $_SESSION['coupon_discount'] ?? 0;

        // JavaScript kompatibilis idő (ms)
        $expiry_timestamp = $_SESSION['coupon_expiry'] * 1000;
    } else {
      // lejárt kupon törlése
        unset($_SESSION['coupon_discount']);
        unset($_SESSION['coupon_expiry']);
    }
}



/*=============== ADMIN JOGOSULTSÁG ===============*/
/*
  Ellenőrzi, hogy a bejelentkezett felhasználó admin-e.
  role = 2 -> admin jogosultság
*/
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] == '2';
?>
