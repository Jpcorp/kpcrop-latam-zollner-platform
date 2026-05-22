import jwt from 'jsonwebtoken';
import { config } from '../config.js';

export interface LicensePayload {
  tenantId: string;
  plan: string;
  features: string[];
  maxStores: number;
}

export function signLicenseJwt(payload: LicensePayload): string {
  return jwt.sign(payload, config.JWT_SECRET, { expiresIn: '5m' });
}

export function verifyLicenseJwt(token: string): LicensePayload {
  return jwt.verify(token, config.JWT_SECRET) as LicensePayload;
}
