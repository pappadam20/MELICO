<?php
/*
====================================================
TERMÉKLISTÁZÓ ÉS KOSÁRKEZELŐ LOGIKA (termekeink.php)
====================================================

Ez a fájl a webáruház egyik központi eleme, amely a következő fő feladatokat látja el:

1. MUNKAMENET KEZELÉS
- A session indításával biztosítja a felhasználói adatok (pl. kosár, kuponok) tárolását.
- Eltárolja az utolsó meglátogatott oldalt a navigáció megkönnyítésére.

2. ADATBÁZIS KAPCSOLAT
- A db.php fájlon keresztül csatlakozik az adatbázishoz.
- Lekérdezi a szükséges adatokat (termékek, kategóriák, beállítások).

3. KUPON- ÉS KEDVEZMÉNYKEZELÉS
- Ellenőrzi, hogy a felhasználónak van-e aktív kuponja.
- Figyelembe veszi a kupon lejárati idejét.
- Dinamikusan alkalmazza a kedvezményt a termékekre.
- A kedvezményes termékek darabszámát a SETTINGS táblában megadott limit szabályozza.

4. KOSÁRKEZELÉS (SESSION ALAPÚ)
- A felhasználó kosarát a session-ben tárolja.
- Termék hozzáadásakor:
  -> ellenőrzi a jogosultságot (csak vásárló adhat hozzá),
  -> ellenőrzi a raktárkészletet,
  -> kezeli a kedvezményes és normál árú tételeket külön.
- Biztosítja, hogy a felhasználó ne vásárolhasson többet, mint a rendelkezésre álló készlet.

5. KATEGÓRIÁK ÉS TERMÉKEK MEGJELENÍTÉSE
- Lekérdezi a kategóriákat és azokhoz tartozó termékeket.
- A renderCategory() függvény felel a termékek dinamikus megjelenítéséért.
- Megjeleníti:
  -> termék nevét,
  -> árát (kedvezményesen vagy normál áron),
  -> készlet állapotát vizuális jelöléssel,
  -> képet (fallback képpel, ha nincs megadva).

6. DINAMIKUS KUPON KVÓTA KEZELÉS
- Figyelembe veszi:
  -> korábbi vásárlásokat (adatbázisból),
  -> aktuális kosár tartalmát (session-ből).
- Ezek alapján számolja ki, hogy a felhasználó még hány kedvezményes terméket vásárolhat.

7. BIZTONSÁG
- Prepared statement-eket használ SQL injection ellen.
- htmlspecialchars() használata XSS támadások ellen.
- Jogosultság ellenőrzések (role alapú hozzáférés).

8. FELHASZNÁLÓI ÉLMÉNY
- Visszajelzések URL paraméterekkel (pl. sikeres kosárba helyezés, készlethiány).
- Dinamikus ármegjelenítés és kupon státusz visszajelzés.

Összességében ez a modul biztosítja a webáruház alapvető működését:
termékek böngészése, kosár kezelés és kedvezmények alkalmazása.

====================================================
*/

session_start();            // Munkamenet indítása a felhasználói adatok (pl. kosár, kupon) kezeléséhez
require_once "db.php";      // Adatbázis kapcsolat betöltése

$_SESSION['last_page'] = 'termekeink.php';  // Az utolsó meglátogatott oldal mentése (pl. visszairányításhoz)



// --- 1. KEDVEZMÉNY ÉS KOSÁR ADATOK ---

// Beállítások lekérése az adatbázisból (pl. hány termékre alkalmazható a kedvezmény)
$settings_res = $conn->query("SELECT max_discounted_items FROM SETTINGS LIMIT 1");
$settings = $settings_res->fetch_assoc();

// Maximálisan kedvezményezhető termékek száma (ha nincs adat, alapértelmezett: 1)
$max_allowed_discounted = $settings['max_discounted_items'] ?? 1;

// Alapértelmezett kedvezmény értékek inicializálása
$discount = 0;
$expiry_timestamp = 0;

// Ellenőrizzük, hogy a felhasználó be van-e jelentkezve
if (isset($_SESSION['user_id'])) {
    // Ha van érvényes kupon a session-ben és még nem járt le
    if (isset($_SESSION['coupon_expiry']) && $_SESSION['coupon_expiry'] > time()) {

        $discount = $_SESSION['coupon_discount'] ?? 0;          // Kedvezmény mértéke (%)
        $expiry_timestamp = $_SESSION['coupon_expiry'] * 1000;  // Lejárati idő (ms, JS kompatibilitás miatt)

    } else {
        // Ha a kupon lejárt vagy nem érvényes, töröljük a session-ből
        unset($_SESSION['coupon_discount']);
        unset($_SESSION['coupon_expiry']);
    }
}



