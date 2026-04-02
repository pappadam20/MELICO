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



<!DOCTYPE html>
<html lang="hu">
<head>
   <!--=============== META INFORMÁCIÓK ===============-->
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">

   <!--=============== FAVICON ===============-->
   <link rel="shortcut icon" href="assets/img/logo/MELICO LOGO 2.png" type="image/x-icon">

   <!--=============== IKONOK (REMIX ICON) ===============-->
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/3.7.0/remixicon.css">

   <!--=============== SAJÁT STÍLUSLAP ===============-->
   <link rel="stylesheet" href="assets/css/styles.css">

   <title>MELICO – Kapcsolatfelvétel</title>


   <!--=============== KUPON VISSZASZÁMLÁLÓ STÍLUS ===============-->
   <style>

      /* Kupon értesítési sáv:
         A felhasználó számára megjelenő figyelmeztető sáv, amely az aktuális kuponról ad információt.
         Alapból rejtett (display: none), és JavaScript jeleníti meg dinamikusan. */
       .coupon-alert {
           background: linear-gradient(90deg, #ffbc3f, #ff9f00);
           color: #1a150e;
           padding: 1rem;
           text-align: center;
           font-weight: bold;
           border-bottom: 2px solid #e68a00;
           display: none; /* JS jeleníti meg */
       }

       /* Visszaszámláló (timer):
          A kupon lejárati idejét mutatja valós időben. */
       #timer {
         color: #000000;
         font-weight: bold;
         margin-left: 5px;
      }

      /* Kupon fő tartalom:
         A kupon szöveges részének elrendezéséhez használt elem. */
      .coupon-main {
         margin-right: 10px;
      }

      /* Elválasztó elem:
         Vizuális elválasztást biztosít a kupon információk között. */
      .coupon-divider {
         margin: 0 10px;
         opacity: 0.6;
      }

      /* Kupon lejárati jelzés:
         Kiemeli a lejárati időt egy figyelemfelkeltő háttérrel. */
      .coupon-expiry {
         background: rgb(255, 0, 81);
         padding: 3px 8px;
         border-radius: 6px;
      }

      /* Timer részletes stílus:
         Monospace betűtípus a pontos idő megjelenítéshez,
         nagyobb méret a jobb olvashatóság érdekében. */
      #timer {
         font-family: monospace;
         font-size: 1.2rem;
         margin-left: 5px;
      }
    </style>
