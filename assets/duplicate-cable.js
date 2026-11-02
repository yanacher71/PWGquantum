'use strict';

// Increment as decimal text: preserve zero padding without integer overflow.
function nextCableSerial(serial) {
  const match = /^([A-Z0-9_-]*?)([0-9]+)$/.exec(serial);
  if (!match) return '';
  const digits = match[2].split('');
  let carry = 1;
  for (let i = digits.length - 1; i >= 0 && carry; i--) {
    if (digits[i] === '9') digits[i] = '0';
    else { digits[i] = String(Number(digits[i]) + 1); carry = 0; }
  }
  const result = match[1] + (carry ? '1' : '') + digits.join('');
  return result.length <= 32 ? result : '';
}

if (typeof module !== 'undefined' && module.exports) {
  module.exports = { nextCableSerial };
} else {
  const button = document.getElementById('duplicate-previous');
  const form = document.getElementById('cable-form');
  button.addEventListener('click', () => {
    const previous = JSON.parse(button.dataset.cable);
    if (!previous) return;
    for (const [name, value] of Object.entries(previous)) {
      const field = form.elements.namedItem(name);
      if (field) field.value = value === null ? '' : value;
    }
    const serial = nextCableSerial(previous.serial_number);
    form.elements.namedItem('serial_number').value = serial;
    form.elements.namedItem('duplicate_new').value = '1';
    document.getElementById('duplicate-notice').textContent =
      'Copied ' + previous.serial_number + '. ' +
      (serial ? 'Suggested serial: ' + serial + '. ' : 'Enter a new serial number. ') +
      'Review the fields and click Save Cable to create it.';
    const heading = form.closest('section').querySelector('h2');
    heading.textContent = 'New Cable (duplicate)';
    form.elements.namedItem('serial_number').focus();
  });
}
