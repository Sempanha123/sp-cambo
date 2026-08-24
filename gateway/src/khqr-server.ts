// Load gateway/.env automatically for local development.
try { process.loadEnvFile?.(".env"); } catch { /* deployment may inject env without a file */ }

import { createServer, type IncomingMessage, type ServerResponse } from "node:http";
import { timingSafeEqual } from "node:crypto";
import { generateKhqr, type KhqrGenerateInput } from "./khqr.js";

const host = process.env.KHQR_HOST ?? "127.0.0.1";
const port = positiveInteger(process.env.KHQR_PORT ?? "3011", "KHQR_PORT");
const secret = process.env.BAKONG_KHQR_GENERATOR_SECRET ?? "";
const maxBodyBytes = positiveInteger(process.env.KHQR_MAX_BODY_BYTES ?? "8192", "KHQR_MAX_BODY_BYTES");

if (secret.length < 32) {
  throw new Error("BAKONG_KHQR_GENERATOR_SECRET must contain at least 32 characters.");
}

const server = createServer(async (request, response) => {
  try {
    setSafeHeaders(response);
    if (request.method === "GET" && request.url === "/health") {
      return json(response, 200, { data: { status: "ok" } });
    }
    if (request.method !== "POST" || request.url !== "/v1/khqr/generate") {
      return json(response, 404, { message: "Not found.", code: "not_found" });
    }
    if (!authorized(request.headers.authorization, secret)) {
      return json(response, 401, { message: "Authentication failed.", code: "unauthenticated" });
    }

    const body = validate(JSON.parse(await readBody(request, maxBodyBytes)) as unknown);
    const result = generateKhqr(body);
    if (result === null) {
      return json(response, 422, { message: "KHQR generation failed.", code: "khqr_generation_failed" });
    }

    return json(response, 200, { data: result });
  } catch (error) {
    const status = error instanceof RequestError ? error.status : 500;
    const code = error instanceof RequestError ? error.code : "server_error";
    const message = status >= 500 ? "An unexpected server error occurred." : (error as Error).message;
    return json(response, status, { message, code });
  }
});

server.listen(port, host, () => {
  process.stdout.write(`KHQR generator listening on ${host}:${port}\n`);
});

class RequestError extends Error {
  constructor(readonly status: number, readonly code: string, message: string) {
    super(message);
  }
}

export function validate(value: unknown): KhqrGenerateInput {
  if (!isRecord(value)) throw new RequestError(422, "validation_failed", "The request must be an object.");
  const allowed = new Set(["account_id", "merchant_name", "merchant_city", "currency", "amount", "reference", "expires_at_unix_ms"]);
  if (Object.keys(value).some((key) => !allowed.has(key))) throw new RequestError(422, "validation_failed", "Unknown request field.");
  const accountId = requiredString(value.account_id, 100, "account_id");
  const merchantName = requiredString(value.merchant_name, 25, "merchant_name");
  const merchantCity = requiredString(value.merchant_city, 15, "merchant_city");
  const reference = requiredString(value.reference, 25, "reference");
  if (value.currency !== "USD" && value.currency !== "KHR") throw new RequestError(422, "validation_failed", "currency must be USD or KHR.");
  if (typeof value.amount !== "string" || !/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/.test(value.amount)) throw new RequestError(422, "validation_failed", "amount is invalid.");
  const amount = Number(value.amount);
  if (!Number.isFinite(amount) || amount <= 0 || amount > 999_999_999) throw new RequestError(422, "validation_failed", "amount is outside the supported range.");
  const expiresAt = value.expires_at_unix_ms;
  if (typeof expiresAt !== "number" || !Number.isSafeInteger(expiresAt) || expiresAt <= Date.now() || expiresAt > Date.now() + 86_400_000) {
    throw new RequestError(422, "validation_failed", "expires_at_unix_ms is invalid.");
  }
  return { account_id: accountId, merchant_name: merchantName, merchant_city: merchantCity, currency: value.currency, amount: value.amount, reference, expires_at_unix_ms: expiresAt };
}

export function authorized(header: string | undefined, expected: string): boolean {
  if (!header?.startsWith("Bearer ")) return false;
  const provided = Buffer.from(header.slice(7));
  const wanted = Buffer.from(expected);
  return provided.length === wanted.length && timingSafeEqual(provided, wanted);
}

async function readBody(request: IncomingMessage, limit: number): Promise<string> {
  const chunks: Buffer[] = [];
  let bytes = 0;
  for await (const chunk of request) {
    const buffer = Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk);
    bytes += buffer.length;
    if (bytes > limit) throw new RequestError(413, "request_too_large", "The request is too large.");
    chunks.push(buffer);
  }
  if (chunks.length === 0) throw new RequestError(422, "validation_failed", "The request body is required.");
  return Buffer.concat(chunks).toString("utf8");
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function requiredString(value: unknown, max: number, field: string): string {
  if (typeof value !== "string" || value.trim() === "" || value.length > max) throw new RequestError(422, "validation_failed", `${field} is invalid.`);
  return value.trim();
}

function positiveInteger(value: string, name: string): number {
  const parsed = Number(value);
  if (!Number.isSafeInteger(parsed) || parsed < 1 || parsed > 65_535) throw new Error(`${name} must be a positive integer.`);
  return parsed;
}

function setSafeHeaders(response: ServerResponse): void {
  response.setHeader("cache-control", "no-store");
  response.setHeader("content-security-policy", "default-src 'none'");
  response.setHeader("x-content-type-options", "nosniff");
}

function json(response: ServerResponse, status: number, body: unknown): void {
  if (response.headersSent) return;
  response.statusCode = status;
  response.setHeader("content-type", "application/json; charset=utf-8");
  response.end(JSON.stringify(body));
}
