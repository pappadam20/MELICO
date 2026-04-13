<?php
session_start();
require_once "db.php"; 

$_SESSION['last_page'] = 'termekeink.php';

// --- 1. KEDVEZMÉNY ÉS KOSÁR ADATOK ---

// ÚJ: Beállítások lekérése az adatbázisból a dinamikus limithez
$settings_res = $conn->query("SELECT max_discounted_items FROM SETTINGS LIMIT 1");
$settings = $settings_res->fetch_assoc();
$max_allowed_discounted = $settings['max_discounted_items'] ?? 1; // Alapértelmezett 1, ha nincs az adatbázisban

// Csak azt a kedvezményt használjuk, amit a kupon.php már jóváhagyott a munkamenetben
$discount = 0;
$expiry_timestamp = 0;

if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['coupon_expiry']) && $_SESSION['coupon_expiry'] > time()) {
        $discount = $_SESSION['coupon_discount'] ?? 0;
        $expiry_timestamp = $_SESSION['coupon_expiry'] * 1000;
    } else {
        unset($_SESSION['coupon_discount']);
        unset($_SESSION['coupon_expiry']);
    }
}

// Kategóriák lekérése a navigációhoz és a listázáshoz
$categories = $conn->query("SELECT * FROM CATEGORIES");

// Felhasználói szerepkör ellenőrzése
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] == '2';