//=============== KATEGÓRIÁK LEKÉRÉSE ===============//
// Az összes kategória lekérdezése az adatbázisból,
// amelyeket a navigációs menüben és a terméklista szűréséhez használunk
$categories = $conn->query("SELECT * FROM CATEGORIES");

//=============== FELHASZNÁLÓI SZEREPKÖR ===============//
// Ellenőrizzük, hogy a bejelentkezett felhasználó admin-e (role = 2)
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] == '2';

//=============== KOSÁR KEZELÉSE (POST KÉRÉS) ===============//
// Akkor fut le, ha a felhasználó terméket ad a kosárhoz
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {

    // Csak bejelentkezett vásárló (role = 0) tehet terméket a kosárba
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] != '0') {
        header("Location: termekeink.php?error=no_permission");
        exit;
    }

    // Termék ID biztonságos egész számmá alakítása
    $p_id = (int)$_POST['id'];
    
    // Termék lekérdezése az adatbázisból (név, ár, készlet)
    $stmt = $conn->prepare("SELECT name, price, stock FROM PRODUCTS WHERE id = ?");
    $stmt->bind_param("i", $p_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    // Ha a termék létezik
    if ($product = $res->fetch_assoc()) {
        // Ha még nincs kosár session, inicializáljuk
        if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
        
        //=============== KÉSZLET ELLENŐRZÉS ===============//
        // Megszámoljuk, hogy az adott termékből mennyi van már a kosárban
        $total_in_cart = 0;
        foreach($_SESSION['cart'] as $item) {
            if ($item['product_id'] == $p_id) {
                $total_in_cart += $item['quantity'];
            }
        }

        // Csak akkor engedjük hozzáadni, ha van még készlet
        if ($product['stock'] > $total_in_cart) {
            

            //=============== KUPON LOGIKA ===============//
            // Ha van aktív kedvezmény (pl. kupon)
            if ($discount > 0) {

                // Egyedi kulcs az akciós termékhez (külön tároljuk a kosárban)
                $discount_key = $p_id . "_discounted";

                // Jelenlegi akciós darabszám lekérdezése
                $discounted_qty = $_SESSION['cart'][$discount_key]['quantity'] ?? 0;


                // Ellenőrizzük, hogy belefér-e még a kedvezményes darabszám limitbe
                if ($discounted_qty < $max_allowed_discounted) {

                    // Kedvezményes ár kiszámítása (% alapján)
                    $price_after_discount = $product['price'] * (1 - ($discount / 100));
                    
                    // Ha még nincs ilyen akciós termék a kosárban -> hozzáadás
                    if (!isset($_SESSION['cart'][$discount_key])) {
                        $_SESSION['cart'][$discount_key] = [
                            'product_id' => $p_id,
                            'name' => $product['name'] . " (Akciós)",
                            'price' => $price_after_discount,
                            'quantity' => 1
                        ];
                    } else {
                        // Ha már van → darabszám növelése
                        $_SESSION['cart'][$discount_key]['quantity']++;
                    }
                } else {
                    //=============== NORMÁL ÁR (LIMIT ELÉRVE) ===============//
                    // Ha elfogyott az akciós keret, normál áron kerül a kosárba
                    if (!isset($_SESSION['cart'][$p_id])) {
                        $_SESSION['cart'][$p_id] = [
                            'product_id' => $p_id,
                            'name' => $product['name'],
                            'price' => $product['price'],
                            'quantity' => 1
                        ];
                    } else {
                        $_SESSION['cart'][$p_id]['quantity']++;
                    }
                }
            } else {
                //=============== NINCS KEDVEZMÉNY ===============//
                // Alap működés: normál ár
                if (!isset($_SESSION['cart'][$p_id])) {
                    $_SESSION['cart'][$p_id] = [
                        'product_id' => $p_id,
                        'name' => $product['name'],
                        'price' => $product['price'],
                        'quantity' => 1
                    ];
                } else {
                    $_SESSION['cart'][$p_id]['quantity']++;
                }
            }
            
            // Sikeres hozzáadás után visszairányítás
            header("Location: termekeink.php?added=1");
            exit;

        } else {
            //=============== NINCS KÉSZLET ===============//
            // Ha nincs elegendő készlet
            header("Location: termekeink.php?error=no_stock");
            exit;
        }
    }
}

// Kosár összesített darabszámának kiszámolása a fejlécben megjelenítéshez
// Végigiterál a session-ben tárolt kosár elemein, és összeadja a darabszámokat
$total_items = 0;

if (!empty($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $total_items += $item['quantity'];  // Minden termék mennyiségének hozzáadása az összeghez
    }
}
