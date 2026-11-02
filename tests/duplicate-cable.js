'use strict';
const assert = require('node:assert/strict');
const { nextCableSerial } = require('../assets/duplicate-cable.js');
for (const [input, expected] of [
  ['PWG000009', 'PWG000010'], ['PWG-0099', 'PWG-0100'], ['PWG999', 'PWG1000'],
  ['009', '010'], ['PWG-ABC', ''], ['PWG9-X', ''],
  ['9'.repeat(32), ''], ['A' + '0'.repeat(30) + '9', 'A' + '0'.repeat(29) + '10']
]) assert.equal(nextCableSerial(input), expected, input);
console.log('Cable serial increment tests passed.');
