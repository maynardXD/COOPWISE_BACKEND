// This file sends server-rendered sign-in and registration forms to the backend.
(() => {
    const messageBox = document.getElementById('form-message');

    // Show the form result in the message area.
    function showMessage(message, success) {
        if (!messageBox) return;
        messageBox.textContent = message || '';
        messageBox.className = message ? (success ? 'success-message' : 'alert') : '';
    }

    // Put validation errors beside the matching form fields.
    function renderErrors(errors) {
        if (!errors) return;
        Object.entries(errors).forEach(([name, message]) => {
            const input = document.querySelector(`[name="${CSS.escape(name)}"]`);
            if (!input) {
                showMessage(message, false);
                return;
            }

            input.setAttribute('aria-invalid', 'true');
            let error = input.parentElement.querySelector('.field-error');
            if (!error) {
                error = document.createElement('small');
                error.className = 'field-error';
                input.insertAdjacentElement('afterend', error);
            }
            error.textContent = message;
        });
    }

    // Send the form with fetch and show the backend response.
    async function submitViaLavaApi(form) {
        const button = form.querySelector('[type="submit"]');
        if (button) button.disabled = true;

        try {
            const response = await fetch(form.dataset.apiUrl, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const result = await response.json();

            if (!response.ok) {
                showMessage(result.message || 'Please check your information and try again.', false);
                renderErrors(result.errors);
                return;
            }

            showMessage(result.message || 'Done.', true);
            if (result.redirect) {
                window.location.assign(result.redirect);
            }
        } catch (error) {
            showMessage('The request could not be completed. Please check your connection and try again.', false);
        } finally {
            if (button) button.disabled = false;
        }
    }

    // Send supported forms without reloading the whole page.
    document.querySelectorAll('form[data-api-url]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            submitViaLavaApi(form);
        });
    });

    const password = document.getElementById('password');
    const confirmation = document.getElementById('password_confirmation');
    const matchMessage = document.getElementById('password-match');
    if (password && confirmation && matchMessage) {
        // Tell the user when both password fields do not match.
        const checkMatch = () => {
            matchMessage.textContent = confirmation.value && password.value !== confirmation.value
                ? 'Passwords do not match.'
                : '';
        };
        password.addEventListener('input', checkMatch);
        confirmation.addEventListener('input', checkMatch);
    }
})();
