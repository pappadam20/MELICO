/*=============== SHOW MENU ===============*/
const navMenu = document.getElementById('nav-menu'),
      navToggle = document.getElementById('nav-toggle'),
      navClose = document.getElementById('nav-close')

/* Menu show */
if(navToggle){
    navToggle.addEventListener('click', () =>{
        navMenu.classList.add('show-menu')
    })
}

/* Menu hidden */
if(navClose){
    navClose.addEventListener('click', () =>{
        navMenu.classList.remove('show-menu')
    })
}


/*=============== REMOVE MENU MOBILE ===============*/
const navLink = document.querySelectorAll('.nav__link')

const linkAction = () =>{
    const navMenu = document.getElementById('nav-menu')
    // Amikor rákattintunk az egyes nav__link elemekre, eltávolítjuk a show-menu osztályt
    navMenu.classList.remove('show-menu')
}
navLink.forEach(n => n.addEventListener('click', linkAction))


/*=============== ADD BLUR HEADER ===============*/
const blurHeader = () =>{
    const header = document.getElementById('header')
    // Ha az alsó eltolás meghaladja a nézőtér magasságának 50%-át, akkor a fejléchez adjuk hozzá a „blur-header” osztályt
    this.scrollY >= 50 ? header.classList.add('blur-header') 
                       : header.classList.remove('blur-header')
}
window.addEventListener('scroll', blurHeader)


/*=============== SHOW SCROLL UP ===============*/ 
const scrollUp = () =>{
    const scrollUp = document.getElementById('scroll-up')
    // Ha a görgetés a nézőtér magasságának 350%-át meghaladja, akkor add hozzá a
    this.scrollY >= 350 ? scrollUp.classList.add('show-scroll') 
                       : scrollUp.classList.remove('show-scroll')
}
window.addEventListener('scroll', scrollUp)


/*=============== SCROLL SECTIONS ACTIVE LINK ===============*/
const sections = document.querySelectorAll('section[id]')
    
const scrollActive = () =>{
  	const scrollDown = window.scrollY

	sections.forEach(current =>{
		const sectionHeight = current.offsetHeight,
			  sectionTop = current.offsetTop - 58,
			  sectionId = current.getAttribute('id'),
			  sectionsClass = document.querySelector('.nav__menu a[href*=' + sectionId + ']')

		if(scrollDown > sectionTop && scrollDown <= sectionTop + sectionHeight){
			sectionsClass.classList.add('active-link')
		}else{
			sectionsClass.classList.remove('active-link')
		}                                                    
	})
}
window.addEventListener('scroll', scrollActive)




/*=============== SCROLL REVEAL ANIMATION ===============*/
const sr = ScrollReveal({
    origin: 'top',
    distance: '40px',
    opacity: 1,
    scale: 1.1,
    duration: 2500,
    delay: 300,
    //reset: true, // Az animációk ismétlődnek
})



const signInBtn = document.getElementById("signInBtn");

signInBtn.addEventListener("click", () => {
    window.location.href = "signIn.html";
});




/*=============== ÚJ: FELHASZNÁLÓI ÁLLAPOT KEZELÉSE ===============*/
function updateUI() {
    const isLoggedIn = localStorage.getItem('isLoggedIn');
    const loginItem = document.getElementById('nav-login-item');
    const userItem = document.getElementById('nav-user-item');
    const userEmailDisplay = document.getElementById('user-email-display');
    const loginTimeInfo = document.getElementById('login-time-info');

    if (isLoggedIn === 'true') {
        if(loginItem) loginItem.style.display = 'none';
        if(userItem) userItem.style.display = 'block';
        
        if(userEmailDisplay) userEmailDisplay.innerText = localStorage.getItem('userEmail');
        if(loginTimeInfo) loginTimeInfo.innerText = "Belépve: " + localStorage.getItem('lastLogin');
    } else {
        // Ha nincs belépve, biztosítsuk a Login gomb megjelenését
        if(loginItem) loginItem.style.display = 'block';
        if(userItem) userItem.style.display = 'none';
    }
}

// Dropdown megnyitása
const profileIcon = document.getElementById('profileIcon');
if (profileIcon) {
    profileIcon.addEventListener('click', (e) => {
        const dropdown = document.getElementById('profileDropdown');
        if(dropdown) dropdown.classList.toggle('show-dropdown');
        e.stopPropagation();
    });
}

// Kattintás kívülre -> Dropdown bezárása
window.addEventListener('click', () => {
    const dropdown = document.getElementById('profileDropdown');
    if(dropdown) dropdown.classList.remove('show-dropdown');
});

// Kijelentkezés funkció hozzáadása
const logoutBtn = document.getElementById('logoutBtn');
if (logoutBtn) {
    logoutBtn.addEventListener('click', (e) => {
        e.preventDefault();
        localStorage.clear(); // Mindent töröl (isLoggedIn, userEmail, stb.)
        window.location.href = 'index.html'; // Visszadob a főoldalra
    });
}

document.addEventListener('DOMContentLoaded', updateUI);

// Oldal betöltésekor fut le
document.addEventListener('DOMContentLoaded', checkLoginStatus);



sr.reveal(`.home__data, .about__img, .about__data, .visit__data`)

sr.reveal(`.home__image, .footer__img-1, .footer__img-2`, {rotate: {z: -15} })
sr.reveal(`.home__cheese, .about__cheese`, {rotate: {z: 15} })
sr.reveal(`.home__footer`, {scale: 1, origin: 'bottom' })

sr.reveal(`.new__card:nth-child(1) img`, {rotate: {z: -30}, distance: 0 })
sr.reveal(`.new__card:nth-child(2) img`, {rotate: {z: 15}, distance: 0, delay: 600 })
sr.reveal(`.new__card:nth-child(3) img`, {rotate: {z: -30}, distance: 0, delay: 900 })

sr.reveal(`.favorite__card img`, {interval: 100, rotate: {z: 15}, distance: 0 })

sr.reveal(`.testimonial__card`, {interval: 100})

sr.reveal(`.footer__container`, { scale: 1 })
