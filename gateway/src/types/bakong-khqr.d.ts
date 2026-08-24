declare module "bakong-khqr" {
  export const khqrData: {
    currency: { usd: number; khr: number };
  };

  export class IndividualInfo {
    constructor(
      accountId: string,
      merchantName: string,
      merchantCity: string,
      optional?: {
        currency?: number;
        amount?: number;
        billNumber?: string;
        expirationTimestamp?: number;
      },
    );
  }

  export class BakongKHQR {
    generateIndividual(info: IndividualInfo): unknown;
  }
}
