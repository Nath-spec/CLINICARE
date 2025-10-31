const modal = document.getElementById('authModal');
const bookBtn = document.getElementById('bookBtn');
const bookNow = document.getElementById('bookNow');
const toRegister = document.getElementById('toRegister');
const toLogin = document.getElementById('toLogin');
const loginForm = document.getElementById('loginForm');
const registerForm = document.getElementById('registerForm');

// IMPORTANT: Gamitin natin ang 'modal' variable dito, hindi 'authModal'
const authModal = modal; 

// ----------------------
// MODAL TOGGLE LOGIC
// ----------------------
bookBtn.onclick = bookNow.onclick = () => authModal.style.display = 'flex';
authModal.onclick = (e) => { 
    if(e.target === authModal) authModal.style.display = 'none'; 
};

toRegister.onclick = () => {
    loginForm.style.display = 'none';
    registerForm.style.display = 'block';
};
toLogin.onclick = () => {
    registerForm.style.display = 'none';
    loginForm.style.display = 'block';
};

// =======================
// 🟢 LOGIN AND REGISTER LOGIC (AJAX to PHP)
// =======================

// ----------------------
// LOGIN FUNCTIONALITY
// ----------------------
loginForm.addEventListener('submit', async function (e) {
    e.preventDefault();

    const formData = new FormData(loginForm);

    try {
        const response = await fetch('login.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();
        
        alert(result.message);

        if (result.success) {
            authModal.style.display = 'none'; // Close modal on success
            
            // TAMA: Redirect based on the role (DAPAT .php FILES)
            if (result.role === "admin") {
                window.location.href = "ADMIN/admin.php"; // UPDATED to .php
            } else if (result.role === "doctor") {
                window.location.href = "DOCTOR/doctor.php";
            } else if (result.role === "patient") {
                window.location.href = "PATIENT/patient.php"; // UPDATED to .php (nasa root)
            }
        }
    } catch (error) {
        console.error('Login Error:', error);
        alert('An error occurred during login. Please try again.');
    }
});


// ----------------------
// REGISTER FUNCTIONALITY
// ----------------------
registerForm.addEventListener('submit', async function (e) {
    e.preventDefault();
    
    const formData = new FormData(registerForm);
    
    try {
        const response = await fetch('register.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();
        
        alert(result.message);

        if (result.success) {
            // Ipakita ang login form pagkatapos mag-register
            registerForm.style.display = 'none';
            loginForm.style.display = 'block';
            registerForm.reset();
        }
    } catch (error) {
        console.error('Registration Error:', error);
        alert('An error occurred during registration. Please try again.');
    }
});