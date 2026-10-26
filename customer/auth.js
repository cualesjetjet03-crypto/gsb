// Toggle Password Visibility
function togglePass(id) {
    const field = document.getElementById(id);
    const trigger = field.nextElementSibling;
    if (field.type === "password") {
        field.type = "text";
        trigger.innerText = "Hide";
    } else {
        field.type = "password";
        trigger.innerText = "Show";
    }
}

// Validation Logic
const emailRegex = /^[a-zA-Z0-9._%+-]+@example\.com$/;
const passRegex = /^(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/;

const forms = ['loginForm', 'registerForm'];

forms.forEach(formId => {
    const form = document.getElementById(formId);
    if (!form) return;

    const emailInput = form.querySelector('#email');
    const passInput = form.querySelector('#password');

    // Real-time validation
    emailInput.addEventListener('input', () => validateField(emailInput, emailRegex, 'emailError'));
    passInput.addEventListener('input', () => validateField(passInput, passRegex, 'passError'));

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        
        const isEmailValid = validateField(emailInput, emailRegex, 'emailError');
        const isPassValid = validateField(passInput, passRegex, 'passError');

        if (isEmailValid && isPassValid) {
            alert("Success! Form is valid.");
            // Proceed with login/registration logic
        }
    });
});

function validateField(input, regex, errorId) {
    const errorSpan = document.getElementById(errorId);
    if (regex.test(input.value)) {
        input.parentElement.classList.remove('invalid');
        errorSpan.style.display = 'none';
        return true;
    } else {
        input.parentElement.classList.add('invalid');
        errorSpan.style.display = 'block';
        return false;
    }
}