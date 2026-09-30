// LiveAuction — index.js (Login)
// Validación básica en el navegador antes de enviar el formulario al servidor.

document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('login-form');
  const errorSpan = document.getElementById('form-error');
  const emailInput = document.getElementById('email');
  const passwordInput = document.getElementById('password');
  const rolSelect = document.getElementById('rol');
  const submitBtn = form.querySelector('.btn-ingresar');

  function mostrarError(mensaje) {
    errorSpan.textContent = mensaje;
  }

  function limpiarError() {
    errorSpan.textContent = '';
  }

  [emailInput, passwordInput, rolSelect].forEach(function (campo) {
    campo.addEventListener('input', limpiarError);
  });

  form.addEventListener('submit', function (event) {
    const email = emailInput.value.trim();
    const password = passwordInput.value;
    const rol = rolSelect.value;

    const emailValido = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);

    if (email === '' || password === '' || rol === '') {
      event.preventDefault();
      mostrarError('Completá todos los campos.');
      return;
    }

    if (!emailValido) {
      event.preventDefault();
      mostrarError('Ingresá un email válido.');
      return;
    }

    if (password.length < 6) {
      event.preventDefault();
      mostrarError('La contraseña debe tener al menos 6 caracteres.');
      return;
    }

    // Todo OK: se deshabilita el botón para evitar doble envío
    submitBtn.disabled = true;
    submitBtn.textContent = 'Ingresando...';
  });
});