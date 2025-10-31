// FILE: ADMIN/admin.js (FINAL: Single Profile Dropdown, Modal Logic, Optimized Dropdowns)

document.addEventListener('DOMContentLoaded', () => {
    // ----------------------
    // Global Elements & Setup
    // ----------------------
    const sidebar = document.getElementById('sidebar');
    const sections = ['dashboard', 'patients', 'doctors', 'appointments', 'chat']; // 'chat' is present in your file structure
    
    // Profile Dropdown elements (We only use one combined dropdown for Admin actions)
    const profileMenu = document.getElementById('profileMenu'); // The button/trigger
    const profileDropdown = document.getElementById('profileDropdown'); // The actual menu

    // Doctor Modal Elements
    const addDoctorModal = document.getElementById('addDoctorModal');
    const closeDoctorModal = document.getElementById('closeDoctorModal');
    const addDoctorForm = document.getElementById('addDoctorForm');
    const submitDoctorFormBtn = document.getElementById('submitDoctorFormBtn'); 
    
    // ------------------------------------
    // 1. Sidebar navigation logic (Optimized)
    // ------------------------------------

    function switchSection(targetSectionId) {
        // Hide all sections and remove active class
        document.querySelectorAll('.card[id$="Section"]').forEach(section => {
            section.style.display = 'none';
        });
        document.querySelectorAll('.side-item').forEach(i => i.classList.remove('active'));

        // Show target section
        const targetSection = document.getElementById(targetSectionId + 'Section');
        if (targetSection) {
            targetSection.style.display = 'block';
        }

        // Add active class
        const activeItem = document.querySelector(`.side-item[data-section="${targetSectionId}"]`);
        if (activeItem) {
            activeItem.classList.add('active');
        }
    }

    // Event listener for sidebar clicks
    sidebar.addEventListener('click', (e) => {
        const item = e.target.closest('.side-item');
        // Make sure it's a <div> item with data-section, not an <a> link
        if (item && item.tagName === 'DIV' && item.hasAttribute('data-section')) {
            const targetSection = item.getAttribute('data-section');
            switchSection(targetSection);
        }
    });

    // Handle URL parameter on load (for links from analytics.php)
    const urlParams = new URLSearchParams(window.location.search);
    const sectionParam = urlParams.get('section');
    
    // Initial load: Default to 'dashboard' or use URL param
    switchSection(sectionParam || 'dashboard');

    // ------------------------------------
    // 2. Profile Dropdown Toggle (Optimized)
    // ------------------------------------
    if (profileMenu && profileDropdown) {
        profileMenu.addEventListener('click', (e) => {
            e.stopPropagation();
            profileDropdown.classList.toggle('show');
            
            // Toggle caret rotation
            const caret = profileMenu.querySelector('.caret');
            if (caret) {
                caret.style.transform = profileDropdown.classList.contains('show') ? 'rotate(180deg)' : 'rotate(0deg)';
            }
            
            // Close any table action dropdowns
            document.querySelectorAll('.dropdown-menu.show').forEach(menu => menu.classList.remove('show'));
        });
    }

    // ------------------------------------
    // 3. General Dropdown Toggle & Close Logic
    // ------------------------------------
    
    // Function to toggle the action dropdowns on tables
    window.toggleDropdown = function(button) {
        const parentDropdown = button.closest('.action-dropdown');
        const dropdownMenu = parentDropdown.querySelector('.dropdown-menu');
        
        // Close all other open dropdowns first
        document.querySelectorAll('.dropdown-menu').forEach(menu => {
            if (menu !== dropdownMenu) {
                menu.classList.remove('show');
            }
        });
        
        // Close profile dropdown
        profileDropdown?.classList.remove('show');
        profileMenu.querySelector('.caret').style.transform = 'rotate(0deg)';

        // Toggle the target dropdown
        dropdownMenu.classList.toggle('show');
    };

    // Global click handler to close all dropdowns when clicking outside
    document.addEventListener('click', (e) => {
        // Close table action dropdowns
        if (!e.target.closest('.action-dropdown')) {
            document.querySelectorAll('.dropdown-menu').forEach(menu => menu.classList.remove('show'));
        }
        
        // Close profile dropdown
        if (!e.target.closest('.profile')) {
            profileDropdown?.classList.remove('show');
            profileMenu.querySelector('.caret').style.transform = 'rotate(0deg)';
        }
    });
    
    // ------------------------------------
    // 4. Doctor Management - Modal & Actions
    // ------------------------------------
    
    // Modal Display Logic
    // NOTE: Ang "Add Doctor" button ay nasa loob ng Profile Dropdown na ngayon.
    // Kaya dapat mo i-trigger ang modal mula sa 'click' event sa link na iyon
    
    // Find the actual link inside the profile dropdown
    const addDoctorLink = document.querySelector('#profileDropdown a[href="add_doctor.php"]');

    if (addDoctorLink && addDoctorModal && closeDoctorModal) {
        addDoctorLink.addEventListener('click', (e) => {
            e.preventDefault();
            profileDropdown.classList.remove('show'); // Close profile dropdown
            addDoctorModal.style.display = 'flex'; // Open modal
        });

        closeDoctorModal.addEventListener('click', () => {
            addDoctorModal.style.display = 'none';
            addDoctorForm.reset();
        });

        addDoctorModal.addEventListener('click', (e) => {
            if (e.target === addDoctorModal) {
                addDoctorModal.style.display = 'none';
                addDoctorForm.reset();
            }
        });
    }
    
    // SIMPLIFIED FORM SUBMISSION (HTML only check)
    if (submitDoctorFormBtn) {
        submitDoctorFormBtn.addEventListener('click', function (e) {
            e.preventDefault(); // Prevent default form submission for AJAX
            
            if (!addDoctorForm.checkValidity()) {
                addDoctorForm.reportValidity();
                return;
            }

            const fullname = document.getElementById('docFullname').value;
            const specialty = document.getElementById('docSpecialty').value;

            alert(`HTML Test Success!\nDoctor: ${fullname}\nSpecialty: ${specialty}\n\nModal will now close. Ready for PHP/AJAX integration!`);
            
            // TODO: AJAX call to add_doctor.php would go here
            
            addDoctorModal.style.display = 'none';
            addDoctorForm.reset();
        });
    }

    // Doctor Action Functions (Edit/Remove) - Placeholder/Simulation
    window.editDoctor = function(doctorId) {
        console.log('Editing Doctor ID:', doctorId);
        alert(`Simulating Edit for Doctor ID: ${doctorId}. (Requires Edit Doctor Modal/API)`);
    };

    window.removeDoctor = function(doctorId) {
        if (confirm(`Are you sure you want to remove Doctor ID: ${doctorId}? This action is permanent.`)) {
            console.log('Removing Doctor ID:', doctorId);
            alert(`Simulating Removal for Doctor ID: ${doctorId}. (Requires AJAX/PHP delete script)`);
            
            const rowToRemove = document.querySelector(`#doctorsTable tr[data-id="${doctorId}"]`);
            if(rowToRemove) {
                rowToRemove.remove();
            }
        }
    };
    
    // ------------------------------------
    // 5. Placeholder Functions (From Admin.php)
    // ------------------------------------
    window.viewPatient = function(id) {
        alert(`Viewing records for Patient ID: ${id}. (Needs dedicated backend page/modal)`);
    }
    window.removeUser = function(id, role) {
        if (confirm(`Are you sure you want to remove the ${role} with ID ${id}? This action cannot be undone.`)) {
            // TODO: Implement AJAX call to delete_user.php
            alert(`User ${id} deletion initiated (Simulated).`);
        }
    }
    window.updateAppointmentStatus = function(id, status) {
        // TODO: Implement AJAX call to update_appointment.php
        alert(`Appointment ${id} status changed to ${status} (Simulated).`);
    }

    // ------------------------------------
    // 6. Chart Initialization (REMOVED)
    // ------------------------------------
    // NOTE: Ang Chart initialization (lineChart, pieChart, barChart) ay inalis 
    // dito dahil dapat ay nasa hiwalay na 'analytics.js' ito o 
    // sa `<script>` block ng 'analytics.php' mo. 
    // Kung hindi mo inalis ang charts sa admin.php, ibalik mo lang ang code na iyon.
    
});