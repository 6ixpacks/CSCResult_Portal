// ============================================================
// CSCResultPortal - Login Page JavaScript
// File: js/login.js
// Purpose: Handles AJAX form submission, client-side
//          validation, password toggle, and UI feedback
//          for the login page (index.php)
// ============================================================

'use strict';

// ============================================================
// SECTION 1: DOM ELEMENT REFERENCES
// Cache all needed elements on page load for performance.
// ============================================================
const loginForm        = document.getElementById('loginForm');
const emailInput       = document.getElementById('email');
const passwordInput    = document.getElementById('password');
const loginBtn         = document.getElementById('loginBtn');
const loginBtnText     = document.getElementById('loginBtnText');
const loginBtnSpinner  = document.getElementById('loginBtnSpinner');
const loginMessage     = document.getElementById('loginMessage');
const togglePassword   = document.getElementById('togglePassword');
const toggleIcon       = document.getElementById('toggleIcon');
const emailError       = document.getElementById('emailError');
const passwordError    = document.getElementById('passwordError');


// ============================================================
// SECTION 2: UTILITY FUNCTIONS
// ============================================================

/**
 * Displays a styled message in the loginMessage alert box.
 * 
 * @param {string} message  - Text to display
 * @param {string} type     - Bootstrap alert type: 'success', 'danger', 'warning', 'info'
 * @param {string} icon     - Font Awesome icon class e.g. 'fa-circle-check'
 */
function showMessage(message, type, icon) {
    loginMessage.className = `alert alert-${type} login-alert`;
    loginMessage.innerHTML = `<i class="fa-solid ${icon} me-2"></i>${message}`;
    loginMessage.classList.remove('d-none');

    // Smooth scroll to message if user has scrolled down on mobile
    loginMessage.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

/**
 * Hides the loginMessage alert box.
 */
function hideMessage() {
    loginMessage.classList.add('d-none');
    loginMessage.className = 'alert login-alert d-none';
    loginMessage.innerHTML = '';
}

/**
 * Sets the login button into loading/submitting state.
 * Disables button and shows spinner.
 */
function setLoadingState() {
    loginBtn.disabled = true;
    loginBtnText.classList.add('d-none');
    loginBtnSpinner.classList.remove('d-none');
}

/**
 * Resets the login button back to its default state.
 */
function resetLoadingState() {
    loginBtn.disabled = false;
    loginBtnText.classList.remove('d-none');
    loginBtnSpinner.classList.add('d-none');
}

/**
 * Marks an input field as invalid and shows an error message.
 * 
 * @param {HTMLElement} input       - The input element
 * @param {HTMLElement} errorEl     - The error message element
 * @param {string}      message     - Error message text
 */
function setInputError(input, errorEl, message) {
    input.classList.add('is-invalid');
    input.classList.remove('is-valid');
    if (errorEl) {
        errorEl.textContent = message;
        errorEl.style.display = 'block';
    }
}

/**
 * Marks an input field as valid and clears error message.
 * 
 * @param {HTMLElement} input   - The input element
 * @param {HTMLElement} errorEl - The error message element
 */
function clearInputError(input, errorEl) {
    input.classList.remove('is-invalid');
    input.classList.add('is-valid');
    if (errorEl) {
        errorEl.textContent = '';
        errorEl.style.display = 'none';
    }
}

/**
 * Clears all validation states from all inputs.
 */
function clearAllErrors() {
    [emailInput, passwordInput].forEach(input => {
        input.classList.remove('is-invalid', 'is-valid');
    });
    if (emailError)    emailError.style.display    = 'none';
    if (passwordError) passwordError.style.display = 'none';
}


// ============================================================
// SECTION 3: CLIENT-SIDE VALIDATION
// Validates form fields before sending to server.
// This reduces unnecessary server requests.
// ============================================================

/**
 * Validates the email input field.
 * @returns {boolean} true if valid
 */
function validateEmail() {
    const value = emailInput.value.trim();

    if (!value) {
        setInputError(emailInput, emailError, 'Please enter your email address.');
        return false;
    }

    // Simple email regex check
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(value)) {
        setInputError(emailInput, emailError, 'Please enter a valid email address.');
        return false;
    }

    clearInputError(emailInput, emailError);
    return true;
}

/**
 * Validates the password input field.
 * @returns {boolean} true if valid
 */
function validatePassword() {
    const value = passwordInput.value;

    if (!value) {
        setInputError(passwordInput, passwordError, 'Please enter your password.');
        return false;
    }

    if (value.length < 6) {
        setInputError(passwordInput, passwordError, 'Password must be at least 6 characters.');
        return false;
    }

    clearInputError(passwordInput, passwordError);
    return true;
}


// ============================================================
// SECTION 4: REAL-TIME VALIDATION ON INPUT
// Validates each field as user types after first interaction.
// ============================================================
let emailTouched    = false;
let passwordTouched = false;

