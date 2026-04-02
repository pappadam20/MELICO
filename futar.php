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
