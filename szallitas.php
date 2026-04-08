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

<!DOCTYPE html>
<html lang="hu">
<head>
    <!-- Alap meta beállítások -->
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   
   <!-- Oldal ikon (favicon) -->
   <link rel="shortcut icon" href="assets/img/logo/MELICO LOGO 2.png" type="image/x-icon">
   
   <!-- Ikonok (Remixicon) -->
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/3.7.0/remixicon.css">
   
   <!-- Külső CSS fájl -->
   <link rel="stylesheet" href="assets/css/styles.css">
   
   <!-- Oldal címe -->
   <title>Szállítás Folyamatban - MELICO</title>

   <style>
        /* Oldal háttér beállítása (fix futár nézet háttérkép) */
       body {
           background-image: url('assets/img/futar-bg.png');
           background-size: cover;          /* teljes képernyő kitöltése */
           background-position: center;     /* középre igazítás */
           background-attachment: fixed;    /* háttér fix marad scrollnál */
           background-repeat: no-repeat;
           margin: 0;
           font-family: 'Poppins', sans-serif;
       }

       /* Fő szekció (középre igazítás + térköz header alatt) */
       .futar__section {
           padding-top: 8rem;
           padding-bottom: 4rem;
           min-height: 80vh;
           display: flex;
           justify-content: center;
       }

       /* Kártya (rendelés részletek konténer) */
       .futar__container {
           background-color: #ffffff;
           padding: 2.5rem;
           border-radius: 12px;
           box-shadow: 0 8px 30px rgba(0,0,0,0.15);
           max-width: 550px;
           width: 95%;
       }

       /* Oldalcím stílus */
       .futar__title {
           color: #175e69;
           text-align: center;
           margin-bottom: 2rem;
           display: flex;
           align-items: center;
           justify-content: center;
           gap: 10px;
       }

       /* Státusz jelző (pl. "Szállítás alatt") */
       .status-badge {
           display: inline-block;
           padding: 8px 18px;
           border-radius: 20px;
           font-size: 0.85rem;
           font-weight: bold;
           background-color: #28afc4;
           color: white;
           margin-bottom: 1rem;
           text-transform: uppercase;
       }

       /* Információs doboz (rendelés adatok) */
       .detail__info-box {
           background-color: #f9f9f9;
           padding: 1.5rem;
           border-radius: 8px;
           border-left: 5px solid #249db0;
           margin-bottom: 1.5rem;
       }

       /* Információs sorok */
       .detail__info-box p {
           margin: 10px 0;
           display: flex;
           align-items: center;
           gap: 12px;
       }

       /* Ikonok színezése */
       .detail__info-box i { 
        color: #249db0; 
        font-size: 1.2rem; 
        }

        /* Általános gomb stílus */
       .button {
           border: none;
           padding: 15px;
           border-radius: 8px;
           cursor: pointer;
           font-weight: bold;
           width: 100%;
           display: flex;
           align-items: center;
           justify-content: center;
           gap: 10px;
           transition: 0.3s ease;
           font-size: 1rem;
           text-decoration: none;
           margin-bottom: 12px;
       }

       /* Rendelés lezárása gomb */
       .button--complete {
           background-color: #0099cc;
           color: white;
       }
       .button--complete:hover {
           background-color: #00d0ff;
           transform: translateY(-2px);
       }

       /* Navigáció megnyitása (Google Maps) */
       .button--nav {
           background-color: #f39c12;
           color: white;
       }
       .button--nav:hover {
           background-color: #ffcc00;
           color: #000;
           transform: translateY(-2px);
       }

       /* Vissza gomb */
       .button--back {
           background: none;
           color: #666;
           text-decoration: none;
           display: inline-flex;
           align-items: center;
           gap: 8px;
           margin-bottom: 1.5rem;
           font-size: 0.95rem;
           transition: all 0.3s ease;
           width: auto;
           padding: 0;
       }
       .button--back:hover {
           color: #249db0;
           transform: translateX(-5px);
       }

       /* Elválasztó vonal */
       hr { 
        border: none; 
        border-top: 1px solid #eee; 
        margin: 15px 0; 
        }

   </style>

</head>
<body>
