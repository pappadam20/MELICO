<?php
/*=============== FUTÁR PANEL – RENDELÉSKEZELÉS (PHP) ===============*/
/*
    Ez a fájl a MELICO webáruház futár felületét valósítja meg.

    Fő funkciók:
    - Session kezelés (bejelentkezett felhasználó ellenőrzése)
    - Jogosultságkezelés (csak futár – role=1 férhet hozzá)
    - Aktív rendelések lekérdezése adatbázisból
    - Rendelés részleteinek megjelenítése dinamikusan (JS segítségével)
    - Szállítás indításának lehetősége
    - Rendelés teljesítésének kezelése (státusz frissítése)

    Extra logika:
    - Hűségprogram: kiszállított rendelések után automatikus kupon generálás
    - Kupon generálás a vásárló összköltése alapján (threshold rendszer)
    - Biztonságos adatbázis műveletek prepared statementekkel

    Frontend:
    - Reszponzív felület (HTML + CSS)
    - Dinamikus nézetváltás JavaScript segítségével (lista → részletek)
    - Modern UI (kártyás megjelenítés, státusz badge-ek)

    Biztonság:
    - Session alapú hozzáférés-védelem
    - HTML escaping (XSS ellen védelem)
    - Adatbázis műveletek bind_param használatával

    Megjegyzés:
    A kód tartalmaz egy egyszerű integritás-ellenőrző (licencvédelmi) mechanizmust,
    amely figyeli az oldal manipulációját.
*/



/*=============== SESSION KEZELÉS ===============*/
/* Ha még nincs session indítva, elindítjuk */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* Adatbázis kapcsolat betöltése */
include "db.php";



/*=============== JOGOSULTSÁG ELLENŐRZÉS ===============*/
/* Csak futár (role=1) férhet hozzá az oldalhoz */
if (!isset($_SESSION['role']) || $_SESSION['role'] != '1') {
    header("Location: index.php");
    exit();
}

/* Admin ellenőrzés (esetleges extra funkciókhoz) */
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] == '2';



/*=============== RENDELÉS TELJESÍTÉS LOGIKA ===============*/
/* Ha a futár rákattint a "kiszállítva" gombra */
if (isset($_POST['complete_order'])) {

    /* Rendelés státuszának frissítése */
    $finish = $conn->prepare("UPDATE ORDERS SET status = 'Kiszállítva' WHERE id = ?");
    $finish->bind_param("i", $order_id);
    
    if ($finish->execute()) {

        /*=============== HŰSÉGPROGRAM LOGIKA ===============*/
        /*
            Ez a függvény ellenőrzi a felhasználó eddigi költését,
            és ha elér egy bizonyos összeget, kupont generál számára.
        */
        function checkAndGenerateUserCouponsLocal($userId, $conn) {

            /* Rendszerbeállítások lekérése */
            $set_res = $conn->query("SELECT loyalty_threshold, coupon_percent FROM SETTINGS LIMIT 1");
            $settings = $set_res->fetch_assoc();

            $threshold = $settings['loyalty_threshold'] ?? 49999;
            $discount_pct = $settings['coupon_percent'] ?? 10;

            /* Összes eddigi költés kiszámítása */
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

            /* Meghatározzuk hány kupon jár */
            $coupon_level = floor($total_money / $threshold);

            /* Megnézzük eddig hány kupont kapott */
            $count_stmt = $conn->prepare("SELECT COUNT(*) as c FROM USER_COUPONS WHERE user_id = ?");
            $count_stmt->bind_param("i", $userId);
            $count_stmt->execute();

            $existing_coupons = $count_stmt->get_result()->fetch_assoc()['c'];
            $count_stmt->close();

            /* Ha több kupon jár, mint amennyit kapott → generálunk */
            if ($coupon_level > $existing_coupons) {
                $needed = $coupon_level - $existing_coupons;
                for ($i = 0; $i < $needed; $i++) {

                    /* Egyedi kuponkód generálása */
                    $code = "LOYALTY-" . strtoupper(bin2hex(random_bytes(3)));

                    /* Lejárati dátum (7 nap) */
                    $expiry = date('Y-m-d H:i:s', strtotime('+7 days'));
                    
                    /* Kupon mentése */
                    $ins_c = $conn->prepare("INSERT INTO COUPONS (code, discount, valid_until) VALUES (?, ?, ?)");
                    $ins_c->bind_param("sis", $code, $discount_pct, $expiry);
                    $ins_c->execute();

                    $coupon_id = $ins_c->insert_id;
                    $ins_c->close();

                    /* Kupon hozzárendelése felhasználóhoz */
                    $ins_uc = $conn->prepare("INSERT INTO USER_COUPONS (user_id, coupon_id) VALUES (?, ?)");
                    $ins_uc->bind_param("ii", $userId, $coupon_id);
                    $ins_uc->execute();
                    $ins_uc->close();
                }
            }
        }

        /* Függvény futtatása */
        checkAndGenerateUserCouponsLocal($order['uid'], $conn);

        /* Visszairányítás siker esetén */
        header("Location: futar.php?success=delivered");
        exit();
    }
}



