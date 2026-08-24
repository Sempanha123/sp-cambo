const userAgent = process.env.npm_config_user_agent ?? "";
if (!userAgent.startsWith("pnpm/")) {
  console.error("SP Cambo gateway dependencies must be installed with pnpm.");
  process.exit(1);
}