</head>
<body>

   <!--==================== HEADER (NAVIGÁCIÓ) ====================-->
   <!--
   A fejléc tartalmazza a fő navigációs menüt.
   PHP feltételek alapján dinamikusan változik:
   - aktív oldal kiemelése
   - admin menüpont megjelenítése
   - bejelentkezett felhasználó kezelése
   -->
   <header class="header" id="header">
      <nav class="nav container">

         <!-- LOGÓ -->
         <a href="index.php" class="nav__logo">
            <img src="assets/img/logo/MELICO LOGO.png" alt="MELICO Logo" />
         </a>

         <!-- NAVIGÁCIÓS MENÜ -->
         <div class="nav__menu" id="nav-menu">
            <ul class="nav__list">

               <!-- FŐOLDAL -->
               <li class="nav__item">
                  <a href="index.php" class="nav__link <?= basename($_SERVER['PHP_SELF'])=='index.php' ? 'active-link' : ''; ?>">Főoldal</a>
               </li>

               <!-- TERMÉKEK -->
               <li class="nav__item">
                  <a href="termekeink.php" class="nav__link <?= basename($_SERVER['PHP_SELF'])=='termekeink.php' ? 'active-link' : ''; ?>">Termékeink</a>
               </li>

               <!-- RÓLUNK -->
               <li class="nav__item">
                  <a href="rolunk.php" class="nav__link <?= basename($_SERVER['PHP_SELF'])=='rolunk.php' ? 'active-link' : ''; ?>">Rólunk</a>
               </li>

               <!-- KAPCSOLAT -->
               <li class="nav__item">
                  <a href="kapcsolatfelvetel.php" class="nav__link <?= basename($_SERVER['PHP_SELF'])=='kapcsolatfelvetel.php' ? 'active-link' : ''; ?>">Kapcsolatfelvétel</a>
               </li>

               <!-- ADMIN MENÜ (CSAK ADMINNAK) -->
               <?php if($isAdmin): ?>
               <li class="nav__item">
                  <a href="admin.php" class="nav__link <?= basename($_SERVER['PHP_SELF'])=='admin.php' ? 'active-link' : ''; ?>">Admin</a>
               </li>
               <?php endif; ?>

               <!-- FELHASZNÁLÓ / BEJELENTKEZÉS -->
               <li class="nav__item">
                  <?php if (isset($_SESSION['user_id'])): ?>
                     <!-- PROFIL IKON -->
                     <a href="profil.php" class="nav__link nav__profile">
                           <i class="ri-user-line"></i>
                     </a>
                  <?php else: ?>
                     <!-- BEJELENTKEZÉS -->
                     <a href="signIn.php" class="nav__signin button">Bejelentkezés</a>
                  <?php endif; ?>
               </li>

               <!-- KUPON (NEM ADMIN) -->
               <?php if (!$isAdmin): ?>
                  <li class="nav__item">
                     <a href="kupon.php" class="nav__link <?php echo basename($_SERVER['PHP_SELF'])=='kupon.php' ? 'active-link' : ''; ?>">
                        <i class="ri-coupon-2-line"></i>
                     </a>
                  </li>

                  <!-- KOSÁR -->
                  <li class="nav__item">
                     <a href="kosar.php" class="nav__link"><i class="ri-shopping-cart-fill"></i>
                     <?php 
                     $total_items = 0;
                     if (!empty($_SESSION['cart'])) {
                        foreach ($_SESSION['cart'] as $item) {
                              $total_items += $item['quantity'];
                        }
                        if ($total_items > 0) echo "($total_items)";
                     }
                     ?>
                     </a>
                  </li>
               <?php endif; ?>
            </ul>

            <!-- MENÜ BEZÁRÁS IKON (MOBIL) -->
            <div class="nav__close" id="nav-close">
               <i class="ri-close-line"></i>
            </div>

            <!-- DEKORATÍV KÉPEK -->
            <img src="assets/img/cheese2.png" alt="image" class="nav__img-1">
            <img src="assets/img/cheese1.png" alt="image" class="nav__img-2">
         </div>

         <!-- MOBIL MENÜ NYITÁS -->
         <div class="nav__toggle" id="nav-toggle">
            <i class="ri-menu-fill"></i>
         </div>
      </nav>
   </header>



   <!--==================== MAIN TARTALOM ====================-->
   <main class="main">

   <!-- KUPÓN VISSZASZÁMLÁLÓ -->
   <?php if ($discount > 0): ?>
         <div id="coupon-countdown" class="coupon-alert" style="display: block;">
            <i class="ri-time-line"></i> 

            <span class="coupon-main">
               FIGYELEM! Van egy <strong><?= $discount ?>%-os</strong> kuponod! Lejár:
            </span>

            <span class="coupon-expiry">
               <span id="timer">--:--:--</span>
            </span>
         </div>
         <?php endif; ?>

   <!-- HÁTTÉRKÉP -->
   <img src="assets/img/RendelésÉSKapcsolat-bg.png" alt="image" class="home__bg">



   <!--==================== KAPCSOLAT ====================-->
   <!-- Cég bemutatása és ügyfélszolgálat -->
   <section class="about section about--reverse">
      <div class="about__container container grid">

         <div class="about__data">
            <h2 class="section__title">Kapcsolat</h2>

            <p class="about__description">
               Ha kérdése van rendeléseinkkel, termékeinkkel vagy a kiszállítással kapcsolatban,
               ügyfélszolgálatunk készséggel áll rendelkezésére.
               Célunk, hogy minden vásárlónk gyors és pontos választ kapjon.
            </p>

            <p class="about__description">
               Elérhet minket e-mailben vagy telefonon munkanapokon,
               illetve személyesen is fogadjuk előre egyeztetett időpontban.
            </p>
         </div>

         <img src="assets/img/home-melico2.PNG" alt="Kapcsolat" class="about-img">
      </div>
   </section>

   <!--==================== RENDELÉS ÉS ÜGYINTÉZÉS ====================-->
   <section class="about section">
      <div class="about__container container grid">

         <img src="assets/img/cheese4.png" alt="Rendelés" class="about-img">

         <div class="about__data">
            <h2 class="section__title">Rendelés és ügyintézés</h2>

            <p class="about__description">
               A rendelés a webáruházon keresztül néhány egyszerű lépésben leadható.
               A kiválasztott termékek kosárba helyezése után
               biztonságos fizetési felületen véglegesítheti vásárlását.
            </p>

            <p class="about__description">
               A rendelés állapotáról e-mailben küldünk visszaigazolást,
               valamint tájékoztatást a kiszállítás várható időpontjáról.
               Amennyiben kérdés merülne fel, ügyfélszolgálatunk segít.
            </p>
         </div>
      </div>
   </section>

   <!--==================== ELHELYEZKEDÉS ====================-->
   <section class="about section about--reverse">
      <div class="about__container container grid">

         <div class="about__data">
            <h2 class="section__title">Hol talál minket?</h2>

            <p class="about__description">
               Központunk Budapesten található,
               ahol az adminisztráció, a raktározás és a kiszállítás koordinálása történik.
               Személyes átvétel kizárólag előzetes egyeztetés alapján lehetséges.
            </p>

            <p class="about__description">
               Telephelyünk könnyen megközelíthető tömegközlekedéssel és autóval egyaránt,
               így partnereink és vásárlóink számára is jól elérhető.
            </p>

            <!-- CÍM -->
            <ul class="about__description">
               <li>1095 Budapest, Ipar utca 12.</li>
               <li>Hétfő – Péntek: 9:00 – 18:00</li>
            </ul>
         </div>

         <!-- GOOGLE MAPS -->
         <div class="map__container">
            <iframe
               src="https://www.google.com/maps?q=1095+Budapest,+Ipar+utca+12&output=embed"
               loading="lazy"
               referrerpolicy="no-referrer-when-downgrade">
            </iframe>
         </div>
      </div>
   </section>