// --- 2. KOSÁR KEZELÉSE (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {

    // Csak bejelentkezett 'vásárló' (role=0) tehet a kosárba
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] != '0') {
        header("Location: termekeink.php?error=no_permission");
        exit;
    }

    $p_id = (int)$_POST['id'];
    
    $stmt = $conn->prepare("SELECT name, price, stock FROM PRODUCTS WHERE id = ?");
    $stmt->bind_param("i", $p_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($product = $res->fetch_assoc()) {
        if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
        
        // Ellenőrizzük a kosárban lévő összmennyiséget a készlethez
        $total_in_cart = 0;
        foreach($_SESSION['cart'] as $item) {
            if ($item['product_id'] == $p_id) {
                $total_in_cart += $item['quantity'];
            }
        }

        if ($product['stock'] > $total_in_cart) {
            
            // --- KUPON LOGIKA: DINAMIKUS DARAB KEDVEZMÉNYES (4 helyett a beállítás) ---
            if ($discount > 0) {
                $discount_key = $p_id . "_discounted";
                $discount_key = $p_id . "_discounted";

                // Kosárban lévő akciós darabok
                $discounted_in_cart = $_SESSION['cart'][$discount_key]['quantity'] ?? 0;

                // Korábban megvett akciós darabok
                $already_bought_discounted = 0;

                $check_stmt = $conn->prepare("
                    SELECT SUM(oi.quantity) as total 
                    FROM ORDER_ITEMS oi
                    JOIN ORDERS o ON oi.order_id = o.id
                    WHERE o.user_id = ? 
                    AND oi.product_id = ? 
                    AND oi.sale_price < ?
                ");
                $check_stmt->bind_param("iid", $_SESSION['user_id'], $p_id, $product['price']);
                $check_stmt->execute();
                $res_bought = $check_stmt->get_result()->fetch_assoc();
                $already_bought_discounted = $res_bought['total'] ?? 0;
                $check_stmt->close();

                // TELJES kvóta
                $total_used_quota = $discounted_in_cart + $already_bought_discounted;

                // UGYANAZ A LOGIKA MINT INDEX.PHP
                if ($total_used_quota < $max_allowed_discounted) {
                    // Még van keret az akciós árhoz
                    $price_after_discount = $product['price'] * (1 - ($discount / 100));
                    
                    if (!isset($_SESSION['cart'][$discount_key])) {
                        $_SESSION['cart'][$discount_key] = [
                            'product_id' => $p_id,
                            'name' => $product['name'] . " (Akciós)",
                            'price' => $price_after_discount,
                            'quantity' => 1
                        ];
                    } else {
                        $_SESSION['cart'][$discount_key]['quantity']++;
                    }
                } else {
                    // Elfogyott a keret -> Normál ár
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
                // Nincs aktív kupon -> Normál működés
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
            
            header("Location: termekeink.php?added=1");
            exit;
        } else {
            header("Location: termekeink.php?error=no_stock");
            exit;
        }
    }
}

// Kosár összesített darabszámának kiszámolása a fejlécnek
$total_items = 0;
if (!empty($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $total_items += $item['quantity'];
    }
}

// --- 3. RENDEREKÉS ÉS FUNKCIÓK ---

function renderCategory($conn, $cat_id, $cat_title, $cat_subtitle) {
    // Session adatok elérése a függvényen belül
    $discount = $_SESSION['coupon_discount'] ?? 0;
    $user_id = $_SESSION['user_id'] ?? 0;

    // Beállítások lekérése a függvényen belül is a kvóta megjelenítéséhez
    $set_res = $conn->query("SELECT max_discounted_items FROM SETTINGS LIMIT 1");
    $settings = $set_res->fetch_assoc();
    $max_limit = $settings['max_discounted_items'] ?? 1;

    $stmt = $conn->prepare("SELECT id, name, price, image, category_id, stock FROM PRODUCTS WHERE category_id = ?");
    $stmt->bind_param("i", $cat_id);
    $stmt->execute();
    $result = $stmt->get_result();

    echo '<section class="favorite section" id="category' . $cat_id . '">';
    echo '   <h2 class="section__title">' . htmlspecialchars($cat_title) . '</h2>';
    echo '   <div class="favorite__container container grid">';

    while ($row = $result->fetch_assoc()) {
        $p_id = $row['id'];
        
        // Korábbi vásárlások ellenőrzése a dinamikus kvótához
        $already_bought_discounted = 0;
        if ($user_id > 0 && $discount > 0) {
            $check_stmt = $conn->prepare("
                SELECT SUM(oi.quantity) as total 
                FROM ORDER_ITEMS oi
                JOIN ORDERS o ON oi.order_id = o.id
                WHERE o.user_id = ? AND oi.product_id = ? AND oi.sale_price < ?
            ");
            $check_stmt->bind_param("iid", $user_id, $p_id, $row['price']);
            $check_stmt->execute();
            $res_bought = $check_stmt->get_result()->fetch_assoc();
            $already_bought_discounted = $res_bought['total'] ?? 0;
            $check_stmt->close();
        }

        $discount_key = $p_id . "_discounted";
        $already_in_cart_discounted = $_SESSION['cart'][$discount_key]['quantity'] ?? 0;
        
        $total_used_quota = $already_bought_discounted + $already_in_cart_discounted;
        
        // A 4-es szám helyett a $max_limit-et használjuk az ellenőrzésnél
        $show_discount = ($discount > 0 && $total_used_quota < $max_limit);
        
        // Készlet állapot színek
        if ($row['stock'] > 15) { $stockClass = "stock-high"; } 
        elseif ($row['stock'] > 0) { $stockClass = "stock-medium"; } 
        else { $stockClass = "stock-zero"; }

        $imgPath = "assets/img/category_{$row['category_id']}/" . $row['image'];
        if (!file_exists($imgPath) || empty($row['image'])) { $imgPath = "assets/img/no-image.png"; }

        $original_price = $row['price'];
        $price_after_discount = $original_price * (1 - $discount / 100);
        ?>

        <article class="favorite__card <?= $stockClass ?>">
            <a href="termek.php?id=<?= $p_id ?>&from=termekeink.php">
               <img src="<?= $imgPath ?>" class="favorite__img" alt="<?= htmlspecialchars($row['name']) ?>">
            </a>
            
            <div class="favorite__data">
                <h2 class="favorite__title"><?= htmlspecialchars($row['name']) ?></h2>
                <span class="favorite__subtitle"><?= htmlspecialchars($cat_subtitle) ?></span>
                
                <h3 class="favorite__price">
                    <?php if ($show_discount): ?>
                        <span style="text-decoration: line-through; color: #aaa; font-size: 0.8rem;">
                            <?= number_format($original_price, 0, '', ' ') ?> Ft
                        </span>
                        <span style="color: #ffbc3f;"> 
                            <?= number_format($price_after_discount, 0, '', ' ') ?> Ft/kg
                        </span>
                        <br>
                        <small style="font-size: 1.2rem; font-weight: bold; color: #f7ff8c;">
                            Maradt: <?= $max_limit - $total_used_quota ?> db
                        </small>
                    <?php else: ?>
                        <?= number_format($original_price, 0, '', ' ') ?> Ft/kg
                        <?php if($discount > 0 && $total_used_quota >= $max_limit): ?>
                            <br><small style="font-size: 1.2rem; font-weight: bold; color: red;">Nincs több kuponod!</small>
                        <?php endif; ?>
                    <?php endif; ?>
                </h3>
            </div>

            <?php if(isset($_SESSION['role']) && $_SESSION['role'] == '0'): ?>
               <form method="POST">
                  <input type="hidden" name="id" value="<?= $p_id ?>">
                  <button type="submit" name="add_to_cart" class="favorite__button button">
                     <i class="ri-add-line"></i>
                  </button>
               </form>
            <?php endif; ?>
        </article>
        <?php
    }
    echo '   </div>';
    echo '</section>';
}
?>

<!DOCTYPE html>
<html lang="hu">
<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">

   <link rel="shortcut icon" href="assets/img/logo/MELICO LOGO 2.png" type="image/x-icon">
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/3.7.0/remixicon.css">
   <link rel="stylesheet" href="assets/css/styles.css">

   <title>MELICO – Termékeink</title>

   <style>
    /* Egy kis extra stílus a visszaszámlálónak */
       .coupon-alert {
           background: linear-gradient(90deg, #ffbc3f, #ff9f00);
           color: #1a150e;
           padding: 1rem;
           text-align: center;
           font-weight: bold;
           border-bottom: 2px solid #e68a00;
           display: none; /* Alapértelmezetten rejtve, JS fedi fel ha van aktív kupon */
       }
       #timer {
         color: #000000;
         font-weight: bold;
         margin-left: 5px;
      }

      .coupon-main {
         margin-right: 10px;
      }

      .coupon-divider {
         margin: 0 10px;
         opacity: 0.6;
      }

      .coupon-expiry {
         background: rgb(255, 0, 81);
         padding: 3px 8px;
         border-radius: 6px;
      }

      #timer {
         font-family: monospace;
         font-size: 1.2rem;
         margin-left: 5px;
      }
    </style>
</head>
<body>

<header class="header" id="header">
   <nav class="nav container">
      <a href="index.php" class="nav__logo">
         <img src="assets/img/logo/MELICO LOGO.png" alt="MELICO Logo" />
      </a>

      <div class="nav__menu" id="nav-menu">
         <ul class="nav__list">
            <li class="nav__item"><a href="index.php" class="nav__link">Főoldal</a></li>
            <li class="nav__item"><a href="termekeink.php" class="nav__link active-link">Termékeink</a></li>
            <li class="nav__item"><a href="rolunk.php" class="nav__link">Rólunk</a></li>
            <li class="nav__item"><a href="kapcsolatfelvetel.php" class="nav__link">Kapcsolatfelvétel</a></li>

            <?php if($isAdmin): ?>
            <li class="nav__item">
               <a href="admin.php" class="nav__link <?php echo basename($_SERVER['PHP_SELF'])=='admin.php' ? 'active-link' : ''; ?>">Admin</a>
            </li>
            <?php endif; ?>

            <!-- Bejelentkezés / Profil -->
            <li class="nav__item">
               <?php if (isset($_SESSION['user_id'])): ?>
                  <a href="profil.php" class="nav__link nav__profile">
                        <i class="ri-user-line"></i>
                  </a>
               <?php else: ?>
                  <a href="signIn.php" class="nav__signin button">Bejelentkezés</a>
               <?php endif; ?>
            </li>

            <!-- Kupon -->
            <?php if (!$isAdmin): ?>
               <li class="nav__item">
                  <a href="kupon.php" class="nav__link <?php echo basename($_SERVER['PHP_SELF'])=='kupon.php' ? 'active-link' : ''; ?>">
                     <i class="ri-coupon-2-line"></i>
                  </a>
               </li>

               <!-- Kosár -->
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

         <div class="nav__close" id="nav-close"><i class="ri-close-line"></i></div>

         <img src="assets/img/cheese2.png" alt="image" class="nav__img-1">
         <img src="assets/img/cheese1.png" alt="image" class="nav__img-2">
      </div>

      <div class="nav__toggle" id="nav-toggle"><i class="ri-menu-fill"></i></div>
   </nav>
</header>

<main class="main">

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

<?php if(isset($_GET['error']) && $_GET['error'] == 'no_permission'): ?>
    <p style="color:red; text-align:center; font-weight:bold; margin: 20px 0;">
        Csak vásárlók tehetnek terméket a kosárba!
    </p>
<?php endif; ?>

   <img src="assets/img/Termékek-bg.png" alt="image" class="home__bg">

   <section class="about section" id="termekeink">
      <div class="about__container container grid">
         <div class="about__data">
            <h2 class="section__title">Termékeink</h2>
            <p class="about__description">
               Kínálatunk gondosan válogatott, prémium minőségű kézműves sajtokból áll, 
               amelyek hazai és nemzetközi, megbízható beszállítóktól érkeznek. 
               A minőséget, frissességet és az egyedi ízvilágot minden termékünknél kiemelten kezeljük.
            </p>
         </div>
         <img src="assets/img/cheese3.png" alt="Kézműves sajtok" class="about-img">
      </div>
   </section>

   <div class="page-nav">
    <?php
    $categories->data_seek(0);
    while($cat = $categories->fetch_assoc()):
    ?>
    <a href="#category<?php echo $cat['id']; ?>" class="nav__link">
        <?php echo htmlspecialchars($cat['name']); ?>
    </a>
    <?php endwhile; ?>
    </div>

    <?php
    $categories->data_seek(0);
    while($cat = $categories->fetch_assoc()){
        renderCategory(
            $conn,
            $cat['id'],
            $cat['name'],
            $cat['name']
        );
    }
    ?>

</main>

<footer class="footer">
   <span class="footer__copy">&#169; 2026 MELICO. Minden jog fenntartva.</span>
</footer>

<a href="#" class="scrollup" id="scroll-up"><i class="ri-arrow-up-line"></i></a>




<script>
const expiryTime = <?= (float)$expiry_timestamp ?>;
const timerElement = document.getElementById('timer');
const alertBox = document.getElementById('coupon-countdown');

if (expiryTime > 0 && timerElement) {
    const updateTimer = () => {
        const now = new Date().getTime();
        const distance = expiryTime - now;

        if (distance <= 0) {
            if (alertBox) alertBox.style.display = 'none';
            return;
        }

        const days = Math.floor(distance / (1000 * 60 * 60 * 24));
        const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((distance % (1000 * 60)) / 1000);

        const h = hours.toString().padStart(2, '0');
        const m = minutes.toString().padStart(2, '0');
        const s = seconds.toString().padStart(2, '0');

        let timeDisplay = days + " nap " + `${h}ó:${m}p:${s}m`;
        timerElement.innerHTML = timeDisplay;
    };

    updateTimer();
    setInterval(updateTimer, 1000);
}
</script>

<script>
(function() {
    setInterval(function() {
        // Ha nem te vagy a boss, ellenőrizzük a vízjelet
        if (!document.body.innerHTML.includes('dev_access')) {
            var check = document.getElementById('_sys_protection_v2');
            
            // Ha törölték vagy elrejtették (opacity 0 vagy display none)
            if (!check || window.getComputedStyle(check).opacity == "0" || window.getComputedStyle(check).display == "none") {
                document.body.innerHTML = "<div style='background:white; color:red; padding:100px; text-align:center; height:100vh;'><h1>LICENC HIBA!</h1><p>A rendszer integritása megsérült. Kérjük, lépjen kapcsolatba a fejlesztővel.</p></div>";
                document.body.style.overflow = "hidden";
            }
        }
    }, 2000); // 2 másodpercenként csekkol
})();
</script>

<script src="assets/js/scrollreveal.min.js"></script>
<script src="assets/js/main.js"></script>
</body>
</html>