/*=============== RENDELÉSEK LEKÉRDEZÉSE ===============*/
/*
    Cél:
    Az aktív (még le nem zárt) rendelések lekérdezése az adatbázisból,
    valamint a hozzájuk tartozó végösszeg kiszámítása.

    A lekérdezés az alábbi adatokat adja vissza:
    - rendelés azonosító (O.id)
    - rendelés dátuma (O.date)
    - rendelés státusza (O.status)
    - felhasználó neve és email címe
    - felhasználó szállítási címe
    - felhasználó azonosító (uid)
    - rendelés teljes összege (total_sum)

    Megjegyzés:
    A total_sum egy al-lekérdezéssel kerül kiszámításra,
    amely az ORDER_ITEMS táblában lévő tételek mennyiség * eladási ár összegét adja vissza.
*/
$sql = "SELECT O.id, O.date, O.status, U.name, U.email, U.location, U.id as uid,
        (SELECT SUM(quantity * sale_price) FROM ORDER_ITEMS WHERE order_id = O.id) as total_sum
        FROM ORDERS O 
        JOIN USERS U ON O.user_id = U.id 
        WHERE O.status IN ('Megrendelve', 'Szállítás alatt')
        ORDER BY O.date DESC";
$orders_res = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="hu">
<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">

   <!-- Favicon (weboldal ikon) -->
   <link rel="shortcut icon" href="assets/img/logo/MELICO LOGO 2.png" type="image/x-icon">

   <!-- Ikonkészlet (Remix Icon) -->
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/3.7.0/remixicon.css">

   <!-- Fő stíluslap -->
   <link rel="stylesheet" href="assets/css/styles.css">

   <title>Futár Panel - MELICO</title>

   <style>

        /*=============== OLDAL HÁTTÉR ===============*/
       /*
         Fix háttérkép a futár panelhez.
         - teljes képernyős megjelenés
         - nem ismétlődik
         - középre igazított
         - scroll közben fix marad
       */
       body {
           background-image: url('assets/img/futar-bg.png');
           background-size: cover;
           background-position: center;
           background-attachment: fixed;
           background-repeat: no-repeat;
           margin: 0;
       }

       /*=============== FŐ SZEKCIÓ ===============*/
       /*
         A futár panel teljes tartalmát középre igazítja.
       */
       .futar__section {
           padding-top: 8rem;
           padding-bottom: 4rem;
           min-height: 80vh;
           display: flex;
           justify-content: center;
       }

       /*=============== KONTAINER ===============*/
       /*
         Fehér kártya, ami tartalmazza az összes rendelést.
       */
       .futar__container {
           background-color: #ffffff;
           padding: 2rem;
           border-radius: 12px;
           box-shadow: 0 8px 30px rgba(0,0,0,0.15);
           max-width: 800px;
           width: 95%;
       }

       /*=============== CÍM ===============*/
       /*
         Futár panel címe ikon + szöveg középre igazítva.
       */
       .futar__title {
           color: #175e69;
           text-align: center;
           margin-bottom: 2rem;
           display: flex;
           align-items: center;
           justify-content: center;
           gap: 10px;
       }

       /*=============== RENDELÉS KÁRTYA ===============*/
       /*
         Egy-egy rendelés megjelenése listában.
       */
       .order__card {
           background-color: #f9f9f9;
           border: 1px solid #eee;
           padding: 1.25rem;
           border-radius: 8px;
           margin-bottom: 1rem;
           cursor: pointer;
           transition: 0.3s;
           display: flex;
           justify-content: space-between;
           align-items: center;
       }

       /* Hover effekt -> kiemelés */
       .order__card:hover {
           border-color: #28afc4;
           transform: translateX(5px);
       }

       /*=============== STÁTUSZ CÍMKE ===============*/
       /*
         Rendelés állapotát jelző badge.
       */
       .status-badge {
           padding: 4px 12px;
           border-radius: 20px;
           font-size: 0.75rem;
           font-weight: bold;
           background-color: #eee;
       }

       /* Aktív állapot */
       .status--active {
           background-color: #28afc4;
           color: white;
       }

       /*=============== RÉSZLET NÉZET ===============*/
       /*
         Rendelés részletes megtekintése.
         Alapból rejtve van.
       */
       .detail__view {
           display: none;
           animation: fadeIn 0.3s ease;
       }

       /*=============== ANIMÁCIÓ ===============*/
       /*
         Részletek megjelenésekor finom fade-in animáció.
       */
       @keyframes fadeIn {
           from { opacity: 0; transform: translateY(10px); }
           to { opacity: 1; transform: translateY(0); }
       }

       /*=============== INFORMÁCIÓS DOBOZ ===============*/
       /*
         Rendelés adatok kiemelt blokkban.
       */
       .detail__info-box {
           background-color: #f9f9f9;
           padding: 1.5rem;
           border-radius: 8px;
           border: 1px solid #249db0;
           margin-bottom: 1.5rem;
       }

       /*=============== GOMBOK ===============*/
       /*
         Alap gomb (pl. rendelés kezelése)
       */
       .button {
           background-color: #43b2d3;
           color: white;
           border: none;
           padding: 12px 20px;
           border-radius: 4px;
           cursor: pointer;
           font-weight: bold;
           width: 100%;
           display: inline-flex;
           align-items: center;
           justify-content: center;
           gap: 8px;
           transition: background-color 0.3s;
       }

       /* Hover állapot */
       .button:hover {
           background-color: #007193;
       }

       /* Vissza gomb */
       .button--back {
           background: none;
           color: #43b2d3;
           width: auto;
           padding: 0;
           margin-bottom: 1rem;
       }

       .button--back:hover {
           color: #ffffff;
           text-decoration: underline;
       }
   </style>
</head>
<body>
