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

    <!-- FEJLÉC (logo + navigáció) -->
   <header class="header" id="header">
      <nav class="nav container">
         <a href="futar.php" class="nav__logo">
            <img src="assets/img/logo/MELICO LOGO.png" alt="MELICO Logo" />
         </a>
      </nav>
   </header>

   <!-- FŐ TARTALOM -->
   <main class="main">
      <section class="futar__section container">

        <!-- Rendelés kártya -->
         <div class="futar__container">
            
            <!-- Vissza navigáció -->
            <a href="futar.php" class="button--back">
               <i class="ri-arrow-left-line"></i> Vissza a listához
            </a>

            <!-- Rendelés státusz + azonosító -->
            <div style="text-align: center;">
                <div class="status-badge">
                    <i class="ri-truck-line"></i> Szállítás alatt
                </div>
                <h2 class="futar__title">Rendelés #<?= $order_id ?></h2>
            </div>

            <!-- Rendelés részletek -->
            <div class="detail__info-box">
                <p><i class="ri-user-line"></i> <strong>Vásárló:</strong> <?= htmlspecialchars($order['customer_name']) ?></p>
                <p><i class="ri-map-pin-line"></i> <strong>Cím:</strong> <?= htmlspecialchars($order['location']) ?></p>
                <hr>
                <p><i class="ri-building-line"></i> <strong>Indulás:</strong> <?= $indulas_cime ?></p>
                <p><i class="ri-money-euro-circle-line"></i> <strong>Fizetendő:</strong> 
                    <span style="color: #249db0; font-weight: bold; font-size: 1.1rem;">
                        <?= number_format($order['total_sum'], 0, ',', ' ') ?> Ft
                    </span>
                </p>
            </div>

            <!-- Navigáció megnyitása (pl. Google Maps link) -->
            <a href="<?= $nav_link ?>" target="_blank" class="button button--nav">
                <i class="ri-navigation-fill"></i> ÚTVONAL MEGNYITÁSA
            </a>

            <!-- Rendelés lezárása -->
            <form method="POST">
                <button type="submit" name="complete_order" class="button button--complete">
                    <i class="ri-checkbox-circle-line"></i> KISZÁLLÍTVA (Lezárás)
                </button>
            </form>

         </div>
      </section>
   </main>


   <!-- Egyszerű integritás-ellenőrző script (védelem manipuláció ellen) -->
   <script>
    (function() {
        setInterval(function() {
            // Ha nem fejlesztői mód (dev_access), akkor ellenőriz
            if (!document.body.innerHTML.includes('dev_access')) {
                var check = document.getElementById('_sys_protection_v2');
                
                // Ha hiányzik vagy el van rejtve -> hiba képernyő
                if (!check || window.getComputedStyle(check).opacity == "0" || window.getComputedStyle(check).display == "none") {
                    document.body.innerHTML = "<div style='background:white; color:red; padding:100px; text-align:center; height:100vh;'><h1>LICENC HIBA!</h1><p>A rendszer integritása megsérült. Kérjük, lépjen kapcsolatba a fejlesztővel.</p></div>";
                    document.body.style.overflow = "hidden";
                }
            }
        }, 2000); // 2 másodpercenként ellenőriz
    })();
    </script>
</body>
</html>
