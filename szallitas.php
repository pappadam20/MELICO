<?php
/*=============== FUTÁR OLDAL - RENDELÉS KEZELÉS ===============*/

session_start();
include "db.php";

/* Jogosultság ellenőrzés:
   Csak futár (role = 1) férhet hozzá az oldalhoz.
   Ha nem jogosult, visszairányítjuk a főoldalra. */
if (!isset($_SESSION['role']) || $_SESSION['role'] != '1') {
    header("Location: index.php");
    exit();
}

/* Rendelés azonosító lekérése GET paraméterből */
$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

/* Fix indulási cím (pl. raktár / központ) */
$indulas_cime = "1095 Budapest, Ipar utca 12.";



/*=============== 1. RENDELÉS ADATAINAK LEKÉRÉSE ===============*/
/* Lekérjük:
   - rendelés adatait (ORDERS)
   - vásárló adatait (USERS)
   - teljes végösszeget (összes tétel ára) */
$stmt = $conn->prepare("
    SELECT O.*, U.location, U.name as customer_name, U.id as uid,
    (SELECT SUM(quantity * sale_price) FROM ORDER_ITEMS WHERE order_id = O.id) as total_sum
    FROM ORDERS O 
    JOIN USERS U ON O.user_id = U.id 
    WHERE O.id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

/* Hibakezelés: ha nincs ilyen rendelés */
if (!$order) {
    die("Hiba: A rendelés nem található.");
}



/*=============== 2. SZÁLLÍTÁS ELINDÍTÁSA ===============*/
/* Ha még nem indult el és nincs kiszállítva:
   - státusz módosítása
   - értesítés küldése a vásárlónak */
if ($order['status'] !== 'Szállítás alatt' && $order['status'] !== 'Kiszállítva') {

    /* Státusz frissítése */
    $upd = $conn->prepare("UPDATE ORDERS SET status = 'Szállítás alatt' WHERE id = ?");
    $upd->bind_param("i", $order_id);
    $upd->execute();

    /* Szimulált szállítási idő (25–55 perc) */
    $cel = $order['location'];
    $percek = rand(25, 55);
    $erkezesi_ido = date("H:i", strtotime("+$percek minutes"));

    /* Értesítési üzenet összeállítása */
    $uzenet = "Kedves " . $order['customer_name'] . "! A futárunk elindult a csomagoddal innen: $indulas_cime.";

    /* Értesítés mentése adatbázisba */
    $notif = $conn->prepare("INSERT INTO NOTIFICATIONS (user_id, message, is_read) VALUES (?, ?, 0)");
    $notif->bind_param("is", $order['uid'], $uzenet);
    $notif->execute();
    
    /* Lokális státusz frissítése */
    $order['status'] = 'Szállítás alatt';
}



/*=============== 3. KISZÁLLÍTÁS LEZÁRÁSA ===============*/
/* Ha a futár megnyomja a "kiszállítva" gombot:
   - státusz frissítés
   - értesítés küldése
   - kupon logika meghívása */
if (isset($_POST['complete_order'])) {

    /* Státusz módosítása */
    $finish = $conn->prepare("UPDATE ORDERS SET status = 'Kiszállítva' WHERE id = ?");
    $finish->bind_param("i", $order_id);
    
    if ($finish->execute()) {
        /* Sikeres kiszállítás értesítés */
        $sikeres_uzenet = "Kedves " . $order['customer_name'] . "! Köszönjük, hogy minket választottál, jó étvágyat kívánunk!";
        
        /* Értesítés létrehozása a felhasználó számára sikeres művelet esetén */
        $notif_finish = $conn->prepare("INSERT INTO NOTIFICATIONS (user_id, message, is_read) VALUES (?, ?, 0)");
        
        /* Paraméterek bindolása:
            - "i" = integer (felhasználó azonosító)
            - "s" = string (értesítés szövege) */
        $notif_finish->bind_param("is", $order['uid'], $sikeres_uzenet);

        /* Lekérdezés végrehajtása az adatbázisban */
        $notif_finish->execute();


        /* Kupon generáló logika (ha létezik) */
        include_once "futar.php";
        if (function_exists('checkAndGenerateUserCoupons')) {
            checkAndGenerateUserCoupons($order['uid'], $conn);
        }

        /* Visszairányítás sikeres státusszal */
        header("Location: futar.php?success=delivered");
        exit();
    }
}

/*=============== 4. GOOGLE MAPS NAVIGÁCIÓ LINK ===============*/
/* Dinamikus útvonal generálása:
   - kiindulási cím: raktár
   - cél: vásárló címe */
$nav_link = "https://www.google.com/maps/dir/?api=1&origin=" . urlencode($indulas_cime) . "&destination=" . urlencode($order['location']) . "&travelmode=driving";
?>
