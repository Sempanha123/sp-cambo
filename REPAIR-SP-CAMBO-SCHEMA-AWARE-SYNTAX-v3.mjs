import fs from "node:fs";
import path from "node:path";
import { spawnSync } from "node:child_process";

const root = path.resolve(process.argv[2] ?? ".");
const runChecks = process.argv.includes("--run-checks");
const toolPath = path.join(root, "gateway", "src", "tool-integrity.ts");

function fail(message) {
  throw new Error(`[SP Cambo schema-aware syntax repair] ${message}`);
}

if (!fs.existsSync(toolPath)) {
  fail(`Missing ${toolPath}`);
}

const raw = fs.readFileSync(toolPath, "utf8");
const crlf = raw.includes("\r\n");
let text = raw.replace(/\r\n/g, "\n");

const goodLine =
  '    if (!name || name.length > 128 || name.includes("\\r") || name.includes("\\n") || name.includes("\\0")) continue;';

if (text.includes(goodLine)) {
  console.log("Syntax repair already appears to be applied.");
} else {
  const prefix = '    if (!name || name.length > 128 || /[';
  const suffix = ']/u.test(name)) continue;';

  const start = text.indexOf(prefix);

  if (start < 0) {
    fail("Could not find the broken tool-name validation line. No file written.");
  }

  const endStart = text.indexOf(suffix, start);

  if (endStart < 0) {
    fail("Found the start of the broken validation line but not its end. No file written.");
  }

  const end = endStart + suffix.length;

  text = text.slice(0, start) + goodLine + text.slice(end);

  if (!text.includes(goodLine)) {
    fail("Post-repair validation failed. No file written.");
  }

  if (text.includes(prefix)) {
    fail("Broken validation prefix is still present. No file written.");
  }

  if (fs.existsSync(path.join(root, ".git"))) {
    const backupDir = path.join(root, ".git", "sp-cambo-fix-backups");
    fs.mkdirSync(backupDir, { recursive: true });
    const stamp = new Date().toISOString().replace(/[:.]/g, "-");
    fs.writeFileSync(
      path.join(backupDir, `tool-integrity.ts.before-schema-syntax-repair.${stamp}`),
      raw,
      "utf8",
    );
  }

  fs.writeFileSync(
    toolPath,
    crlf ? text.replace(/\n/g, "\r\n") : text,
    "utf8",
  );

  console.log("Fixed gateway/src/tool-integrity.ts tool-name validation syntax.");
}

if (runChecks) {
  const gatewayDir = path.join(root, "gateway");

  for (const [cmd, args] of [
    ["pnpm", ["test"]],
    ["pnpm", ["typecheck"]],
    ["pnpm", ["build"]],
  ]) {
    console.log(`> ${cmd} ${args.join(" ")}`);
    const result = spawnSync(cmd, args, {
      cwd: gatewayDir,
      stdio: "inherit",
      shell: process.platform === "win32",
    });

    if (result.status !== 0) {
      fail(`${cmd} ${args.join(" ")} failed.`);
    }
  }

  console.log("");
  console.log("ALL GATEWAY CHECKS PASSED.");
} else {
  console.log("");
  console.log("Next:");
  console.log("  cd gateway");
  console.log("  pnpm test");
  console.log("  pnpm typecheck");
  console.log("  pnpm build");
}
