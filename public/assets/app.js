document.addEventListener('DOMContentLoaded', function () {
  const codeInput = document.querySelector('input[name="code"]');
  if (codeInput) {
    codeInput.focus();
    codeInput.addEventListener('input', function () {
      this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
    });
  }
});
