import { execSync } from 'child_process';
import { existsSync, writeFileSync } from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

export default function globalSetup() {
  const dbPath = path.resolve(__dirname, '../database/e2e.sqlite');

  if (!existsSync(dbPath)) {
    writeFileSync(dbPath, '');
  }

  execSync('php artisan migrate:fresh --seed --env=e2e --force', {
    cwd: path.resolve(__dirname, '..'),
    stdio: 'inherit',
  });
}