emailInput.addEventListener('blur', () => {
    emailTouched = true;
    validateEmail();
});

emailInput.addEventListener('input', () => {
    if (emailTouched) validateEmail();
});

passwordInput.addEventListener('blur', () => {
    passwordTouched = true;
    validatePassword();
});

passwordInput.addEventListener('input', () => {
    if (passwordTouched) validatePassword();
    // Clear server error message when user starts typing again
    hideMessage();
});


// ============================================================
// SECTION 5: PASSWORD VISIBILITY TOGGLE
// ============================================================
if (togglePassword) {
    togglePassword.addEventListener('click', () => {
        const isPassword = passwordInput.type === 'password';

        // Toggle input type
        passwordInput.type = isPassword ? 'text' : 'password';

        // Toggle icon
        toggleIcon.className = isPassword
            ? 'fa-solid fa-eye-slash'
            : 'fa-solid fa-eye';

        // Update accessibility attribute
        togglePassword.setAttribute(
            'aria-label',
            isPassword ? 'Hide password' : 'Show password'
        );

        // Refocus password field
        passwordInput.focus();
    });
}


// ============================================================
// SECTION 6: FORM SUBMISSION (AJAX)
// Sends login credentials to auth/login.php via fetch().
// Handles success redirect and error display.
// ============================================================
if (loginForm) {
    loginForm.addEventListener('submit', async (e) => {
        e.preventDefault(); // Prevent default page reload

        // Clear previous messages
        hideMessage();
        clearAllErrors();

        // Run validation
        const emailValid    = validateEmail();
        const passwordValid = validatePassword();

        if (!emailValid || !passwordValid) {
            showMessage(
                'Please correct the errors above before signing in.',
                'warning',
                'fa-triangle-exclamation'
            );
            return;
        }

        // Set loading state on button
        setLoadingState();

        // Prepare request payload
        const payload = {
            email:    emailInput.value.trim(),
            password: passwordInput.value
        };

        try {
            const response = await fetch('auth/login.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(payload),
                credentials: 'same-origin'
            });

            // Handle non-OK HTTP status
            if (!response.ok) {
                throw new Error(`Server error: ${response.status} ${response.statusText}`);
            }

            // Parse JSON response from auth/login.php
            const data = await response.json();

            if (data.success) {
                // Show success message
                showMessage(
                    data.message || 'Login successful! Redirecting...',
                    'success',
                    'fa-circle-check'
                );

                // Small delay then redirect to dashboard
                setTimeout(() => {
                    window.location.href = data.redirect_url;
                }, 900);

            } else {
                // Show error from server
                showMessage(
                    data.message || 'Invalid credentials. Please try again.',
                    'danger',
                    'fa-circle-exclamation'
                );

                // Shake the form card for visual feedback
                const card = document.querySelector('.login-card');
                if (card) {
                    card.style.animation = 'none';
                    card.offsetHeight; // Force reflow
                    card.style.animation = 'shake 0.4s ease';
                }

                resetLoadingState();

                // Clear password field on failed login (security)
                passwordInput.value = '';
                passwordTouched = false;
                passwordInput.classList.remove('is-invalid', 'is-valid');
            }

        } catch (error) {
            // Network error or server completely unreachable
            console.error('Login request failed:', error);
            showMessage(
                'Unable to connect to the server. Please check your connection and try again.',
                'danger',
                'fa-wifi'
            );
            resetLoadingState();
        }
    });
}


// ============================================================
// SECTION 7: AUTO-DISMISS FLASH MESSAGES
// Bootstrap alerts from PHP (logout, session expired)
// fade out automatically after 5 seconds.
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
    const phpAlerts = document.querySelectorAll('.login-alert.alert-success, .login-alert.alert-warning');

    phpAlerts.forEach(alert => {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            if (bsAlert) {
                bsAlert.close();
            }
        }, 5000);
    });
});


// ============================================================
// SECTION 8: SHAKE ANIMATION KEYFRAMES
// Injected via JavaScript so no separate CSS block needed.
// Triggers when login fails.
// ============================================================
(function injectShakeAnimation() {
    const style = document.createElement('style');
    style.textContent = `
        @keyframes shake {
            0%   { transform: translateX(0); }
            20%  { transform: translateX(-7px); }
            40%  { transform: translateX(7px); }
            60%  { transform: translateX(-5px); }
            80%  { transform: translateX(5px); }
            100% { transform: translateX(0); }
        }
    `;
    document.head.appendChild(style);
})();


// ============================================================
// SECTION 9: ENTER KEY SUPPORT
// Pressing Enter inside any input submits the form.
// Already handled natively by the form element but we
// explicitly confirm here for cross-browser reliability.
// ============================================================
[emailInput, passwordInput].forEach(input => {
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            loginForm.dispatchEvent(new Event('submit', { cancelable: true }));
        }
    });
});