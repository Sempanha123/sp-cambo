import { spawnSync } from 'node:child_process'
import path from 'node:path'
import fs from 'node:fs'

const projectRoot = path.resolve(process.argv[2] || process.cwd())
const frontend = path.join(projectRoot, 'frontend')

if (!fs.existsSync(path.join(frontend, 'package.json'))) {
  throw new Error(`frontend/package.json not found under ${projectRoot}`)
}

function run(title, args) {
  console.log('')
  console.log(`=== ${title} ===`)
  const result = spawnSync('pnpm', args, {
    cwd: frontend,
    stdio: 'inherit',
    shell: true
  })

  if (result.status !== 0) {
    process.exitCode = result.status || 1
    throw new Error(`${title} failed with exit code ${result.status}`)
  }
}

// Start with the files directly addressed by R9. This gives a useful first
// error instead of another 273-test summary.
run('Playground component tests', [
  'exec', 'vitest', 'run', '--project', 'nuxt',
  'tests/component/PlaygroundPage.spec.ts',
  '--reporter=verbose', '--bail=1', '--maxWorkers=1'
])

run('Models component tests', [
  'exec', 'vitest', 'run', '--project', 'nuxt',
  'tests/component/ModelsPage.spec.ts',
  '--reporter=verbose', '--bail=1', '--maxWorkers=1'
])

run('API key details component tests', [
  'exec', 'vitest', 'run', '--project', 'nuxt',
  'tests/component/ApiKeyDetailsPage.spec.ts',
  '--reporter=verbose', '--bail=1', '--maxWorkers=1'
])

run('Admin aliases / API keys / Entitlements regression set', [
  'exec', 'vitest', 'run', '--project', 'nuxt',
  'tests/component/AdminModelAliasesPage.spec.ts',
  'tests/component/ApiKeysPage.spec.ts',
  'tests/component/EntitlementsPage.spec.ts',
  '--reporter=verbose', '--bail=1', '--maxWorkers=1'
])

run('All Nuxt component tests', [
  'exec', 'vitest', 'run', '--project', 'nuxt'
])

run('Frontend unit tests', [
  'exec', 'vitest', 'run', '--project', 'unit'
])

run('Frontend lint', ['run', 'lint'])
run('Frontend typecheck', ['run', 'typecheck'])
run('Frontend production build', ['run', 'build'])

console.log('')
console.log('[PASS] SP Cambo Frontend R9 verification completed.')
