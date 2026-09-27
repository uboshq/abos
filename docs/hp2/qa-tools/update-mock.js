// A stand-in for GET /api/v1/app/version, for the self-update bad rounds.
//
// The live server's answer cannot be changed from here, and the rounds that
// matter most are the ones where the answer is wrong: a hash that does not
// match, a size that does not match, a file that is broken. So this sits on
// the emulator's host, answers /app/version itself, and passes every other
// request straight through to live, so the app under test signs in and draws
// real figures exactly as it would against the real server.
//
//   node update-mock.js <round> [port]
//
// rounds:
//   good        the real file, the real hash, the real size
//   wrong-sha   the real file, a hash that is not its own
//   wrong-size  the real file, a size one byte too large
//   broken      a truncated file (APK_BROKEN_URL), the real hash and size
//
// The APK address is always https: the app refuses anything else.

const http = require('http');
const https = require('https');

const LIVE = 'https://erp.adi.com.bd';
const APK_URL = process.env.APK_URL || `${LIVE}/download/abos-latest.apk`;
const APK_BROKEN_URL = process.env.APK_BROKEN_URL || `${LIVE}/download/abos-broken-test.apk`;
const REAL_SHA = process.env.APK_SHA256 || '999e5fbac8e4fe80e1df342ba1cd56b1ae39bc05d34b3135e59183dfb822d348';
const REAL_SIZE = parseInt(process.env.APK_SIZE || '56241077', 10);

const round = process.argv[2] || 'good';
const port = parseInt(process.argv[3] || '8099', 10);

const base = {
  versionCode: 5,
  versionName: '0.4.0',
  minimumCode: 4,
  note: { bn: `পরীক্ষা: ${round}`, en: `test round: ${round}` },
};

const answers = {
  good: { ...base, url: APK_URL, apkSha256: REAL_SHA, sizeBytes: REAL_SIZE },
  'wrong-sha': { ...base, url: APK_URL, apkSha256: 'f'.repeat(64), sizeBytes: REAL_SIZE },
  'wrong-size': { ...base, url: APK_URL, apkSha256: REAL_SHA, sizeBytes: REAL_SIZE + 1 },
  broken: { ...base, url: APK_BROKEN_URL, apkSha256: REAL_SHA, sizeBytes: REAL_SIZE },
};

if (!answers[round]) {
  console.error(`unknown round "${round}"; one of: ${Object.keys(answers).join(', ')}`);
  process.exit(2);
}

const log = (line) => console.log(`${new Date().toISOString().slice(11, 19)} ${line}`);

http
  .createServer((req, res) => {
    const path = req.url.split('?')[0];

    if (req.method === 'GET' && path === '/api/v1/app/version') {
      const body = JSON.stringify(answers[round]);
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(body);
      log(`ANSWERED  ${req.method} ${req.url}  round=${round}`);
      return;
    }

    // Everything else is live's to answer.
    const headers = { ...req.headers, host: 'erp.adi.com.bd' };
    const upstream = https.request(
      LIVE + req.url,
      { method: req.method, headers },
      (up) => {
        res.writeHead(up.statusCode, up.headers);
        up.pipe(res);
        log(`PASSED    ${req.method} ${path}  ${up.statusCode}`);
      },
    );
    upstream.on('error', (error) => {
      res.writeHead(502, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ message: `mock could not reach live: ${error.message}` }));
      log(`FAILED    ${req.method} ${path}  ${error.message}`);
    });
    req.pipe(upstream);
  })
  .listen(port, '0.0.0.0', () => log(`update mock, round "${round}", on :${port}, passing the rest to ${LIVE}`));
