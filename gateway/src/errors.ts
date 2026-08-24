import type { FastifyReply } from "fastify";

export class GatewayError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
    readonly headers: Record<string, string> = {},
  ) {
    super(message);
  }
}

export class ControlPlaneError extends GatewayError {}

export function writeError(reply: FastifyReply, error: unknown, anthropic: boolean): void {
  const known = error instanceof GatewayError
    ? error
    : new GatewayError(500, "server_error", "An unexpected server error occurred.");
  for (const [name, value] of Object.entries(known.headers)) reply.header(name, value);
  reply.header("cache-control", "no-store");
  reply.status(known.status).send(anthropic
    ? { type: "error", error: { type: anthropicType(known.code), message: known.message, sp_cambo_code: known.code } }
    : { error: { message: known.message, type: openAiType(known.status), code: known.code } });
}

function anthropicType(code: string): string {
  if (code.includes("rate_limit") || code.includes("concurrency")) return "rate_limit_error";
  if (code.includes("api_key") || code === "account_suspended") return "authentication_error";
  if (code === "server_error" || code.includes("upstream")) return "api_error";
  return "invalid_request_error";
}

function openAiType(status: number): string {
  if (status === 401 || status === 403) return "authentication_error";
  if (status === 429) return "rate_limit_error";
  if (status >= 500) return "api_error";
  return "invalid_request_error";
}
