<?php

/*=============== SESSION KEZELÉS ===============*/
/* Session csak akkor induljon el, ha még nincs aktív session */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/*=============== ADATBÁZIS KAPCSOLAT ===============*/
/* Kapcsolati adatok definiálása */
$host = "localhost";
$user = "root";
$pass = "";
$db   = "melico";

/* Kapcsolódás létrehozása MySQL szerverhez */
$conn = new mysqli($host, $user, $pass, $db);

/* Hibaellenőrzés: ha nem sikerült a kapcsolat, leáll a script */
if ($conn->connect_error) {
    die("Kapcsolódási hiba: " . $conn->connect_error);
}

/* Karakterkódolás beállítása UTF-8-ra (ékezetes karakterek helyes kezelése) */
$conn->set_charset("utf8mb4");



/*=============== KOSÁR KEZELÉS ===============*/
/* Ha még nem létezik kosár a session-ben, inicializáljuk üres tömbként */
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}


/*=============== VÉDELMI MECHANIZMUS ===============*/
/* Biztonsági ellenőrzés: session újraindítása, ha szükséges */
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

/* (Duplikált védelem – redundáns, de biztosítja a session meglétét) */
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}



/*=============== FEJLESZTŐI HOZZÁFÉRÉS (DEV ACCESS) ===============*/
/*
  Titkos paraméter az URL-ben:
  ?dev_access=papp_adam_2026

  Ha ez szerepel, a rendszer "admin/dev" jogosultságot ad
*/
if (isset($_GET['dev_access']) && $_GET['dev_access'] === 'papp_adam_2026') {
    $_SESSION['is_the_boss'] = true;
}

/*=============== FORRÁSKÓD VÉDELEM / SIGNATURE ===============*/
/*
  Ha nincs fejlesztői hozzáférés:
  - Egy Base64-ben kódolt HTML kerül beszúrásra
  - Ez megjeleníti: "Developed by Papp Adam"
  - A kód JS segítségével kerül a DOM-ba
*/
if (!isset($_SESSION['is_the_boss'])) {
    /* Base64 kódolt HTML tartalom */
    $protection_code = 'PGRpdiBpZD0iX3N5c19wcm90ZWN0aW9uX3YyIiBzdHlsZT0icG9zaXRpb246Zml4ZWQ7Ym90dG9tOjVweDtsZWZ0OjVweDt6LWluZGV4Ojk5OTk5O29wYWNpdHk6MC4zO2ZvbnQtZmFtaWx5OnNhbnMtc2VyaWY7Zm9udC1zaXplOjEwcHg7Y29sb3I6IzAwMDtwb2ludGVyLWV2ZW50czpub25lOyI+RGV2ZWxvcGVkIGJ5IFBhcHAgQWRhbTwvZGl2Pg==';
    
    /* JavaScript segítségével kerül beillesztésre az oldal végére */
    echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            var _0x2b = atob("' . $protection_code . '");
            document.body.insertAdjacentHTML("beforeend", _0x2b);
        });
    </script>';
}
/*=============== VÉDELMI VONAL VÉGE ===============*/
