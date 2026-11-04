const { spawnSync } = require('node:child_process');
const { join } = require('node:path');
for (const TZ of ['America/Vancouver', 'UTC', 'Asia/Tokyo']) {
  for (const file of ['calendar-regressions.cjs', 'calendar-lanes.cjs', 'calendar-pagination.cjs', 'calendar-range.cjs', 'calendar-results.cjs', 'calendar-mobile.cjs', 'calendar-posters.cjs']) {
    const result = spawnSync(process.execPath, [join(__dirname, file), ...process.argv.slice(2)], { env: { ...process.env, TZ }, stdio: 'inherit' });
    if (result.error) throw result.error;
    if (result.status !== 0) process.exit(result.status || 1);
  }
}
