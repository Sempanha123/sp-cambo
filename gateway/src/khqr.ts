import { BakongKHQR, IndividualInfo, khqrData } from "bakong-khqr";

export type KhqrGenerateInput = {
  account_id: string;
  merchant_name: string;
  merchant_city: string;
  currency: "USD" | "KHR";
  amount: string;
  reference: string;
  expires_at_unix_ms: number;
};

type KhqrResult = { status?: { code?: number }; data?: { qr?: string; md5?: string } };

export function generateKhqr(body: KhqrGenerateInput): { qr_payload: string; md5: string } | null {
  const result = new BakongKHQR().generateIndividual(
    new IndividualInfo(body.account_id, body.merchant_name, body.merchant_city, {
      currency: body.currency === "USD" ? khqrData.currency.usd : khqrData.currency.khr,
      amount: Number(body.amount),
      billNumber: body.reference,
      expirationTimestamp: body.expires_at_unix_ms,
    }),
  ) as KhqrResult;
  const payload = result.data?.qr;
  const md5 = result.data?.md5;
  if (result.status?.code !== 0 || typeof payload !== "string" || !/^[a-f0-9]{32}$/i.test(md5 ?? "")) {
    return null;
  }

  return { qr_payload: payload, md5: md5!.toLowerCase() };
}
