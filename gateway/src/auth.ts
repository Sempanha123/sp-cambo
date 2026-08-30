import type { FastifyRequest } from "fastify";
import { GatewayError } from "./errors.js";

export function customerKey(request: FastifyRequest): string {
  const authorization = request.headers.authorization;
  const anthropic = singleHeader(request.headers["x-api-key"]);
  let bearer: string | null = null;
  if (authorization !== undefined) {
    const match = authorization.match(/^Bearer ([^\s]+)$/i);
    if (!match) throw new GatewayError(401, "invalid_api_key", "The API key is invalid.");
    bearer = match[1]!;
  }
  if (bearer && anthropic && bearer !== anthropic) {
    throw new GatewayError(401, "conflicting_api_keys", "Conflicting API credentials were supplied.");
  }
  const key = bearer ?? anthropic;
  if (!key || !/^sk-(?:spc-)?[a-z0-9]{32,80}$/.test(key)) {
    throw new GatewayError(401, "invalid_api_key", "The API key is invalid.");
  }
  return key;
}

function singleHeader(value: string | string[] | undefined): string | null {
  if (Array.isArray(value)) {
    if (value.length !== 1) throw new GatewayError(401, "conflicting_api_keys", "Conflicting API credentials were supplied.");
    return value[0] ?? null;
  }
  return value ?? null;
}
